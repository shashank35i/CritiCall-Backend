<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$auth = require_auth();
$uid = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);

$q = read_json();
$unreadOnly = (int)($q["unread_only"] ?? 0);
$limit = (int)($q["limit"] ?? 100);
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;

$pdo = db();

$sql = "
  SELECT id, title, body, is_read, created_at
  FROM notifications
  WHERE user_id=?
";
$params = [$uid];

if ($unreadOnly === 1) {
  $sql .= " AND is_read=0";
}

$sql .= " ORDER BY id DESC LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_response(200, ["ok"=>true, "data"=>["items"=>$rows]]);
