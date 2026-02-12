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

function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = :t
      AND COLUMN_NAME = :c
    LIMIT 1
  ");
  $st->execute([":t"=>$table, ":c"=>$col]);
  return (bool)$st->fetchColumn();
}

function pick_col(PDO $pdo, string $table, array $candidates): string {
  foreach ($candidates as $c) {
    if (has_col($pdo, $table, $c)) return $c;
  }
  return "";
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);

$in = read_json();
if (!is_array($in)) $in = [];

$patientId      = (int)($in["patient_id"] ?? 0);
$apptId         = (int)($in["appointment_id"] ?? 0);
$appointmentKey = trim((string)($in["appointment_key"] ?? ""));
$title          = trim((string)($in["title"] ?? ""));
$diagnosis      = trim((string)($in["diagnosis"] ?? ""));
$doctorNotes    = trim((string)($in["doctor_notes"] ?? ""));
$followUpText   = trim((string)($in["follow_up_text"] ?? ""));
$items          = $in["items"] ?? null;

if ($patientId <= 0) json_response(400, ["ok"=>false, "error"=>"Missing patient_id"]);
if ($title === "") $title = $diagnosis;
if ($title === "") json_response(400, ["ok"=>false, "error"=>"Missing title/diagnosis"]);
if (!is_array($items) || count($items) < 1) json_response(400, ["ok"=>false, "error"=>"Add at least one medicine"]);

try {
  // patient check
  $stP = $pdo->prepare("SELECT id, role FROM users WHERE id=:id LIMIT 1");
  $stP->execute([":id" => $patientId]);
  $pRow = $stP->fetch(PDO::FETCH_ASSOC);
  if (!$pRow) json_response(404, ["ok"=>false, "error"=>"Patient not found"]);
  if (strtoupper((string)$pRow["role"]) !== "PATIENT") json_response(400, ["ok"=>false, "error"=>"Invalid patient_id"]);

  // resolve appointment by public_code only if column exists
  $hasPublicCode = has_col($pdo, "appointments", "public_code");
  if ($apptId <= 0 && $appointmentKey !== "" && $hasPublicCode) {
    $stR = $pdo->prepare("
      SELECT id
      FROM appointments
      WHERE public_code = :k AND doctor_id = :did
      ORDER BY id DESC
      LIMIT 1
    ");
    $stR->execute([":k"=>$appointmentKey, ":did"=>$uid]);
    $apptId = (int)($stR->fetchColumn() ?: 0);
  }

  // validate appointment (if provided)
  if ($apptId > 0) {
    $stA = $pdo->prepare("
      SELECT id
      FROM appointments
      WHERE id=:aid AND doctor_id=:did AND patient_id=:pid
      LIMIT 1
    ");
    $stA->execute([":aid"=>$apptId, ":did"=>$uid, ":pid"=>$patientId]);
    if (!$stA->fetchColumn()) json_response(404, ["ok"=>false, "error"=>"Appointment not found for this doctor/patient"]);
  }

  // ---- flexible column picks ----
  $diagnosisCol = pick_col($pdo, "prescriptions", ["diagnosis"]);
  $notesCol     = pick_col($pdo, "prescriptions", ["doctor_notes", "notes"]);
  $followCol    = pick_col($pdo, "prescriptions", ["follow_up_text", "followup_note", "followup_text"]);

  $itemNameCol  = pick_col($pdo, "prescription_items", ["name", "medicine_name"]);
  if ($itemNameCol === "") json_response(500, ["ok"=>false, "error"=>"prescription_items missing name column"]);

  $pdo->beginTransaction();

  // insert prescriptions
  $cols = ["patient_id","doctor_id","title"];
  $vals = [":patient_id",":doctor_id",":title"];
  $bind = [
    ":patient_id"=>$patientId,
    ":doctor_id"=>$uid,
    ":title"=>$title,
  ];

  if ($apptId > 0 && has_col($pdo, "prescriptions", "appointment_id")) {
    $cols[] = "appointment_id";
    $vals[] = ":appointment_id";
    $bind[":appointment_id"] = $apptId;
  }

  if ($diagnosisCol !== "") {
    $cols[] = $diagnosisCol;
    $vals[] = ":diagnosis";
    $bind[":diagnosis"] = $diagnosis;
  }

  if ($notesCol !== "" && $doctorNotes !== "") {
    $cols[] = $notesCol;
    $vals[] = ":doctor_notes";
    $bind[":doctor_notes"] = $doctorNotes;
  }

  if ($followCol !== "" && $followUpText !== "") {
    $cols[] = $followCol;
    $vals[] = ":follow_up_text";
    $bind[":follow_up_text"] = $followUpText;
  }

  $sqlPres = "INSERT INTO prescriptions (".implode(",",$cols).") VALUES (".implode(",",$vals).")";
  $pdo->prepare($sqlPres)->execute($bind);

  $prescriptionId = (int)$pdo->lastInsertId();
  if ($prescriptionId <= 0) {
    $pdo->rollBack();
    json_response(500, ["ok"=>false, "error"=>"Failed to create prescription"]);
  }

  // insert items
  $sqlItem = "INSERT INTO prescription_items
    (prescription_id, $itemNameCol, dosage, frequency, duration, instructions)
    VALUES
    (:pid, :name, :dosage, :frequency, :duration, :instructions)";
  $stItem = $pdo->prepare($sqlItem);

  $savedAny = false;
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $name = trim((string)($it["name"] ?? ""));
    if ($name === "") continue;

    $stItem->execute([
      ":pid" => $prescriptionId,
      ":name" => $name,
      ":dosage" => trim((string)($it["dosage"] ?? "")),
      ":frequency" => trim((string)($it["frequency"] ?? "")),
      ":duration" => trim((string)($it["duration"] ?? "")),
      ":instructions" => trim((string)($it["instructions"] ?? "")),
    ]);
    $savedAny = true;
  }

  if (!$savedAny) {
    $pdo->rollBack();
    json_response(400, ["ok"=>false, "error"=>"No valid medicine rows"]);
  }

  // mark appointment finished
  if ($apptId > 0) {
    $pdo->prepare("
      UPDATE appointments
      SET status='FINISHED'
      WHERE id=:aid AND doctor_id=:did AND patient_id=:pid
    ")->execute([":aid"=>$apptId, ":did"=>$uid, ":pid"=>$patientId]);
  }

  // notification (best-effort)
  try {
    $stD = $pdo->prepare("SELECT full_name FROM users WHERE id=:id LIMIT 1");
    $stD->execute([":id"=>$uid]);
    $dName = (string)($stD->fetchColumn() ?: "Doctor");

    $dataJson = json_encode([
      "type" => "PRESCRIPTION",
      "prescription_id" => $prescriptionId
    ], JSON_UNESCAPED_SLASHES);

    $pdo->prepare("
      INSERT INTO notifications (user_id, title, body, is_read, created_at, data_json)
      VALUES (:uid, :t, :b, 0, NOW(), :dj)
    ")->execute([
      ":uid"=>$patientId,
      ":t"=>"New prescription",
      ":b"=>"Dr. $dName sent you a prescription for \"$title\".",
      ":dj"=>$dataJson
    ]);
  } catch (Throwable $ignore) {}

  $pdo->commit();

  json_response(200, [
    "ok"=>true,
    "data"=>[
      "prescription_id"=>$prescriptionId,
      "appointment_id"=>($apptId > 0 ? $apptId : null),
      "patient_id"=>$patientId
    ]
  ]);

} catch (Throwable $e) {
  try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $ignore) {}
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to create prescription",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
