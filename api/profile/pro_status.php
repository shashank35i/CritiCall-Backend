<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

$data = read_json();
require_fields($data, ["email","role"]);

$email = normalize_email((string)$data["email"]);
$role = strtoupper(trim((string)$data["role"]));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Invalid email"]);
if (!in_array($role, ["DOCTOR","PHARMACIST"], true)) {
  json_response(422, ["ok"=>false,"error"=>"role must be DOCTOR or PHARMACIST"]);
}

$pdo = db();

$stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) json_response(404, ["ok"=>false,"error"=>"User not found"]);

$userId = (int)$u["id"];

$stmt = $pdo->prepare("SELECT status, rejection_reason FROM professional_verifications WHERE user_id=? LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  json_response(200, ["ok"=>true, "status"=>"PENDING", "rejection_reason"=>null]);
}

json_response(200, [
  "ok"=>true,
  "status"=>$row["status"],
  "rejection_reason"=>$row["rejection_reason"]
]);
