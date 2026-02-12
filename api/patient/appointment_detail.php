<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

date_default_timezone_set("Asia/Kolkata");

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  if ($ip === "127.0.0.1" || $ip === "::1") return true;
  if (strpos($ip, "10.") === 0 || strpos($ip, "192.168.") === 0 || strpos($ip, "172.16.") === 0) return true;
  return false;
}
function pick_str($v): string {
  if (is_string($v)) return trim($v);
  if (is_int($v) || is_float($v)) return (string)$v;
  return "";
}
function read_appt_key(array $src): string {
  $keys = ["appointment_id","appointmentId","id","public_code","publicCode"];
  foreach ($keys as $k) {
    if (array_key_exists($k, $src)) {
      $s = pick_str($src[$k]);
      if ($s !== "") return $s;
    }
  }
  return "";
}
function sanitize_code($s): string {
  $s = trim((string)$s);
  if ($s === "") return "";
  return preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
}
function make_room($publicCode, $scheduledAt): string {
  $code = sanitize_code($publicCode);
  if ($code === "") return "";

  try {
    $tz = new DateTimeZone("Asia/Kolkata");
    $dt = new DateTime((string)$scheduledAt, $tz);
    $stamp = $dt->format("YmdHi"); // yyyyMMddHHmm
  } catch (Throwable $e) {
    $stamp = (string)floor(microtime(true) * 1000);
  }

  return "ss_appt_" . $code . "_" . $stamp;
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);

$src = [];
$method = $_SERVER["REQUEST_METHOD"] ?? "";
if ($method === "POST") {
  $src = read_json();
  if (!is_array($src)) $src = [];
} else if ($method === "GET") {
  $src = $_GET;
} else {
  json_response(405, ["ok"=>false, "error"=>"GET/POST only"]);
}

$apptKey = read_appt_key($src);
if ($apptKey === "") json_response(422, ["ok"=>false, "error"=>"Invalid appointment id"]);

$isNumericId = preg_match('/^\d+$/', $apptKey) === 1;

try {
  $sql = "
    SELECT
      a.id,
      a.public_code,
      a.patient_id,
      a.doctor_id,
      a.specialty,
      a.consult_type,
      a.symptoms,
      a.fee_amount,
      a.scheduled_at,
      a.duration_min,
      a.status,
      u.full_name AS doctor_name,
      u.phone     AS doctor_phone,
      dp.specialization,
      dp.practice_place
    FROM appointments a
    JOIN users u ON u.id = a.doctor_id
    LEFT JOIN doctor_profiles dp ON dp.user_id = a.doctor_id
    WHERE a.patient_id = ?
      AND " . ($isNumericId ? "a.id = ?" : "a.public_code = ?") . "
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$uid, $apptKey]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) json_response(404, ["ok"=>false, "error"=>"Appointment not found"]);

  $tz = new DateTimeZone("Asia/Kolkata");
  $scheduled = new DateTime((string)$row["scheduled_at"], $tz);
  $now = new DateTime("now", $tz);

  $diffSeconds = $scheduled->getTimestamp() - $now->getTimestamp();
  $minutesLeft = (int)floor($diffSeconds / 60);

  $dateLabel = $scheduled->format("Y-m-d");
  $timeLabel = $scheduled->format("H:i");

  $publicCode = (string)($row["public_code"] ?? "");
  $room = make_room($publicCode, (string)($row["scheduled_at"] ?? ""));

  json_response(200, [
    "ok" => true,
    "data" => [
      "appointmentId"   => (string)$publicCode,
      "internalId"      => (string)($row["id"] ?? ""),
      "public_code"     => (string)$publicCode,
      "doctor_id"       => (int)($row["doctor_id"] ?? 0),

      "room"            => $room,

      "doctorName"      => (string)($row["doctor_name"] ?? "Doctor"),
      "doctorPhone"     => (string)($row["doctor_phone"] ?? ""),
      "specialization"  => (string)($row["specialization"] ?? ""),
      "worksAt"         => (string)($row["practice_place"] ?? ""),

      "specialtyKey"    => (string)($row["specialty"] ?? ""),
      "consultType"     => (string)($row["consult_type"] ?? ""),
      "symptoms"        => (string)($row["symptoms"] ?? ""),
      "fee"             => (int)($row["fee_amount"] ?? 0),

      "dateLabel"       => $dateLabel,
      "timeLabel"       => $timeLabel,
      "scheduledAt"     => (string)($row["scheduled_at"] ?? ""),
      "durationMinutes" => (int)($row["duration_min"] ?? 0),
      "status"          => (string)($row["status"] ?? ""),

      "minutesLeft"     => $minutesLeft,
      "server_now_ms"   => (int)(microtime(true) * 1000)
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to load appointment",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
