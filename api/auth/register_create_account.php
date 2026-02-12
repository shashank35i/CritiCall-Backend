<?php
declare(strict_types=1);

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

/**
 * Only POST allowed
 */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    json_response(405, ["ok" => false, "error" => "POST only"]);
}

set_time_limit(20);
ini_set("default_socket_timeout", "20");

/**
 * Read & validate input
 */
$data = read_json();
require_fields($data, ["full_name", "email", "password", "role"]);

$fullName = trim((string)$data["full_name"]);
$email    = normalize_email((string)$data["email"]);
$password = (string)$data["password"];
$role     = strtoupper(trim((string)$data["role"]));

if ($fullName === "") {
    json_response(422, ["ok" => false, "error" => "Full name required"]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(422, ["ok" => false, "error" => "Invalid email"]);
}

if (!is_valid_role($role)) {
    json_response(422, ["ok" => false, "error" => "Invalid role"]);
}

if (strlen($password) < 6) {
    json_response(422, ["ok" => false, "error" => "Password must be at least 6 characters"]);
}

/**
 * DB connection
 */
try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("[register] DB connection failed: " . $e->getMessage());
    json_response(503, ["ok" => false, "error" => "Database unavailable"]);
}

/**
 * Check if email already exists
 */
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);

if ($stmt->fetch()) {
    json_response(409, [
        "ok"   => false,
        "code" => "EMAIL_EXISTS",
        "error"=> "Email already registered"
    ]);
}

/**
 * Business rules
 * - Doctor / Pharmacist → PENDING
 * - Others → VERIFIED
 */
$adminStatus     = in_array($role, ["DOCTOR", "PHARMACIST"], true) ? "PENDING" : "VERIFIED";
$profileCompleted = 0;

try {
    $pdo->beginTransaction();

    /**
     * Create user
     */
    $stmt = $pdo->prepare("
        INSERT INTO users (
            full_name,
            email,
            password_hash,
            role,
            is_verified,
            admin_verification_status,
            profile_completed
        ) VALUES (?, ?, ?, ?, 1, ?, ?)
    ");

    $stmt->execute([
        $fullName,
        $email,
        password_hash($password, PASSWORD_BCRYPT),
        $role,
        $adminStatus,
        $profileCompleted
    ]);

    $userId = (int)$pdo->lastInsertId();

    /**
     * Create professional verification record (if required)
     */
    if (in_array($role, ["DOCTOR", "PHARMACIST"], true)) {
        $stmt = $pdo->prepare("
            INSERT INTO professional_verifications (user_id, role, status)
            VALUES (?, ?, 'PENDING')
        ");
        $stmt->execute([$userId, $role]);
    }

    /**
     * Cleanup pending signup
     */
    $stmt = $pdo->prepare("
        DELETE FROM signup_pending
        WHERE email = ? AND role = ?
    ");
    $stmt->execute([$email, $role]);

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $traceId = bin2hex(random_bytes(8));
    error_log("[register_create_account] trace=$traceId message=".$e->getMessage());
    json_response(500, [
        "ok" => false,
        "error" => "Could not create account",
        "trace_id" => $traceId,
        "debug" => $e->getMessage()   // <-- temporarily show error
    ]);
}


/**
 * Success
 */
json_response(200, [
    "ok"                        => true,
    "message"                   => "Account created successfully",
    "admin_verification_status" => $adminStatus,
    "profile_completed"         => $profileCompleted
]);
