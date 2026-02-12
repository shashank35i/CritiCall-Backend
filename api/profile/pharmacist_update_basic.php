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
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// pharmacist only
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

$full = trim((string)$data["full_name"]);
$phoneRaw = trim((string)$data["phone"]);

if ($full === "") json_response(422, ["ok"=>false,"error"=>"Full name is required."]);

// normalize phone (digits + optional +)
$phone = preg_replace("/[^0-9+]/", "", $phoneRaw);
$digits = preg_replace("/[^0-9]/", "", $phone);

// allow blank phone if user clears it, else validate
if ($phone !== "") {
  if (strlen($digits) < 7 || strlen($digits) > 15) {
    json_response(422, ["ok"=>false,"error"=>"Please enter a valid phone number."]);
  }
}

$pdo->prepare("
  UPDATE users
  SET full_name=?,
      phone=?,
      updated_at=UTC_TIMESTAMP()
  WHERE id=?
")->execute([$full, $phone, $userId]);

json_response(200, ["ok"=>true, "message"=>"Updated"]);
