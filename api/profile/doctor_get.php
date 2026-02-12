<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok" => false, "error" => "GET only"]);
}

if (!function_exists("require_auth")) {
  json_response(500, ["ok" => false, "error" => "Auth helpers missing"]);
}

$auth = require_auth();
$uid = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Invalid token"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("
  SELECT u.full_name, u.email, dp.phone
  FROM users u
  LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
  WHERE u.id = ?
  LIMIT 1
");
$stmt->execute([$uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) json_response(404, ["ok"=>false, "error"=>"User not found"]);

json_response(200, [
  "ok" => true,
  "profile" => [
    "full_name" => (string)($row["full_name"] ?? ""),
    "email" => (string)($row["email"] ?? ""),
    "phone" => (string)($row["phone"] ?? "")
  ]
]);
