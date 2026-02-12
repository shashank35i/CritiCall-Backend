<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(30);
ini_set('default_socket_timeout', '30');

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

  $data = read_json();
  require_fields($data, ["email","otp","new_password"]);

  if (!function_exists("sha256")) { function sha256(string $s): string { return hash("sha256", $s); } }

  $email = normalize_email((string)$data["email"]);
  $otp = preg_replace("/\D+/", "", (string)$data["otp"]);
  $newPass = (string)$data["new_password"];

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Please enter a valid email address."]);
  if (!preg_match('/^\d{6}$/', $otp)) json_response(422, ["ok"=>false,"error"=>"Please enter the 6-digit OTP."]);
  if (strlen($newPass) < 6) json_response(422, ["ok"=>false,"error"=>"Password must be at least 6 characters."]);

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$u) json_response(404, ["ok"=>false,"code"=>"USER_NOT_FOUND","error"=>"We couldn't find an account with this email."]);

  $userId = (int)$u["id"];

  $stmt = $pdo->prepare("
    SELECT id, token_hash, expires_at, attempts, verified_at
    FROM password_resets
    WHERE user_id=? AND used_at IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please resend."]);

  $nowUtc = new DateTime("now", new DateTimeZone("UTC"));
  $expUtc = new DateTime((string)$row["expires_at"], new DateTimeZone("UTC"));
  if ($nowUtc > $expUtc) {
    $pdo->prepare("UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row["id"]]);
    json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please resend."]);
  }

  if ((int)($row["attempts"] ?? 0) >= 5) {
    json_response(429, ["ok"=>false,"code"=>"TOO_MANY_ATTEMPTS","error"=>"Too many attempts. Please resend a new OTP."]);
  }

  if (empty($row["verified_at"])) json_response(403, ["ok"=>false,"code"=>"OTP_NOT_VERIFIED","error"=>"Please verify OTP first."]);

  $otpHash = sha256($otp);
  if (!hash_equals((string)$row["token_hash"], $otpHash)) {
    $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id=?")->execute([(int)$row["id"]]);
    json_response(400, ["ok"=>false,"code"=>"OTP_INVALID","error"=>"That OTP doesn’t match. Please try again."]);
  }

  $newHash = password_hash($newPass, PASSWORD_BCRYPT);

  $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $userId]);
  $pdo->prepare("UPDATE password_resets SET used_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row["id"]]);

  json_response(200, ["ok"=>true,"message"=>"Password updated"]);

} catch (Throwable $e) {
  error_log("reset_password_otp error: " . $e->getMessage());
  json_response(500, ["ok"=>false,"error"=>"Server error"]);
}
