<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  json_response(405, ["ok"=>false, "error"=>"GET only"]);
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Forbidden"]);

$pid = (int)($_GET["prescription_id"] ?? ($_GET["id"] ?? 0));
if ($pid <= 0) json_response(400, ["ok"=>false, "error"=>"Missing prescription_id"]);

function is_local_request(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  if ($ip === "127.0.0.1" || $ip === "::1") return true;
  if (strpos($ip, "10.") === 0) return true;
  if (strpos($ip, "192.168.") === 0) return true;
  if (strpos($ip, "172.16.") === 0) return true;
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

try {
  // ---- prescriptions optional columns ----
  $hasDiagnosis = has_col($pdo, "prescriptions", "diagnosis");

  $notesCol = "";
  if (has_col($pdo, "prescriptions", "doctor_notes")) $notesCol = "doctor_notes";
  else if (has_col($pdo, "prescriptions", "notes")) $notesCol = "notes";

  $followCol = "";
  if (has_col($pdo, "prescriptions", "follow_up_text")) $followCol = "follow_up_text";
  else if (has_col($pdo, "prescriptions", "followup_note")) $followCol = "followup_note";
  else if (has_col($pdo, "prescriptions", "followup_text")) $followCol = "followup_text";

  $selDiagnosis = $hasDiagnosis ? "COALESCE(p.diagnosis,'') AS diagnosis" : "'' AS diagnosis";
  $selNotes     = ($notesCol !== "") ? "COALESCE(p.$notesCol,'') AS doctor_notes" : "'' AS doctor_notes";
  $selFollow    = ($followCol !== "") ? "COALESCE(p.$followCol,'') AS followup_note" : "'' AS followup_note";

  // ---- prescription_items name column ----
  $itemNameCol = "";
  if (has_col($pdo, "prescription_items", "name")) $itemNameCol = "name";
  else if (has_col($pdo, "prescription_items", "medicine_name")) $itemNameCol = "medicine_name";
  else json_response(500, ["ok"=>false, "error"=>"prescription_items missing name column"]);

  // ---- doctor verified column on users may vary ----
  $hasAdminStatus = has_col($pdo, "users", "admin_verification_status");
  $selAdminStatus = $hasAdminStatus ? "d.admin_verification_status AS admin_status" : "'' AS admin_status";

  $sql = "
    SELECT
      p.id,
      p.appointment_id,
      p.patient_id,
      p.doctor_id,
      p.title,
      p.created_at,

      $selDiagnosis,
      $selNotes,
      $selFollow,

      d.full_name AS doctor_name,
      $selAdminStatus,

      COALESCE(dp.specialization, '') AS doctor_specialization,
      COALESCE(dp.works_at_text, '') AS works_at,
      COALESCE(dp.practice_place, '') AS practice_place
    FROM prescriptions p
    JOIN users d ON d.id = p.doctor_id
    LEFT JOIN doctor_profiles dp ON dp.user_id = d.id
    WHERE p.id = :pid AND p.patient_id = :uid
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([":pid"=>$pid, ":uid"=>$uid]);
  $pres = $st->fetch(PDO::FETCH_ASSOC);

  if (!$pres) {
    json_response(404, ["ok"=>false, "error"=>"Prescription not found"]);
  }

  $st2 = $pdo->prepare("
    SELECT
      id,
      $itemNameCol AS name,
      COALESCE(dosage,'') AS dosage,
      COALESCE(frequency,'') AS frequency,
      COALESCE(duration,'') AS duration,
      COALESCE(instructions,'') AS instructions
    FROM prescription_items
    WHERE prescription_id = :pid
    ORDER BY id ASC
  ");
  $st2->execute([":pid"=>$pid]);
  $items = $st2->fetchAll(PDO::FETCH_ASSOC);

  $adminStatus = strtoupper(trim((string)($pres["admin_status"] ?? "")));
  $doctorVerified = ($adminStatus === "" ? true : ($adminStatus === "VERIFIED"));

  $worksAt = (string)($pres["works_at"] ?: $pres["practice_place"]);

  json_response(200, [
    "ok" => true,
    "data" => [
      "prescription" => [
        "id" => (int)$pres["id"],
        "appointment_id" => $pres["appointment_id"] ? (int)$pres["appointment_id"] : null,
        "created_at" => (string)$pres["created_at"],

        "title" => (string)$pres["title"],
        "diagnosis" => (string)$pres["diagnosis"],
        "doctor_notes" => (string)$pres["doctor_notes"],
        "followup_note" => (string)$pres["followup_note"],

        "doctor_id" => (int)$pres["doctor_id"],
        "doctor_name" => (string)$pres["doctor_name"],
        "doctor_specialization" => (string)$pres["doctor_specialization"],
        "works_at" => $worksAt,

        "doctor_verified" => $doctorVerified
      ],
      "items" => $items
    ]
  ]);

} catch (Throwable $e) {
  if (is_local_request()) {
    json_response(500, ["ok"=>false, "error"=>"Server error", "debug"=>$e->getMessage()]);
  }
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
