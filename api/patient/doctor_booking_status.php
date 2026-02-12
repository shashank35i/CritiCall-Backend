<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ensure IST comparisons (safe even if server timezone differs slightly)
try { $pdo->exec("SET time_zone = '+05:30'"); } catch (Throwable $e) {}

function int_from_any($v): int {
  if (is_int($v)) return $v;
  if (is_float($v)) return (int)$v;
  if (is_string($v)) {
    $t = trim($v);
    if ($t === "") return 0;
    if (preg_match('/^\d+$/', $t)) return (int)$t;
  }
  if (is_numeric($v)) return (int)$v;
  return 0;
}

function read_doctor_id(array $src): int {
  $keys = ["doctorId","doctor_id","doctor_id_str","doctorIdLong","doctor_id_long","user_id","id"];
  foreach ($keys as $k) {
    if (array_key_exists($k, $src)) {
      $n = int_from_any($src[$k]);
      if ($n > 0) return $n;
    }
  }
  return 0;
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);

// accept GET or POST
$method = $_SERVER["REQUEST_METHOD"] ?? "";
if ($method === "GET") {
  $src = $_GET;
} else if ($method === "POST") {
  $src = read_json();
  if (!is_array($src)) $src = [];
} else {
  json_response(405, ["ok"=>false, "error"=>"GET/POST only"]);
}

$doctorId = read_doctor_id($src);
if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Missing doctorId"]);

// ✅ IMPORTANT: do NOT use DATE(scheduled_at)=today.
// ✅ Block only if still ACTIVE by time window.
$GRACE_MIN = 15;

$st = $pdo->prepare("
  SELECT
    a.id,
    a.public_code,
    a.scheduled_at,
    a.duration_min,
    a.consult_type,
    a.status,
    a.fee_amount
  FROM appointments a
  WHERE a.patient_id = ?
    AND a.doctor_id = ?
    AND UPPER(COALESCE(a.status,'')) IN ('BOOKED','CONFIRMED','UPCOMING','IN_PROGRESS')
    AND NOW() < DATE_ADD(
      a.scheduled_at,
      INTERVAL (COALESCE(NULLIF(a.duration_min,0),30) + ?) MINUTE
    )
  ORDER BY a.scheduled_at DESC
  LIMIT 1
");
$st->execute([$uid, $doctorId, $GRACE_MIN]);
$r = $st->fetch(PDO::FETCH_ASSOC);

if (!$r) {
  json_response(200, ["ok"=>true, "data"=>[
    "hasActiveBooking"=>false,
    "appointmentId"=>"",
    "public_code"=>""
  ]]);
}

json_response(200, ["ok"=>true, "data"=>[
  "hasActiveBooking"=>true,
  "appointmentId"=>(string)($r["id"] ?? ""),
  "public_code"=>(string)($r["public_code"] ?? ""),
  "scheduled_at"=>(string)($r["scheduled_at"] ?? ""),
  "consult_type"=>(string)($r["consult_type"] ?? ""),
  "status"=>(string)($r["status"] ?? ""),
  "fee_amount"=>(int)($r["fee_amount"] ?? 0)
]]);
