<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$q = read_json();
$id = (int)($q["notification_id"] ?? $q["id"] ?? 0);
if ($id <= 0) json_response(422, ["ok"=>false, "error"=>"Missing notification_id"]);

$st = $pdo->prepare("
  UPDATE notifications
  SET is_read=1, read_at=NOW()
  WHERE id=? AND user_id=?
  LIMIT 1
");
$st->execute([$id, $uid]);

json_response(200, ["ok"=>true]);
