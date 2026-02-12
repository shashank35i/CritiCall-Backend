<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";
require __DIR__ . "/../mailer.php";
require __DIR__ . "/../templates/email_reset_otp.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

$data = read_json();
require_fields($data, ["email"]);

@ini_set('default_socket_timeout', '90');
@ini_set('max_execution_time', '90');
@set_time_limit(90);
@ignore_user_abort(true);

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

/**
 *  Send response first (prevents "unexpected end of stream")
 * Then continue running to send SMTP after client has response.
 */
function flush_json_and_continue(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  header('Connection: close');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  while (ob_get_level() > 0) { @ob_end_clean(); }
  @ini_set('zlib.output_compression', '0');
  @ini_set('output_buffering', '0');

  $out = json_encode($payload, JSON_UNESCAPED_UNICODE);
  header('Content-Length: ' . strlen($out));

  echo $out;
  @flush();
  @ob_flush();
}

$email = normalize_email((string)$data["email"]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Please enter a valid email address."]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) json_response(404, ["ok"=>false,"code"=>"USER_NOT_FOUND","error"=>"We couldn't find an account with this email."]);

$userId = (int)$u["id"];
$fullName = (string)($u["full_name"] ?? "");

$otp = otp6();
$otpHash = sha256($otp);
$expiresAt = (new DateTime("now", new DateTimeZone("UTC")))->modify("+10 minutes")->format("Y-m-d H:i:s");

$stmt = $pdo->prepare("
  SELECT id, last_sent_at
  FROM password_resets
  WHERE user_id=? AND used_at IS NULL
  ORDER BY id DESC
  LIMIT 1
");
$stmt->execute([$userId]);
$pr = $stmt->fetch(PDO::FETCH_ASSOC);

// Rate limit: 30 sec
if ($pr && !empty($pr["last_sent_at"])) {
  $last = new DateTime((string)$pr["last_sent_at"], new DateTimeZone("UTC"));
  $now  = new DateTime("now", new DateTimeZone("UTC"));
  $diff = $now->getTimestamp() - $last->getTimestamp();
  if ($diff < 30) json_response(429, ["ok"=>false,"code"=>"RATE_LIMIT","error"=>"Please wait ".(30-$diff)."s and try again."]);
}

try {
  $pdo->beginTransaction();

  if ($pr) {
    $pdo->prepare("
      UPDATE password_resets
      SET token_hash=?, expires_at=?, last_sent_at=UTC_TIMESTAMP(),
          send_count=send_count+1, attempts=0, verified_at=NULL
      WHERE id=?
    ")->execute([$otpHash, $expiresAt, (int)$pr["id"]]);
  } else {
    $pdo->prepare("
      INSERT INTO password_resets (user_id, token_hash, expires_at, last_sent_at, send_count, attempts, verified_at)
      VALUES (?, ?, ?, UTC_TIMESTAMP(), 1, 0, NULL)
    ")->execute([$userId, $otpHash, $expiresAt]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not create OTP. Please try again."]);
}

/**
 *  IMPORTANT: respond immediately so the app never waits for SMTP
 */
flush_json_and_continue(["ok"=>true, "message"=>"OTP sent"]);

// ---- after response: send email ----
$toName  = $fullName ?: $email;
$subject = "Your SehatSethu password reset code";
$html    = email_reset_otp_template($toName, $otp);

$sent = send_mail_unified($email, $toName, $subject, $html);

if (!$sent) {
  // Do NOT break client response; just log.
  $err = $GLOBALS["MAILER_LAST_ERROR"] ?? "Mail send failed";
  error_log("RESET OTP MAIL FAILED for $email : $err");
}

exit;
