<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo = db();

$uid = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);

$stmt = $pdo->prepare("
  SELECT day_of_week, enabled, start_time, end_time
  FROM doctor_availability
  WHERE user_id=?
  ORDER BY day_of_week ASC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_response(200, ["ok"=>true, "availability"=>$rows]);
