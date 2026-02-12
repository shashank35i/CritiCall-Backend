<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok" => false, "error" => "POST only"]);
}

$auth = require_auth();
$data = read_json();
require_fields($data, ["availability"]);

$uid = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Invalid token"]);

$avail = $data["availability"];
if (!is_array($avail)) json_response(422, ["ok"=>false, "error"=>"availability must be array"]);

function valid_time($t) {
  return is_string($t) && preg_match('/^\d{2}:\d{2}$/', $t);
}
function to_minutes($t) {
  $p = explode(":", $t);
  $h = intval($p[0] ?? 0);
  $m = intval($p[1] ?? 0);
  return $h * 60 + $m;
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// doctor only
$r = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$r->execute([$uid]);
$u = $r->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "DOCTOR") {
  json_response(403, ["ok"=>false, "error"=>"Doctor only"]);
}

try {
  $pdo->beginTransaction();

  $up = $pdo->prepare("
    INSERT INTO doctor_availability (user_id, day_of_week, enabled, start_time, end_time)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      enabled=VALUES(enabled),
      start_time=VALUES(start_time),
      end_time=VALUES(end_time),
      updated_at=CURRENT_TIMESTAMP
  ");

  foreach ($avail as $a) {
    if (!is_array($a)) continue;

    $d  = (int)($a["day_of_week"] ?? 0);
    $en = (int)($a["enabled"] ?? 0);
    $st = (string)($a["start_time"] ?? "09:00");
    $et = (string)($a["end_time"] ?? "17:00");

    if ($d < 1 || $d > 7) json_response(422, ["ok"=>false, "error"=>"Invalid day_of_week"]);
    if (!valid_time($st) || !valid_time($et)) json_response(422, ["ok"=>false, "error"=>"Invalid time format"]);

    // if enabled, enforce end > start
    if ($en === 1 && to_minutes($et) <= to_minutes($st)) {
      json_response(422, ["ok"=>false, "error"=>"End time must be after start time"]);
    }

    $up->execute([$uid, $d, $en ? 1 : 0, $st, $et]);
  }

  $pdo->commit();
  json_response(200, ["ok"=>true, "message"=>"Saved"]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false, "error"=>"Failed to save"]);
}
