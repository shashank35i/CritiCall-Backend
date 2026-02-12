<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);

$in = read_json();
if (!is_array($in)) $in = [];

$appointmentId = (int)($in["appointment_id"] ?? 0);
$appointmentKey = trim((string)($in["appointment_key"] ?? "")); // can be public_code or numeric id
$publicCode = trim((string)($in["public_code"] ?? ""));
$room = trim((string)($in["room"] ?? ""));

if ($publicCode === "" && $room !== "") {
  // if you follow ss_appt_<public_code>
  if (strpos($room, "ss_appt_") === 0) {
    $publicCode = substr($room, strlen("ss_appt_"));
  }
}

try {
  $row = null;

  if ($appointmentId > 0) {
    $st = $pdo->prepare("
      SELECT id, public_code, patient_id, doctor_id, consult_type, scheduled_at, duration_min, status
      FROM appointments
      WHERE id=:aid AND doctor_id=:did
      LIMIT 1
    ");
    $st->execute([":aid"=>$appointmentId, ":did"=>$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$row && $appointmentKey !== "") {
    // if numeric -> try by id first, else by public_code
    $isNum = ctype_digit($appointmentKey);
    if ($isNum) {
      $st = $pdo->prepare("
        SELECT id, public_code, patient_id, doctor_id, consult_type, scheduled_at, duration_min, status
        FROM appointments
        WHERE id=:aid AND doctor_id=:did
        LIMIT 1
      ");
      $st->execute([":aid" => (int)$appointmentKey, ":did"=>$uid]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
      $st = $pdo->prepare("
        SELECT id, public_code, patient_id, doctor_id, consult_type, scheduled_at, duration_min, status
        FROM appointments
        WHERE public_code=:pc AND doctor_id=:did
        LIMIT 1
      ");
      $st->execute([":pc"=>$appointmentKey, ":did"=>$uid]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    }
  }

  if (!$row && $publicCode !== "") {
    $st = $pdo->prepare("
      SELECT id, public_code, patient_id, doctor_id, consult_type, scheduled_at, duration_min, status
      FROM appointments
      WHERE public_code=:pc AND doctor_id=:did
      LIMIT 1
    ");
    $st->execute([":pc"=>$publicCode, ":did"=>$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$row) {
    json_response(404, ["ok"=>false, "error"=>"Appointment not found"]);
  }

  $pid = (int)($row["patient_id"] ?? 0);
  $aid = (int)($row["id"] ?? 0);

  if ($pid <= 0 || $aid <= 0) {
    json_response(404, ["ok"=>false, "error"=>"Appointment found but missing patient_id"]);
  }

  json_response(200, ["ok"=>true, "data"=>[
    "appointment_id" => $aid,
    "patient_id" => $pid,
    "public_code" => (string)($row["public_code"] ?? ""),
    "consult_type" => (string)($row["consult_type"] ?? ""),
    "scheduled_at" => (string)($row["scheduled_at"] ?? ""),
    "duration_min" => (int)($row["duration_min"] ?? 15),
    "status" => (string)($row["status"] ?? ""),
  ]]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Failed to resolve appointment", "debug"=>$e->getMessage()]);
}
