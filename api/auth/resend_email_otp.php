<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";
require __DIR__ . "/../mailer.php";
require __DIR__ . "/../templates/email_verify_otp.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

$data = read_json();
require_fields($data, ["email"]);

if (!function_exists("otp6")) { function otp6(): string { return strval(random_int(100000, 999999)); } }
if (!function_exists("sha256")) { function sha256(string $s): string { return hash("sha256", $s); } }
if (!function_exists("send_mail_unified")) {
  function send_mail_unified(string $toEmail, string $toName, string $subject, string $html): bool {
    try {
      if (function_exists("sendMail")) return (bool)sendMail($toEmail, $toName, $subject, $html);
      if (function_exists("send_email")) return (bool)send_email($toEmail, $toName, $subject, $html);
      return false;
    } catch (Throwable $e) { $GLOBALS["MAILER_LAST_ERROR"] = $e->getMessage(); return false; }
  }
}

$email = normalize_email((string)$data["email"]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Please enter a valid email address."]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("SELECT id, full_name, is_verified FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) json_response(404, ["ok"=>false,"code"=>"USER_NOT_FOUND","error"=>"No account found for this email. Please create an account first."]);
if ((int)$u["is_verified"] === 1) json_response(409, ["ok"=>false,"code"=>"ALREADY_VERIFIED","error"=>"This email is already verified. Please sign in."]);

$userId = (int)$u["id"];
$fullName = (string)($u["full_name"] ?? "");

$otp = otp6();
$otpHash = sha256($otp);
$expiresAt = (new DateTime("now", new DateTimeZone("UTC")))->modify("+10 minutes")->format("Y-m-d H:i:s");

$stmt = $pdo->prepare("
  SELECT id, last_sent_at
  FROM email_verifications
  WHERE user_id=? AND used_at IS NULL
  ORDER BY id DESC
  LIMIT 1
");
$stmt->execute([$userId]);
$ev = $stmt->fetch(PDO::FETCH_ASSOC);

if ($ev && !empty($ev["last_sent_at"])) {
  $last = new DateTime((string)$ev["last_sent_at"], new DateTimeZone("UTC"));
  $now  = new DateTime("now", new DateTimeZone("UTC"));
  $diff = $now->getTimestamp() - $last->getTimestamp();
  if ($diff < 30) json_response(429, ["ok"=>false,"code"=>"RATE_LIMIT","error"=>"Please wait ".(30-$diff)."s and try again."]);
}

try {
  $pdo->beginTransaction();

  if ($ev) {
    $pdo->prepare("
      UPDATE email_verifications
      SET token_hash=?, expires_at=?, last_sent_at=UTC_TIMESTAMP(), send_count=send_count+1, attempts=0
      WHERE id=?
    ")->execute([$otpHash, $expiresAt, (int)$ev["id"]]);
  } else {
    $pdo->prepare("
      INSERT INTO email_verifications (user_id, token_hash, expires_at, last_sent_at, send_count, attempts)
      VALUES (?, ?, ?, UTC_TIMESTAMP(), 1, 0)
    ")->execute([$userId, $otpHash, $expiresAt]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not create OTP. Please try again."]);
}

$toName  = $fullName ?: $email;
$subject = "Your SehatSethu verification code";
$html    = email_verify_otp_template($toName, $otp);

$sent = send_mail_unified($email, $toName, $subject, $html);

if (!$sent) {
  try { $pdo->prepare("UPDATE email_verifications SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL")->execute([$userId]); } catch (Throwable $e) {}
  $err = $GLOBALS["MAILER_LAST_ERROR"] ?? "Mail send failed";
  json_response(500, ["ok"=>false,"code"=>"MAIL_FAILED","error"=>"We couldn't send the OTP email right now. Please try again.","debug"=>$err]);
}

json_response(200, ["ok"=>true,"message"=>"OTP sent"]);
