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
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") json_response(405, ["ok"=>false, "error"=>"GET only"]);

$startEarlySec = 120;
$endExpr = "DATE_ADD(a.scheduled_at, INTERVAL COALESCE(a.duration_min,15) MINUTE)";

try {
  // doctor header
  $st = $pdo->prepare("
    SELECT u.full_name AS doctor_name,
           COALESCE(dp.specialization,'') AS doctor_specialization
    FROM users u
    LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
  ");
  $st->execute([$uid]);
  $doc = $st->fetch(PDO::FETCH_ASSOC) ?: ["doctor_name"=>"Doctor", "doctor_specialization"=>""];

  // unread notifications count (safe if table exists)
  $notif = 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $st->execute([$uid]);
    $notif = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $notif = 0;
  }

  // today stats
  $st = $pdo->prepare("
    SELECT
      COUNT(DISTINCT CASE WHEN a.status IN ('BOOKED','CONFIRMED','IN_PROGRESS','COMPLETED','NO_SHOW') THEN a.patient_id END) AS today_patients,
      SUM(CASE WHEN a.status='COMPLETED' THEN 1 ELSE 0 END) AS today_completed,
      SUM(CASE WHEN a.status='COMPLETED' THEN a.fee_amount ELSE 0 END) AS today_amount
    FROM appointments a
    WHERE a.doctor_id=?
      AND DATE(a.scheduled_at)=DATE(NOW())
  ");
  $st->execute([$uid]);
  $stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  // next 3 appointments (today, not ended yet)
  $st = $pdo->prepare("
    SELECT
      a.id,
      a.patient_id,             -- ✅ added (needed for AUDIO prescription + safe passing)
      a.status,                 -- ✅ added (needed for IN_PROGRESS start button logic)
      a.public_code,
      a.consult_type,
      a.symptoms,
      a.scheduled_at,
      a.duration_min,
      p.full_name AS patient_name,
      p.phone     AS patient_phone,
      pp.age      AS patient_age,
      pp.gender   AS patient_gender,
      CONCAT('ss_appt_', REPLACE(REPLACE(REPLACE(a.public_code,'-','_'),' ','_'),'#','_')) AS room,
      TIMESTAMPDIFF(SECOND, NOW(), a.scheduled_at) AS seconds_to_start,
      (NOW() >= DATE_SUB(a.scheduled_at, INTERVAL ? SECOND) AND NOW() < $endExpr) AS can_start
    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    LEFT JOIN patient_profiles pp ON pp.user_id = a.patient_id
    WHERE a.doctor_id=?
      AND DATE(a.scheduled_at)=DATE(NOW())
      AND a.status IN ('BOOKED','CONFIRMED','IN_PROGRESS')
      AND (a.status='IN_PROGRESS' OR NOW() < $endExpr)
    ORDER BY a.scheduled_at ASC
    LIMIT 3
  ");
  $st->execute([$startEarlySec, $uid]);
  $todayUpcoming = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_response(200, [
    "ok"=>true,
    "data"=>[
      "doctor_name" => (string)($doc["doctor_name"] ?? "Doctor"),
      "doctor_specialization" => (string)($doc["doctor_specialization"] ?? ""),
      "notifications_count" => (int)$notif,

      "today_patients" => (int)($stats["today_patients"] ?? 0),
      "today_completed" => (int)($stats["today_completed"] ?? 0),
      "today_amount" => (int)($stats["today_amount"] ?? 0),

      "rating" => 0.0,

      "server_now_ms" => (int)(microtime(true) * 1000),
      "today_upcoming" => $todayUpcoming
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Failed to load dashboard", "debug"=>$e->getMessage()]);
}
