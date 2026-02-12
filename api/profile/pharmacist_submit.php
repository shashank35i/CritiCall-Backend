<?php
// /api/profile/pharmacist_submit.php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok" => false, "error" => "POST only"]);
}

function allowed_mime(string $m): bool {
  $m = strtolower(trim($m));
  return in_array($m, ["application/pdf", "image/jpeg", "image/jpg", "image/png"], true);
}

function safe_ext_from_mime(string $m): string {
  $m = strtolower(trim($m));
  if ($m === "application/pdf") return "pdf";
  if ($m === "image/png") return "png";
  return "jpg";
}

function gen_application_no(string $prefix, string $dateYmd, int $id): string {
  return sprintf("%s-%s-%06d", $prefix, $dateYmd, $id);
}

$auth = require_auth(); // helpers.php (Bearer extraction + JWT)
$data = read_json();

// ✅ required fields (lat/lng optional)
require_fields($data, ["full_name","phone","pharmacy_name","drug_license_no","village_town","documents"]);

$userId = (int)($auth["uid"] ?? 0);
if ($userId <= 0) {
  json_response(401, ["ok" => false, "error" => "Unauthorized"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// must be pharmacist
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok" => false, "error" => "Pharmacist only"]);
}

// --------- Normalize + validate fields ----------
$full     = trim((string)($data["full_name"] ?? ""));
$phoneRaw = trim((string)($data["phone"] ?? ""));
$pharmacy = trim((string)($data["pharmacy_name"] ?? ""));
$lic      = trim((string)($data["drug_license_no"] ?? ""));
$village  = trim((string)($data["village_town"] ?? ""));

// Accept both "full_address" and "fullAddress"
$addr = null;
if (isset($data["full_address"])) $addr = trim((string)$data["full_address"]);
if ($addr === null && isset($data["fullAddress"])) $addr = trim((string)$data["fullAddress"]);
if ($addr !== null && $addr === "") $addr = null;

// ✅ latitude/longitude (optional)
$lat = null;
$lng = null;
if (isset($data["latitude"]) && is_numeric($data["latitude"])) $lat = (float)$data["latitude"];
if (isset($data["longitude"]) && is_numeric($data["longitude"])) $lng = (float)$data["longitude"];
if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
if ($lng !== null && ($lng < -180 || $lng > 180)) $lng = null;

// normalize phone: keep digits + optional leading +
$phone = preg_replace("/[^0-9+]/", "", $phoneRaw);
$digitsOnly = preg_replace("/[^0-9]/", "", $phone);

if ($full === "" || $phone === "" || $pharmacy === "" || $lic === "" || $village === "") {
  json_response(422, ["ok" => false, "error" => "Please fill all required fields."]);
}
if (strlen($digitsOnly) < 7 || strlen($digitsOnly) > 15) {
  json_response(422, ["ok" => false, "error" => "Please enter a valid phone number."]);
}

// documents must be an array
$docs = $data["documents"];
if (!is_array($docs)) {
  json_response(422, ["ok" => false, "error" => "Invalid documents format"]);
}

$MIN_DOCS = 1;
$MAX_DOCS = 3;

$c = count($docs);
if ($c < $MIN_DOCS) json_response(422, ["ok" => false, "error" => "Please upload at least {$MIN_DOCS} document"]);
if ($c > $MAX_DOCS) json_response(422, ["ok" => false, "error" => "You can upload only {$MAX_DOCS} documents"]);

$maxBytes = 5 * 1024 * 1024;

// validate each doc payload quickly before transaction
foreach ($docs as $d) {
  if (!is_array($d)) json_response(422, ["ok" => false, "error" => "Invalid documents format"]);
  $mime = strtolower(trim((string)($d["mime_type"] ?? "")));
  $b64  = (string)($d["file_base64"] ?? "");
  if ($mime === "" || $b64 === "") json_response(422, ["ok" => false, "error" => "Invalid document payload"]);
  if (!allowed_mime($mime)) json_response(422, ["ok" => false, "error" => "Invalid mime_type: " . $mime]);
  if (strlen($b64) < 20) json_response(422, ["ok" => false, "error" => "Invalid document data"]);
}

$uploaded = [];

try {
  $pdo->beginTransaction();

  // ✅ Upsert pharmacist profile (includes latitude/longitude)
  // NOTE: Ensure pharmacist_profiles has columns latitude, longitude (DECIMAL(10,7) NULL)
  $pdo->prepare("
    INSERT INTO pharmacist_profiles (user_id, pharmacy_name, drug_license_no, village_town, full_address, latitude, longitude)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      pharmacy_name=VALUES(pharmacy_name),
      drug_license_no=VALUES(drug_license_no),
      village_town=VALUES(village_town),
      full_address=VALUES(full_address),
      latitude=VALUES(latitude),
      longitude=VALUES(longitude),
      updated_at=UTC_TIMESTAMP()
  ")->execute([$userId, $pharmacy, $lic, $village, $addr, $lat, $lng]);

  // ✅ Save phone into users.phone
  $pdo->prepare("
    UPDATE users
    SET full_name = ?,
        phone = ?,
        updated_at = UTC_TIMESTAMP()
    WHERE id = ?
  ")->execute([$full, $phone, $userId]);

  // Mark profile completion + verification status
  $pdo->prepare("
    UPDATE users
    SET profile_completed=1,
        profile_submitted_at=UTC_TIMESTAMP(),
        admin_verification_status='UNDER_REVIEW',
        admin_rejection_reason=NULL
    WHERE id=?
  ")->execute([$userId]);

  // professional_verifications upsert
  $pdo->prepare("
    INSERT INTO professional_verifications (user_id, role, status, submitted_at)
    VALUES (?, 'PHARMACIST', 'UNDER_REVIEW', UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE
      role='PHARMACIST',
      status='UNDER_REVIEW',
      submitted_at=UTC_TIMESTAMP(),
      rejection_reason=NULL
  ")->execute([$userId]);

  // Ensure application_no exists
  $s = $pdo->prepare("SELECT id, application_no FROM professional_verifications WHERE user_id=? LIMIT 1");
  $s->execute([$userId]);
  $pv = $s->fetch(PDO::FETCH_ASSOC);
  if (!$pv) throw new Exception("PV missing");

  $pvId = (int)$pv["id"];
  $appNo = (string)($pv["application_no"] ?? "");

  if ($appNo === "") {
    $newAppNo = gen_application_no("PHA", gmdate("Ymd"), $pvId);
    $pdo->prepare("UPDATE professional_verifications SET application_no=? WHERE id=?")->execute([$newAppNo, $pvId]);
    $appNo = $newAppNo;
  }

  // Upload dir
  $projectRoot = realpath(__DIR__ . "/../..");
  if (!$projectRoot) throw new Exception("Server path error");

  $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads"
    . DIRECTORY_SEPARATOR . "pharmacists"
    . DIRECTORY_SEPARATOR . $userId;

  if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
  }

  // Replace documents (simple + clean)
  $pdo->prepare("DELETE FROM pharmacist_documents WHERE user_id=?")->execute([$userId]);

  foreach ($docs as $i => $d) {
    $mime   = strtolower(trim((string)$d["mime_type"]));
    $nameIn = trim((string)($d["file_name"] ?? ""));
    $b64    = (string)$d["file_base64"];

    $bin = base64_decode($b64, true);
    if ($bin === false) json_response(422, ["ok" => false, "error" => "Invalid base64 for document " . ($i + 1)]);

    $size = strlen($bin);
    if ($size <= 0 || $size > $maxBytes) {
      json_response(422, ["ok" => false, "error" => "File too large (max 5MB) for document " . ($i + 1)]);
    }

    $ext = safe_ext_from_mime($mime);
    $safeName = preg_replace("/[^a-zA-Z0-9._-]+/", "_", $nameIn);
    if ($safeName === "" || strlen($safeName) < 3) $safeName = "document_" . ($i + 1) . "." . $ext;

    $rand = bin2hex(random_bytes(6));
    $finalName = "doc_" . ($i + 1) . "_" . gmdate("Ymd_His") . "_" . $rand . "." . $ext;

    $path = $uploadDir . DIRECTORY_SEPARATOR . $finalName;
    if (file_put_contents($path, $bin) === false) {
      json_response(500, ["ok" => false, "error" => "Failed to save document " . ($i + 1)]);
    }

    $publicPath = "/sehatsethu_api/uploads/pharmacists/" . $userId . "/" . $finalName;

    $pdo->prepare("
      INSERT INTO pharmacist_documents (user_id, doc_index, file_url, file_name, mime_type, file_size)
      VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$userId, (int)($i + 1), $publicPath, $safeName, $mime, $size]);

    $uploaded[] = ["doc_index" => ($i + 1), "file_url" => $publicPath, "file_name" => $safeName];
  }

  $pdo->commit();

  json_response(200, [
    "ok" => true,
    "message" => "Profile submitted",
    "status" => "UNDER_REVIEW",
    "application_no" => $appNo,
    "uploaded_documents" => $uploaded,
    "location_saved" => ($lat !== null && $lng !== null)
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok" => false, "error" => "Could not save pharmacist profile"]);
}
