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
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") json_response(405, ["ok"=>false, "error"=>"GET only"]);

$unreadOnly = (int)($_GET["unread"] ?? 0) === 1;
$limit = (int)($_GET["limit"] ?? 100);
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;

$hasDataJson = false;
try {
  $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'data_json'")->fetch(PDO::FETCH_ASSOC);
  $hasDataJson = !!$col;
} catch (Throwable $e) {}

$select = $hasDataJson
  ? "id, user_id, title, body, created_at, is_read, data_json"
  : "id, user_id, title, body, created_at, is_read";

$where = "user_id=? AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00')";
$params = [$uid];

if ($unreadOnly) $where .= " AND is_read=0";

$sql = "SELECT $select FROM notifications WHERE $where ORDER BY created_at DESC LIMIT $limit";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

json_response(200, ["ok"=>true, "data"=>$rows]);
