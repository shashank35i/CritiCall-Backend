<?php
//patient_submit.php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$data = read_json();

// keep existing required fields (do NOT break old clients)
require_fields($data, ["full_name","gender","age"]);

// ✅ accept either village_town OR village
$villageTown = "";
if (isset($data["village_town"])) $villageTown = trim((string)$data["village_town"]);
else if (isset($data["village"])) $villageTown = trim((string)$data["village"]);

if ($villageTown === "") {
  json_response(422, ["ok"=>false,"error"=>"Village/Town is required"]);
}

$userId = (int)($auth["uid"] ?? 0);
if ($userId <= 0) json_response(401, ["ok"=>false,"error"=>"Invalid token"]);

$full = trim((string)$data["full_name"]);
$gender = strtoupper(trim((string)$data["gender"]));
$age = (int)($data["age"] ?? 0);

$district = isset($data["district"]) ? trim((string)$data["district"]) : null;
if ($district === "") $district = null;

// ✅ NEW: phone (optional, but we will save when present)
$phone = isset($data["phone"]) ? trim((string)$data["phone"]) : null;
if ($phone === "") $phone = null;

// optional: normalize phone to a safe stored format (keeps +, digits)
if ($phone !== null) {
  $phone = preg_replace('/[^\d+]/', '', $phone);
  if ($phone === "") $phone = null;
}

if ($full === "" || $age <= 0) {
  json_response(422, ["ok"=>false,"error"=>"Please fill all required fields."]);
}
if (!in_array($gender, ["MALE","FEMALE","OTHER"], true)) {
  json_response(422, ["ok"=>false,"error"=>"Invalid gender"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// must be patient
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

try {
  $pdo->beginTransaction();

  // upsert patient profile
  $pdo->prepare("
    INSERT INTO patient_profiles (user_id, gender, age, village_town, district)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      gender=VALUES(gender),
      age=VALUES(age),
      village_town=VALUES(village_town),
      district=VALUES(district),
      updated_at=CURRENT_TIMESTAMP
  ")->execute([$userId, $gender, $age, $villageTown, $district]);

  // ✅ Update users (full_name + phone + gates)
  $pdo->prepare("
    UPDATE users
    SET full_name=?,
        phone=?,
        profile_completed=1,
        profile_submitted_at=UTC_TIMESTAMP(),
        admin_verification_status='VERIFIED',
        admin_verified_by=NULL,
        admin_verified_at=UTC_TIMESTAMP(),
        admin_rejection_reason=NULL
    WHERE id=?
  ")->execute([$full, $phone, $userId]);

  $pdo->commit();

  json_response(200, [
    "ok"=>true,
    "message"=>"Profile saved",
    "profile_completed"=>1,
    "admin_verification_status"=>"VERIFIED"
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not save patient profile"]);
}
