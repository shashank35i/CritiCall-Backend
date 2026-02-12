<?php
require __DIR__ . "/../config.php";
require __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

//  never hang forever (safe)
set_time_limit(20);
ini_set('default_socket_timeout', '20');

$data = read_json();
require_fields($data, ["email","otp"]); // role is OPTIONAL (required only for signup-pending flow)

if (!function_exists("sha256")) { function sha256(string $s): string { return hash("sha256", $s); } }
if (!function_exists("is_valid_role")) {
  function is_valid_role(string $role): bool {
    return in_array($role, ["PATIENT","DOCTOR","PHARMACIST","ADMIN"], true);
  }
}

$email = normalize_email((string)$data["email"]);
$otp = preg_replace("/\D+/", "", (string)$data["otp"]);
$role = strtoupper(trim((string)($data["role"] ?? ""))); // optional

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Please enter a valid email address."]);
if (!preg_match('/^\d{6}$/', $otp)) json_response(422, ["ok"=>false,"error"=>"Please enter the 6-digit OTP."]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// -------------------------------
// MODE A: Existing flow (users + email_verifications)
// If user exists -> verify there (NO CHANGE)
// -------------------------------
$stmt = $pdo->prepare("SELECT id, is_verified FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if ($u) {
  $userId = (int)$u["id"];
  if ((int)$u["is_verified"] === 1) {
    json_response(200, ["ok"=>true,"message"=>"Email already verified."]);
  }

  $stmt = $pdo->prepare("
    SELECT id, token_hash, expires_at, attempts
    FROM email_verifications
    WHERE user_id=? AND used_at IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please tap Resend OTP."]);

  $nowUtc = new DateTime("now", new DateTimeZone("UTC"));
  $expUtc = new DateTime((string)$row["expires_at"], new DateTimeZone("UTC"));
  if ($nowUtc > $expUtc) {
    $pdo->prepare("UPDATE email_verifications SET used_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row["id"]]);
    json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please tap Resend OTP."]);
  }

  if ((int)($row["attempts"] ?? 0) >= 5) {
    json_response(429, ["ok"=>false,"code"=>"TOO_MANY_ATTEMPTS","error"=>"Too many attempts. Please request a new OTP."]);
  }

  $otpHash = sha256($otp);
  if (!hash_equals((string)$row["token_hash"], $otpHash)) {
    $pdo->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE id=?")->execute([(int)$row["id"]]);
    json_response(400, ["ok"=>false,"code"=>"OTP_INVALID","error"=>"That OTP doesn’t match. Please try again."]);
  }

  $pdo->prepare("UPDATE email_verifications SET used_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$row["id"]]);
  $pdo->prepare("UPDATE users SET is_verified=1 WHERE id=?")->execute([$userId]);

  json_response(200, ["ok"=>true,"message"=>"Email verified"]);
}

// -------------------------------
// MODE B: Signup pending (NO user yet) -> verify OTP in signup_pending
// Requires role (NO CHANGE in logic, just safer)
// -------------------------------
if ($role === "" || !is_valid_role($role)) {
  json_response(422, ["ok"=>false, "code"=>"ROLE_REQUIRED", "error"=>"Role is required for signup verification."]);
}

$stmt = $pdo->prepare("SELECT * FROM signup_pending WHERE email=? AND role=? LIMIT 1");
$stmt->execute([$email, $role]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) json_response(404, ["ok"=>false,"error"=>"No pending signup found. Please tap Send OTP again."]);

if ((int)($p["attempts"] ?? 0) >= 5) {
  json_response(429, ["ok"=>false,"code"=>"TOO_MANY_ATTEMPTS","error"=>"Too many attempts. Please resend OTP."]);
}

if (empty($p["expires_at"]) || empty($p["otp_hash"])) {
  json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please tap Resend OTP."]);
}

$nowUtc = new DateTime("now", new DateTimeZone("UTC"));
$expUtc = new DateTime((string)$p["expires_at"], new DateTimeZone("UTC"));
if ($nowUtc > $expUtc) {
  json_response(400, ["ok"=>false,"code"=>"OTP_EXPIRED","error"=>"OTP expired. Please tap Resend OTP."]);
}

$otpHash = sha256($otp);
if (!hash_equals((string)$p["otp_hash"], $otpHash)) {
  $pdo->prepare("UPDATE signup_pending SET attempts = attempts + 1 WHERE id=?")->execute([(int)$p["id"]]);
  json_response(400, ["ok"=>false,"code"=>"OTP_INVALID","error"=>"That OTP doesn’t match. Please try again."]);
}

//  Create signup token
$signupToken = bin2hex(random_bytes(16));
$signupTokenHash = sha256($signupToken);
$tokenExp = (new DateTime("now", new DateTimeZone("UTC")))->modify("+15 minutes")->format("Y-m-d H:i:s");

$pdo->prepare("
  UPDATE signup_pending
  SET verified_at=UTC_TIMESTAMP(),
      signup_token_hash=?,
      signup_token_expires_at=?,
      attempts=0
  WHERE id=?
")->execute([$signupTokenHash, $tokenExp, (int)$p["id"]]);

json_response(200, ["ok"=>true, "signup_token"=>$signupToken, "message"=>"Email verified"]);
