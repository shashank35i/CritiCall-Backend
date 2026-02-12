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
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);

$body = read_json();
if (!is_array($body)) $body = [];

$patient_id = isset($body["patient_id"]) ? (int)$body["patient_id"] : 0;
if ($patient_id <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid patient_id"]);

try {
  // 1) Ensure this patient belongs to this doctor (privacy)
  $st = $pdo->prepare("SELECT 1 FROM appointments WHERE doctor_id=? AND patient_id=? LIMIT 1");
  $st->execute([$uid, $patient_id]);
  if (!$st->fetchColumn()) {
    json_response(404, ["ok"=>false, "error"=>"Patient not found for this doctor"]);
  }

  // 2) Patient basic info (users + patient_profiles)
  $st = $pdo->prepare("
    SELECT
      u.id,
      u.full_name,
      pp.gender,
      pp.age,
      pp.village_town,
      pp.district,
      pp.medical_history
    FROM users u
    LEFT JOIN patient_profiles pp ON pp.user_id = u.id
    WHERE u.id = ? AND UPPER(u.role) = 'PATIENT'
    LIMIT 1
  ");
  $st->execute([$patient_id]);
  $patient = $st->fetch(PDO::FETCH_ASSOC);
  if (!$patient) json_response(404, ["ok"=>false, "error"=>"Patient not found"]);

  // Normalize patient fields (your Android expects these keys)
  $patientOut = [
    "id"         => (int)$patient["id"],
    "full_name"  => (string)($patient["full_name"] ?? ""),
    "gender"     => (string)($patient["gender"] ?? ""),
    "age"        => (int)($patient["age"] ?? 0),
    "village"    => (string)($patient["village_town"] ?? ""),
    "district"   => (string)($patient["district"] ?? ""),
    // you don't have blood_group column in your patient_profiles table screenshot
    "blood_group" => ""
  ];

  // 3) Stats: TOTAL VISITS + LAST VISIT (COMPLETED only)
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN UPPER(a.status)='COMPLETED' THEN 1 ELSE 0 END) AS total_visits,
      MAX(CASE WHEN UPPER(a.status)='COMPLETED' THEN a.scheduled_at ELSE NULL END) AS last_visit_at
    FROM appointments a
    WHERE a.doctor_id = ? AND a.patient_id = ?
  ");
  $st->execute([$uid, $patient_id]);
  $stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $statsOut = [
    "total_visits" => (int)($stats["total_visits"] ?? 0),
    "last_visit_at" => (string)($stats["last_visit_at"] ?? "")
  ];

  // 4) Latest appointment (used for Start Consultation)
  // Prefer active/booked-like statuses; fallback to most recent if none.
  $latest = null;

  $st = $pdo->prepare("
    SELECT
      a.id AS appointment_id,
      a.public_code,
      a.scheduled_at,
      a.status,
      CONCAT('ss_appt_', REPLACE(REPLACE(REPLACE(a.public_code,'-','_'),' ','_'),'#','_')) AS room
    FROM appointments a
    WHERE a.doctor_id = ? AND a.patient_id = ?
      AND UPPER(a.status) IN ('BOOKED','CONFIRMED','SCHEDULED','PENDING','ACTIVE','APPROVED')
    ORDER BY a.scheduled_at DESC
    LIMIT 1
  ");
  $st->execute([$uid, $patient_id]);
  $latest = $st->fetch(PDO::FETCH_ASSOC);

  if (!$latest) {
    $st = $pdo->prepare("
      SELECT
        a.id AS appointment_id,
        a.public_code,
        a.scheduled_at,
        a.status,
        CONCAT('ss_appt_', REPLACE(REPLACE(REPLACE(a.public_code,'-','_'),' ','_'),'#','_')) AS room
      FROM appointments a
      WHERE a.doctor_id = ? AND a.patient_id = ?
      ORDER BY a.scheduled_at DESC
      LIMIT 1
    ");
    $st->execute([$uid, $patient_id]);
    $latest = $st->fetch(PDO::FETCH_ASSOC);
  }

  $latestOut = null;
  if ($latest) {
    $latestOut = [
      "appointment_id" => (int)($latest["appointment_id"] ?? 0),
      "public_code"    => (string)($latest["public_code"] ?? ""),
      "scheduled_at"   => (string)($latest["scheduled_at"] ?? ""),
      "status"         => (string)($latest["status"] ?? ""),
      "room"           => (string)($latest["room"] ?? "")
    ];
  }

  // 5) Prescriptions (doctor + patient)
  $rxOut = [];
  $st = $pdo->prepare("
    SELECT
      p.id,
      p.title,
      p.created_at,
      (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) AS items_count
    FROM prescriptions p
    WHERE p.patient_id = ? AND p.doctor_id = ?
    ORDER BY p.created_at DESC
    LIMIT 100
  ");
  $st->execute([$patient_id, $uid]);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $rxOut[] = [
      "id" => (int)($r["id"] ?? 0),
      "title" => (string)($r["title"] ?? ""),
      "created_at" => (string)($r["created_at"] ?? ""),
      "items_count" => (int)($r["items_count"] ?? 0)
    ];
  }

  // 6) Vitals history (doctor can view patient vitals)
  $vitalsOut = [];
  $st = $pdo->prepare("
    SELECT
      id,
      created_at,
      client_recorded_at_ms,
      systolic,
      diastolic,
      sugar,
      sugar_context,
      temperature_f,
      weight_kg,
      notes
    FROM patient_vitals
    WHERE patient_id = ?
    ORDER BY
      COALESCE(client_recorded_at_ms, UNIX_TIMESTAMP(created_at) * 1000) DESC
    LIMIT 100
  ");
  $st->execute([$patient_id]);
  while ($v = $st->fetch(PDO::FETCH_ASSOC)) {
    $vitalsOut[] = [
      "id" => (int)($v["id"] ?? 0),
      "created_at" => (string)($v["created_at"] ?? ""),
      "client_recorded_at_ms" => $v["client_recorded_at_ms"] !== null ? (int)$v["client_recorded_at_ms"] : null,
      "systolic" => $v["systolic"] !== null ? (int)$v["systolic"] : null,
      "diastolic" => $v["diastolic"] !== null ? (int)$v["diastolic"] : null,
      "sugar" => $v["sugar"] !== null ? (int)$v["sugar"] : null,
      "sugar_context" => (string)($v["sugar_context"] ?? "FASTING"),
      "temperature_f" => $v["temperature_f"] !== null ? (float)$v["temperature_f"] : null,
      "weight_kg" => $v["weight_kg"] !== null ? (float)$v["weight_kg"] : null,
      "notes" => (string)($v["notes"] ?? "")
    ];
  }

  // (optional) medical_history JSON stored in patient_profiles.medical_history
  $medHistory = $patient["medical_history"] ?? null;

  json_response(200, [
    "ok" => true,
    "data" => [
      "patient" => $patientOut,
      "stats" => $statsOut,
      "latest_appointment" => $latestOut,
      "prescriptions" => $rxOut,
      "vitals_history" => $vitalsOut,
      "medical_history" => $medHistory
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, [
    "ok" => false,
    "error" => "Failed to load patient record",
    "debug" => is_localhost() ? $e->getMessage() : null
  ]);
}
