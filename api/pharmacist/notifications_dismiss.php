<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$uid  = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false,"error"=>"Unauthorized"]);

$data = read_json();
require_fields($data, ["id"]);
$id = (int)$data["id"];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

// dismiss = soft delete using deleted_at
$pdo->prepare("
  UPDATE notifications
  SET deleted_at=NOW()
  WHERE id=? AND user_id=? AND deleted_at IS NULL
")->execute([$id, $uid]);

json_response(200, ["ok"=>true]);
