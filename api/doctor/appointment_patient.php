<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)($auth['uid'] ?? 0);
$role = strtoupper((string)($auth['role'] ?? ''));

if ($uid <= 0) json_response(401, ['ok'=>false, 'error'=>'Unauthorized']);
if ($role !== 'DOCTOR') json_response(403, ['ok'=>false, 'error'=>'Forbidden']);

$apptId = (int)($_GET['appointment_id'] ?? 0);
if ($apptId <= 0) json_response(400, ['ok'=>false, 'error'=>'appointment_id required']);

$st = $pdo->prepare("
  SELECT a.id AS appointment_id, a.patient_id
  FROM appointments a
  WHERE a.id = :id AND a.doctor_id = :doc
  LIMIT 1
");
$st->execute([':id'=>$apptId, ':doc'=>$uid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) json_response(404, ['ok'=>false, 'error'=>'Not found']);

json_response(200, [
  'ok' => true,
  'data' => [
    'appointment_id' => (int)$row['appointment_id'],
    'patient_id' => (int)$row['patient_id'],
  ]
]);
