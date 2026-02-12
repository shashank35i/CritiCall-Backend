<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";
require __DIR__ . "/../mailer.php";
require __DIR__ . "/../templates/email_reset_otp.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

$data = read_json();
require_fields($data, ["email"]);

$email = normalize_email($data["email"]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Invalid email"]);

$pdo = db();
$stmt = $pdo->prepare("SELECT id, full_name, is_verified FROM users WHERE email=?");
$stmt->execute([$email]);
$u = $stmt->fetch();

// Always respond OK (avoid enumeration)
if (!$u || (int)$u["is_verified"] !== 1) {
  json_response(200, ["ok"=>true,"message"=>"If that email exists, a reset code will be sent."]);
}

$userId = (int)$u["id"];
$fullName = (string)$u["full_name"];

$otp = otp6();
$otpHash = sha256($otp);
$expiresAt = (new DateTime("now", new DateTimeZone("UTC")))->modify("+10 minutes")->format("Y-m-d H:i:s");

$pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
$pdo->prepare("INSERT INTO password_resets (user_id, token_hash, otp_length, expires_at, send_count, attempt_count, last_sent_at)
              VALUES (?,?,?,?,1,0,NOW())")
    ->execute([$userId, $otpHash, 6, $expiresAt]);

try {
  $html = email_reset_otp_template($fullName, $otp, 10);
  send_email($email, $fullName, "Your SehatSethu password reset code", $html);
} catch (Exception $e) {}

json_response(200, ["ok"=>true,"message"=>"If that email exists, a reset code will be sent."]);
