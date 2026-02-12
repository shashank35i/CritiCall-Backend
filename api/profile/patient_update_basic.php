<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$data = read_json();
require_fields($data, ["full_name","phone"]);

$userId = (int)$auth["uid"];
$full = trim((string)$data["full_name"]);
$phone = trim((string)$data["phone"]);

if ($full === "") {
  json_response(422, ["ok"=>false,"error"=>"Full name is required"]);
}

// allow digits + +, -, spaces => normalize to digits
$digits = preg_replace('/\D+/', '', $phone);
if ($digits === null) $digits = "";

if (strlen($digits) < 10 || strlen($digits) > 15) {
  json_response(422, ["ok"=>false,"error"=>"Invalid phone number"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// must be patient
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

$pdo->prepare("UPDATE users SET full_name=?, phone=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
    ->execute([$full, $digits, $userId]);

json_response(200, [
  "ok" => true,
  "message" => "Profile updated",
  "user" => [
    "full_name" => $full,
    "phone" => $digits
  ]
]);
