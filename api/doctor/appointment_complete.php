<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

date_default_timezone_set("Asia/Kolkata");

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$body = read_json();
if (!is_array($body)) $body = [];

$key = trim((string)($body["appointment_key"] ?? ""));
if ($key === "") json_response(422, ["ok"=>false, "error"=>"Missing appointment_key"]);

function is_digits($s) { return is_string($s) && preg_match('/^\d+$/', $s); }

try {
  // Find appointment by id OR public_code, must belong to doctor
  if (is_digits($key)) {
    $st = $pdo->prepare("SELECT id, public_code, status FROM appointments WHERE doctor_id=? AND (id=? OR public_code=?) LIMIT 1");
    $st->execute([$uid, (int)$key, $key]);
  } else {
    $st = $pdo->prepare("SELECT id, public_code, status FROM appointments WHERE doctor_id=? AND public_code=? LIMIT 1");
    $st->execute([$uid, $key]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_response(404, ["ok"=>false, "error"=>"Appointment not found"]);

  $id = (int)$row["id"];
  $cur = strtoupper((string)($row["status"] ?? "BOOKED"));

  if ($cur === "CANCELLED") {
    json_response(409, ["ok"=>false, "error"=>"Appointment is cancelled"]);
  }

  if ($cur === "COMPLETED") {
    json_response(200, ["ok"=>true, "data"=>[
      "id" => (string)$id,
      "public_code" => (string)$row["public_code"],
      "status" => "COMPLETED"
    ]]);
  }

  // ✅ Mark completed
  $up = $pdo->prepare("UPDATE appointments SET status='COMPLETED' WHERE id=? AND doctor_id=?");
  $up->execute([$id, $uid]);

  json_response(200, ["ok"=>true, "data"=>[
    "id" => (string)$id,
    "public_code" => (string)$row["public_code"],
    "status" => "COMPLETED"
  ]]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Failed to complete appointment", "debug"=>$e->getMessage()]);
}
