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

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);

$in = read_json();
if (!is_array($in)) $in = [];

$appointmentId  = (int)($in["appointment_id"] ?? 0);
$appointmentKey = trim((string)($in["appointment_key"] ?? ""));
$prescriptionId = (int)($in["prescription_id"] ?? 0);

try {

  // 1) Find appointment for this patient
  $appt = null;

  if ($appointmentId > 0) {
    $st = $pdo->prepare("SELECT * FROM appointments WHERE id=? AND patient_id=? LIMIT 1");
    $st->execute([$appointmentId, $uid]);
    $appt = $st->fetch(PDO::FETCH_ASSOC);
  } else if ($appointmentKey !== "") {
    // public_code / appointment key
    $st = $pdo->prepare("SELECT * FROM appointments WHERE public_code=? AND patient_id=? LIMIT 1");
    $st->execute([$appointmentKey, $uid]);
    $appt = $st->fetch(PDO::FETCH_ASSOC);
  } else if ($prescriptionId > 0) {
    // resolve appointment from prescription
    $st = $pdo->prepare("
      SELECT a.*
      FROM prescriptions p
      JOIN appointments a ON a.id = p.appointment_id
      WHERE p.id=? AND a.patient_id=?
      LIMIT 1
    ");
    $st->execute([$prescriptionId, $uid]);
    $appt = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$appt) json_response(404, ["ok"=>false, "error"=>"Appointment not found"]);

  $aid = (int)$appt["id"];
  $doctorId = (int)($appt["doctor_id"] ?? 0);

  // 2) Doctor info
  $st = $pdo->prepare("
    SELECT u.full_name AS doctor_name,
           COALESCE(dp.specialization, '') AS doctor_speciality
    FROM users u
    LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
  ");
  $st->execute([$doctorId]);
  $doc = $st->fetch(PDO::FETCH_ASSOC) ?: ["doctor_name"=>"", "doctor_speciality"=>""];

  // 3) Pick prescription:
  //    if client passed prescription_id -> use it
  //    else use latest prescription for this appointment
  $pres = null;

  if ($prescriptionId > 0) {
    $st = $pdo->prepare("
      SELECT *
      FROM prescriptions
      WHERE id=? AND patient_id=? AND (appointment_id IS NULL OR appointment_id=?)
      LIMIT 1
    ");
    $st->execute([$prescriptionId, $uid, $aid]);
    $pres = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$pres) {
    $st = $pdo->prepare("
      SELECT *
      FROM prescriptions
      WHERE appointment_id=? AND patient_id=?
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([$aid, $uid]);
    $pres = $st->fetch(PDO::FETCH_ASSOC);
  }

  if (!$pres) {
    // appointment exists but prescription not created
    json_response(200, [
      "ok"=>true,
      "data"=>[
        "appointment_id"=>$aid,
        "completed_at"=>"",
        "duration_minutes"=>0,
        "doctor_name" => (string)($doc["doctor_name"] ?? ""),
        "doctor_speciality" => (string)($doc["doctor_speciality"] ?? ""),
        "diagnosis"=>"",
        "doctor_notes"=>"",
        "follow_up_text"=>"",
        "medicines"=>[]
      ]
    ]);
  }

  $pid = (int)($pres["id"] ?? 0);

  // 4) Determine "completed_at"
  // Prefer appointments.updated_at if exists (your doctor endpoint tries to set it),
  // else fall back to prescriptions.created_at
  $completedAt = "";
  try {
    $st = $pdo->prepare("SELECT updated_at FROM appointments WHERE id=? LIMIT 1");
    $st->execute([$aid]);
    $completedAt = (string)($st->fetchColumn() ?: "");
  } catch (Throwable $e) {
    $completedAt = ""; // ignore
  }
  if ($completedAt === "") {
    $completedAt = (string)($pres["created_at"] ?? "");
  }

  // 5) Duration minutes (best-effort)
  // If you have start/end columns later, you can improve. For now: 0.
  $durationMinutes = 0;

  // 6) Diagnosis = prescriptions.title (because your app sends diagnosis as title)
  $diagnosis = trim((string)($pres["title"] ?? ""));

  // 7) Doctor notes: if you later add a column in prescriptions, this will auto-work.
  $doctorNotes = "";
  if (array_key_exists("doctor_notes", $pres)) $doctorNotes = trim((string)($pres["doctor_notes"] ?? ""));
  if ($doctorNotes === "" && array_key_exists("notes", $pres)) $doctorNotes = trim((string)($pres["notes"] ?? ""));

  // 8) Medicines: schema tolerant (name OR medicine_name)
  $meds = [];
  $items = [];

  // Try query with column "name"
  try {
    $st = $pdo->prepare("
      SELECT name, dosage, frequency, duration, instructions
      FROM prescription_items
      WHERE prescription_id=?
      ORDER BY id ASC
    ");
    $st->execute([$pid]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e1) {
    // Fallback to "medicine_name"
    $st = $pdo->prepare("
      SELECT medicine_name AS name, dosage, frequency, duration, instructions
      FROM prescription_items
      WHERE prescription_id=?
      ORDER BY id ASC
    ");
    $st->execute([$pid]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  foreach ($items as $r) {
    $name = trim((string)($r["name"] ?? ""));
    if ($name === "") continue;

    $dosage = trim((string)($r["dosage"] ?? ""));
    $freq   = trim((string)($r["frequency"] ?? ""));
    $dur    = trim((string)($r["duration"] ?? ""));

    $line1Parts = [];
    if ($dosage !== "") $line1Parts[] = $dosage;
    if ($freq !== "") $line1Parts[] = $freq;
    $line1 = implode(", ", $line1Parts);

    $line2 = ($dur !== "") ? ("For ".$dur) : "";

    $meds[] = [
      "name" => $name,
      "line1" => $line1,
      "line2" => $line2
    ];
  }

  json_response(200, [
    "ok"=>true,
    "data"=>[
      "appointment_id"=>$aid,
      "prescription_id"=>$pid,
      "completed_at"=>$completedAt,
      "duration_minutes"=>$durationMinutes,

      "doctor_name" => (string)($doc["doctor_name"] ?? ""),
      "doctor_speciality" => (string)($doc["doctor_speciality"] ?? ""),

      "diagnosis"=>$diagnosis,
      "doctor_notes"=>$doctorNotes,
      "follow_up_text"=>"",
      "medicines"=>$meds
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to load consultation summary",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
