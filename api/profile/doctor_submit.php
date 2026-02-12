<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok" => false, "error" => "POST only"]);
}

$auth = require_auth();
$data = read_json();

// REQUIRED KEYS (must match Android JSON keys)
require_fields($data, ["full_name","specialization","registration_no","practice_place","experience_years"]);

$userId = (int)$auth["uid"];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// must be doctor
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "DOCTOR") {
  json_response(403, ["ok"=>false,"error"=>"Doctor only"]);
}

$full  = trim((string)$data["full_name"]);
$spec  = strtoupper(trim((string)$data["specialization"])); // store stable key
$reg   = trim((string)$data["registration_no"]);
$place = trim((string)$data["practice_place"]);
$exp   = (int)$data["experience_years"];
$phone = isset($data["phone"]) ? trim((string)$data["phone"]) : null;
if ($phone === "") $phone = null;

// Optional docs array
$docs = [];
if (isset($data["documents"]) && is_array($data["documents"])) $docs = $data["documents"];

// validate required
if ($full === "" || $spec === "" || $reg === "" || $place === "") {
  json_response(422, ["ok"=>false,"error"=>"Please fill all required fields."]);
}

// ---------- helpers ----------
function allowed_doc_type(string $t): bool {
  return in_array($t, ["MEDICAL_LICENSE","AADHAAR","MBBS_CERT"], true);
}
function allowed_mime(string $m): bool {
  $m = strtolower(trim($m));
  return in_array($m, ["application/pdf","image/jpeg","image/jpg","image/png"], true);
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
function humanize_spec(string $specKey): string {
  $s = strtolower(str_replace("_", " ", trim($specKey)));
  $s = preg_replace('/\s+/', ' ', $s);
  return $s ?: "doctor";
}
function build_about_text(string $fullName, string $specKey, int $expYears): string {
  $name = trim($fullName);
  if ($name === "") $name = "Doctor";

  $specHuman = humanize_spec($specKey);

  $adj = "experienced";
  if ($expYears >= 15) $adj = "highly experienced";
  else if ($expYears >= 8) $adj = "experienced";
  else if ($expYears >= 3) $adj = "skilled";
  else $adj = "dedicated";

  // matches your screenshot style (2 sentences)
  // use neutral pronoun "They" (no gender dependency)
  return $name . " is a " . $adj . " " . $specHuman .
    " with expertise in treating common illnesses, chronic conditions, and preventive healthcare. " .
    "They are known for a patient-friendly approach and thorough consultations.";
}

// validate docs (optional)
$maxBytes = 5 * 1024 * 1024;
foreach ($docs as $d) {
  if (!is_array($d)) json_response(422, ["ok"=>false,"error"=>"Invalid documents format"]);
  $docType = strtoupper(trim((string)($d["doc_type"] ?? "")));
  $mime    = strtolower(trim((string)($d["mime_type"] ?? "")));
  $b64     = (string)($d["file_base64"] ?? "");
  if ($docType === "" || $mime === "" || $b64 === "") json_response(422, ["ok"=>false,"error"=>"Invalid document payload"]);
  if (!allowed_doc_type($docType)) json_response(422, ["ok"=>false,"error"=>"Invalid doc_type: ".$docType]);
  if (!allowed_mime($mime)) json_response(422, ["ok"=>false,"error"=>"Invalid mime_type: ".$mime]);
  if (strlen($b64) < 20) json_response(422, ["ok"=>false,"error"=>"Invalid document data"]);
}

// computed fields (as you requested)
$languagesCsv = "English, Hindi";
$worksAtText  = $place;
$aboutText    = build_about_text($full, $spec, $exp);

try {
  $pdo->beginTransaction();

  // Upsert doctor profile
  $pdo->prepare("
    INSERT INTO doctor_profiles (
      user_id,
      specialization,
      registration_no,
      practice_place,
      works_at_text,
      languages_csv,
      phone,
      experience_years,
      about_text
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      specialization=VALUES(specialization),
      registration_no=VALUES(registration_no),
      practice_place=VALUES(practice_place),
      works_at_text=VALUES(works_at_text),
      languages_csv=VALUES(languages_csv),
      phone=VALUES(phone),
      experience_years=VALUES(experience_years),
      about_text=VALUES(about_text),
      updated_at=CURRENT_TIMESTAMP
  ")->execute([$userId, $spec, $reg, $place, $worksAtText, $languagesCsv, $phone, $exp, $aboutText]);

  // Sync name + phone into users
  if ($phone !== null) {
    $pdo->prepare("UPDATE users SET full_name=?, phone=? WHERE id=?")
        ->execute([$full, $phone, $userId]);
  } else {
    $pdo->prepare("UPDATE users SET full_name=? WHERE id=?")
        ->execute([$full, $userId]);
  }

  // Mark submitted + under review
  $pdo->prepare("
    UPDATE users
    SET profile_completed=1,
        profile_submitted_at=UTC_TIMESTAMP(),
        admin_verification_status='UNDER_REVIEW',
        admin_rejection_reason=NULL
    WHERE id=?
  ")->execute([$userId]);

  // Create/Update professional_verifications
  $pdo->prepare("
    INSERT INTO professional_verifications (user_id, role, status, submitted_at)
    VALUES (?, 'DOCTOR', 'UNDER_REVIEW', UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE
      status='UNDER_REVIEW',
      submitted_at=UTC_TIMESTAMP(),
      rejection_reason=NULL
  ")->execute([$userId]);

  // Read pv id + app no
  $s = $pdo->prepare("SELECT id, application_no FROM professional_verifications WHERE user_id=? LIMIT 1");
  $s->execute([$userId]);
  $pv = $s->fetch(PDO::FETCH_ASSOC);
  $pvId = (int)$pv["id"];
  $appNo = (string)($pv["application_no"] ?? "");

  if ($appNo === "") {
    $newAppNo = gen_application_no("DOC", gmdate("Ymd"), $pvId);
    $pdo->prepare("UPDATE professional_verifications SET application_no=? WHERE id=?")
        ->execute([$newAppNo, $pvId]);
    $appNo = $newAppNo;
  }

  // Save optional docs
  $uploaded = [];
  if (!empty($docs)) {
    $projectRoot = realpath(__DIR__ . "/../.."); // .../sehatsethu_api
    if (!$projectRoot) json_response(500, ["ok"=>false,"error"=>"Server path error"]);

    $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads"
      . DIRECTORY_SEPARATOR . "doctors"
      . DIRECTORY_SEPARATOR . $userId;

    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

    foreach ($docs as $d) {
      $docType = strtoupper(trim((string)$d["doc_type"]));
      $mime    = strtolower(trim((string)$d["mime_type"]));
      $nameIn  = trim((string)($d["file_name"] ?? ""));
      $b64     = (string)$d["file_base64"];

      $bin = base64_decode($b64, true);
      if ($bin === false) json_response(422, ["ok"=>false,"error"=>"Invalid base64 for ".$docType]);

      $size = strlen($bin);
      if ($size <= 0 || $size > $maxBytes) json_response(422, ["ok"=>false,"error"=>"File too large (max 5MB) for ".$docType]);

      $ext = safe_ext_from_mime($mime);
      $safeName = preg_replace("/[^a-zA-Z0-9._-]+/", "_", $nameIn);
      if ($safeName === "" || strlen($safeName) < 3) $safeName = strtolower($docType) . "." . $ext;

      $rand = bin2hex(random_bytes(6));
      $finalName = strtolower($docType) . "_" . gmdate("Ymd_His") . "_" . $rand . "." . $ext;

      $path = $uploadDir . DIRECTORY_SEPARATOR . $finalName;
      if (file_put_contents($path, $bin) === false) {
        json_response(500, ["ok"=>false,"error"=>"Failed to save ".$docType]);
      }

      $publicPath = "/sehatsethu_api/uploads/doctors/" . $userId . "/" . $finalName;

      $pdo->prepare("
        INSERT INTO doctor_documents (user_id, doc_type, file_url, file_name, mime_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          file_url=VALUES(file_url),
          file_name=VALUES(file_name),
          mime_type=VALUES(mime_type),
          file_size=VALUES(file_size),
          updated_at=CURRENT_TIMESTAMP
      ")->execute([$userId, $docType, $publicPath, $safeName, $mime, $size]);

      $uploaded[] = ["doc_type"=>$docType, "file_url"=>$publicPath, "file_name"=>$safeName];
    }
  }

  $pdo->commit();

  json_response(200, [
    "ok"=>true,
    "message"=>"Profile submitted",
    "status"=>"UNDER_REVIEW",
    "application_no"=>$appNo,
    "computed" => [
      "languages_csv" => $languagesCsv,
      "works_at_text" => $worksAtText,
      "about_text" => $aboutText
    ],
    "uploaded_documents"=>$uploaded
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Failed to submit profile"]);
}
