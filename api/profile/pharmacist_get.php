<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$userId = (int)$auth["uid"];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// pharmacist only
$stmt = $pdo->prepare("SELECT id, role, full_name, email, phone FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) json_response(404, ["ok"=>false,"error"=>"User not found"]);
if (strtoupper((string)$u["role"]) !== "PHARMACIST") json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);

json_response(200, [
  "ok" => true,
  "data" => [
    "full_name" => (string)($u["full_name"] ?? ""),
    "email"     => (string)($u["email"] ?? ""),
    "phone"     => (string)($u["phone"] ?? "")
  ]
]);
