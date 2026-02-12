<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";
require __DIR__ . "/../mailer.php";
require __DIR__ . "/../templates/email_verify_otp.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

//  stable execution settings
@ini_set('default_socket_timeout', '90');
@ini_set('max_execution_time', '90');
@set_time_limit(90);
@ignore_user_abort(true);

$data = read_json();
require_fields($data, ["email","role","full_name","password"]);

if (!function_exists("sha256")) { function sha256(string $s): string { return hash("sha256", $s); } }
if (!function_exists("otp6")) { function otp6(): string { return strval(random_int(100000, 999999)); } }
if (!function_exists("is_valid_role")) {
  function is_valid_role(string $role): bool {
    return in_array($role, ["PATIENT","DOCTOR","PHARMACIST","ADMIN"], true);
  }
}
if (!function_exists("send_mail_unified")) {
  function send_mail_unified(string $toEmail, string $toName, string $subject, string $html): bool {
    try {
      if (function_exists("sendMail")) return (bool)sendMail($toEmail, $toName, $subject, $html);
      if (function_exists("send_email")) return (bool)send_email($toEmail, $toName, $subject, $html);
      return false;
    } catch (Throwable $e) {
      $GLOBALS["MAILER_LAST_ERROR"] = $e->getMessage();
      return false;
    }
  }
}

//  flush helper (so client gets response even if SMTP hangs)
function flush_json_and_continue(array $payload): void {
  header('Content-Type: application/json; charset=utf-8');
  header('Connection: close');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  // disable buffering
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
$role  = strtoupper(trim((string)$data["role"]));
$fullName = trim((string)$data["full_name"]);
$password = (string)$data["password"]; // validate only

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Please enter a valid email address."]);
if (!is_valid_role($role)) json_response(422, ["ok"=>false,"error"=>"Invalid role."]);
if ($fullName === "") json_response(422, ["ok"=>false,"error"=>"Full name required."]);
if (strlen($password) < 6) json_response(422, ["ok"=>false,"error"=>"Password must be at least 6 characters."]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

//  if user exists -> block signup
$stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
  json_response(409, ["ok"=>false,"code"=>"EMAIL_EXISTS","error"=>"This email is already registered. Please sign in instead."]);
}

// pending record
$stmt = $pdo->prepare("SELECT id, last_sent_at FROM signup_pending WHERE email=? AND role=? LIMIT 1");
$stmt->execute([$email, $role]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

// rate limit 30s
if ($p && !empty($p["last_sent_at"])) {
  $last = new DateTime((string)$p["last_sent_at"], new DateTimeZone("UTC"));
  $now  = new DateTime("now", new DateTimeZone("UTC"));
  $diff = $now->getTimestamp() - $last->getTimestamp();
  if ($diff < 30) json_response(429, ["ok"=>false,"code"=>"RATE_LIMIT","error"=>"Please wait ".(30-$diff)."s and try again."]);
}

$otp = otp6();
$otpHash = sha256($otp);
$expiresAt = (new DateTime("now", new DateTimeZone("UTC")))->modify("+10 minutes")->format("Y-m-d H:i:s");

try {
  $pdo->beginTransaction();

  if ($p) {
    $pdo->prepare("
      UPDATE signup_pending
      SET full_name=?,
          otp_hash=?,
          expires_at=?,
          last_sent_at=UTC_TIMESTAMP(),
          send_count=send_count+1,
          attempts=0,
          verified_at=NULL,
          signup_token_hash=NULL,
          signup_token_expires_at=NULL
      WHERE id=?
    ")->execute([$fullName, $otpHash, $expiresAt, (int)$p["id"]]);
  } else {
    $pdo->prepare("
      INSERT INTO signup_pending (email, role, full_name, otp_hash, expires_at, last_sent_at, send_count, attempts)
      VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), 1, 0)
    ")->execute([$email, $role, $fullName, $otpHash, $expiresAt]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not create OTP. Please try again."]);
}

//  respond immediately (NO EOF possible now)
flush_json_and_continue(["ok"=>true, "otp_sent"=>true, "message"=>"We sent a 6-digit code to your email."]);

//  after response: attempt email send
$toName  = $fullName ?: $email;
$subject = "Your SehatSethu verification code";
$html    = email_verify_otp_template($toName ?: "there", $otp);

$sent = send_mail_unified($email, $toName ?: $email, $subject, $html);

// If mail fails, just log; client already got success and can resend
if (!$sent) {
  $err = $GLOBALS["MAILER_LAST_ERROR"] ?? "Mail send failed";
  error_log("OTP MAIL FAILED for $email : $err");
}

//  end request cleanly
exit;
