<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  if ($ip === "127.0.0.1" || $ip === "::1") return true;
  if (strpos($ip, "10.") === 0 || strpos($ip, "192.168.") === 0 || strpos($ip, "172.16.") === 0) return true;
  return false;
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$in = read_json();
if (!is_array($in)) $in = [];

$view   = strtoupper(trim((string)($in["view"] ?? "UPCOMING"))); // UPCOMING | PAST | ALL
$limit  = (int)($in["limit"] ?? 50);
$offset = (int)($in["offset"] ?? 0);

if (!in_array($view, ["UPCOMING","PAST","ALL"], true)) $view = "UPCOMING";
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;
if ($offset < 0) $offset = 0;

$now = (new DateTime())->format("Y-m-d H:i:s");

$where  = "a.patient_id = :uid";
$params = [":uid" => $uid];

$orderBy = "a.scheduled_at DESC";

if ($view === "UPCOMING") {
  $where .= " AND a.scheduled_at >= :now AND UPPER(a.status) IN ('BOOKED','CONFIRMED')";
  $params[":now"] = $now;
  $orderBy = "a.scheduled_at ASC";
} else if ($view === "PAST") {
  $where .= " AND (a.scheduled_at < :now OR UPPER(a.status) IN ('CANCELLED','CANCELED','COMPLETED','DONE','CLOSED','REJECTED'))";
  $params[":now"] = $now;
  $orderBy = "a.scheduled_at DESC";
} else {
  // ALL: no :now filter, no :now param
  $orderBy = "a.scheduled_at DESC";
}

$sql = "
  SELECT
    a.id,
    a.public_code,
    a.doctor_id,
    a.specialty,
    a.consult_type,
    a.scheduled_at,
    a.duration_min,
    a.status,
    a.fee_amount,

    u.full_name AS doctor_name,
    dp.specialization AS doctor_specialization,
    dp.languages_csv,
    dp.rating
  FROM appointments a
  JOIN users u ON u.id = a.doctor_id
  LEFT JOIN doctor_profiles dp ON dp.user_id = a.doctor_id
  WHERE $where
  ORDER BY $orderBy
  LIMIT $limit OFFSET $offset
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "appointmentId"   => (string)($r["id"] ?? ""),
      "id"              => (string)($r["id"] ?? ""),
      "public_code"     => (string)($r["public_code"] ?? ""),
      "doctor_id"       => (int)($r["doctor_id"] ?? 0),

      "doctor_name"     => (string)($r["doctor_name"] ?? ""),
      "speciality_key"  => (string)($r["specialty"] ?? ""),
      "consult_type"    => (string)($r["consult_type"] ?? ""),
      "scheduled_at"    => (string)($r["scheduled_at"] ?? ""),
      "duration_min"    => (int)($r["duration_min"] ?? 30),
      "status"          => (string)($r["status"] ?? ""),

      "fee_amount"      => (int)($r["fee_amount"] ?? 0),
      "languages_csv"   => (string)($r["languages_csv"] ?? ""),
      "rating"          => (float)($r["rating"] ?? 0),
    ];
  }

  json_response(200, ["ok"=>true, "data"=>[
    "view"   => $view,
    "limit"  => $limit,
    "offset" => $offset,
    "items"  => $items
  ]]);

} catch (Throwable $e) {
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to list appointments",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
