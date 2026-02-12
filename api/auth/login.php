<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok" => false, "error" => "POST only"]);
}

$data = read_json();
require_fields($data, ["email", "password", "role"]);

$email = normalize_email((string)($data["email"] ?? ""));
$password = (string)($data["password"] ?? "");
$role = strtoupper(trim((string)($data["role"] ?? "")));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  json_response(422, ["ok" => false, "error" => "Invalid email"]);
}
if (!is_valid_role($role)) {
  json_response(422, ["ok" => false, "error" => "Invalid role"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * ✅ Change: fetch by email ONLY (email is unique in your schema)
 * so we can detect role mismatch even when password is wrong.
 */
$stmt = $pdo->prepare("
  SELECT id, full_name, email, password_hash, role, is_verified,
         COALESCE(profile_completed,0) AS profile_completed,
         COALESCE(admin_verification_status,'PENDING') AS admin_verification_status,
         admin_rejection_reason
  FROM users
  WHERE email=? AND is_active=1
  LIMIT 1
");
$stmt->execute([$email]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
  json_response(404, [
    "ok" => false,
    "code" => "USER_NOT_FOUND",
    "error" => "No user found with this email."
  ]);
}

$dbRole = strtoupper((string)$u["role"]);

/**
 * ✅ NEW RULE (as you requested):
 * If email exists but selected role differs -> ROLE_MISMATCH
 * EVEN IF PASSWORD IS WRONG.
 */
if ($dbRole !== $role) {
  json_response(409, [
    "ok" => false,
    "code" => "ROLE_MISMATCH",
    "error" => "This email is registered as $dbRole. Please switch role and try again.",
    "expected_role" => $dbRole,
    "selected_role" => $role
  ]);
}

/**
 * Now role matches -> check password
 */
if (!password_verify($password, (string)$u["password_hash"])) {
  json_response(401, [
    "ok" => false,
    "code" => "INVALID_CREDENTIALS",
    "error" => "Invalid email or password."
  ]);
}

if ((int)$u["is_verified"] !== 1) {
  json_response(403, [
    "ok" => false,
    "code" => "EMAIL_NOT_VERIFIED",
    "error" => "Please verify your email first."
  ]);
}

$userId = (int)$u["id"];

// professional status from table (doctor/pharmacist)
$proStatus = null;
$proReason = null;
if ($dbRole === "DOCTOR" || $dbRole === "PHARMACIST") {
  $s2 = $pdo->prepare("SELECT status, rejection_reason FROM professional_verifications WHERE user_id=? LIMIT 1");
  $s2->execute([$userId]);
  $pv = $s2->fetch(PDO::FETCH_ASSOC);

  // If row missing, treat as PENDING (created after signup)
  $proStatus = $pv ? (string)$pv["status"] : "PENDING";
  $proReason = $pv ? ($pv["rejection_reason"] ?? null) : null;
}

/**
 * ✅ JWT generator (NO library needed)
 */
if (!function_exists("jwt_issue")) {
  function jwt_issue(array $claims, int $ttlSeconds): string {
    $cfg = null;
    $cfgPath = __DIR__ . "/../config.php";
    if (file_exists($cfgPath)) $cfg = require $cfgPath;

    $secret = "";
    if (is_array($cfg) && isset($cfg["jwt"]["secret"])) {
      $secret = (string)$cfg["jwt"]["secret"];
    }
    if ($secret === "") $secret = (string)($_ENV["JWT_SECRET"] ?? "");
    if ($secret === "") $secret = (string)(getenv("JWT_SECRET") ?: "");

    if (trim($secret) === "") {
      json_response(500, ["ok" => false, "error" => "JWT_SECRET missing on server. Check config.php jwt.secret"]);
    }

    $header = ["alg" => "HS256", "typ" => "JWT"];
    $now = time();

    $payload = array_merge($claims, [
      "iat" => $now,
      "exp" => $now + $ttlSeconds,
    ]);

    $b64url = function (string $data): string {
      return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
    };

    $h = $b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = $b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac("sha256", "$h.$p", $secret, true);
    $s = $b64url($sig);

    return "$h.$p.$s";
  }
}

// ✅ issue token
$token = jwt_issue(["uid" => $userId, "role" => $dbRole, "email" => $email], 60 * 60 * 24);

// Update last login
$pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$userId]);

/**
 * ✅ END-TO-END SAFE RESPONSE (Android gating compatible)
 */
json_response(200, [
  "ok" => true,
  "token" => $token,
  "user" => [
    "id" => $userId,
    "full_name" => $u["full_name"],
    "email" => $u["email"],
    "role" => $u["role"],
    "is_verified" => (int)$u["is_verified"],

    "profile_completed" => (int)$u["profile_completed"],

    "admin_verification_status" => (string)$u["admin_verification_status"],

    // Android expects this key
    "admin_verification_reason" => $u["admin_rejection_reason"] ?? null,

    // keep old key too
    "admin_rejection_reason" => $u["admin_rejection_reason"] ?? null,

    // professional verification
    "professional_status" => $proStatus,
    "professional_verification_status" => $proStatus,

    // reasons (aliases)
    "professional_verification_reason" => $proReason,
    "professional_rejection_reason" => $proReason,
  ]
]);
