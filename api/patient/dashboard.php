<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
  json_response(405, ["ok" => false, "error" => "GET only"]);
}

$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok" => false, "error" => "Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok" => false, "error" => "Patient only"]);

try {
  $stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$uid]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    json_response(404, ["ok" => false, "error" => "User not found"]);
  }

  $full = trim((string)($u["full_name"] ?? ""));
  $email = $u["email"] ?? null;
  $phone = $u["phone"] ?? null;

  json_response(200, [
    "ok" => true,
    "data" => [
      "user" => [
        "id" => (int)$u["id"],
        "full_name" => $full,
        "email" => $email,
        "phone" => $phone,
        "role" => (string)($u["role"] ?? "PATIENT")
      ]
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, ["ok" => false, "error" => "Server error"]);
}
