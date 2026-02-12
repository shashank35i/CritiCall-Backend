<!--doctors_by_speciality.php-->
<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo = db();

/**
 * Read "speciality" from:
 * 1) JSON body
 * 2) form body (POST)
 * 3) query string (GET)
 */
$q = [];
try { $q = read_json(); } catch (Throwable $e) { $q = []; }

$speciality =
  trim((string)($q["speciality"] ?? "")) ?:
  trim((string)($_POST["speciality"] ?? "")) ?:
  trim((string)($_GET["speciality"] ?? ""));

if ($speciality === "") {
  json_response(400, ["ok" => false, "error" => "Missing speciality"]);
}

function norm_key(string $s): string {
  $s = strtoupper(trim($s));
  $s = preg_replace('/\s+/', '_', $s);
  $s = preg_replace('/[^A-Z0-9_]/', '', $s);
  return $s ?? "";
}

$key = norm_key($speciality);

/**
 * Aliases to support old DB values like "Orthopedic", "Orthopaedic", etc.
 */
$aliases = [$key];
switch ($key) {
  case "ORTHOPEDICS":
    $aliases[] = "ORTHOPEDIC";
    $aliases[] = "ORTHOPAEDICS";
    $aliases[] = "ORTHOPAEDIC";
    break;

  case "OPHTHALMOLOGY":
    $aliases[] = "OPHTHALMOLOGIST";
    break;

  case "CARDIOLOGY":
    $aliases[] = "CARDIOLOGIST";
    break;

  case "NEUROLOGY":
    $aliases[] = "NEUROLOGIST";
    break;

  case "DERMATOLOGY":
    $aliases[] = "DERMATOLOGIST";
    break;

  case "PEDIATRICS":
    $aliases[] = "PEDIATRICIAN";
    break;

  case "GENERAL_PHYSICIAN":
    $aliases[] = "GENERAL_MEDICINE";
    $aliases[] = "PHYSICIAN";
    break;
}

$in = implode(",", array_fill(0, count($aliases), "?"));

try {
  /**
   * Normalize dp.specialization in SQL close to norm_key():
   * - TRIM
   * - replace spaces/hyphen/slash to underscore
   * - UPPER
   *
   * This will turn "Orthopedic" => ORTHOPEDIC
   * and "General Physician" => GENERAL_PHYSICIAN
   */
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.full_name,
      dp.specialization,
      dp.experience_years,
      dp.fee_amount,
      dp.languages_csv,
      dp.rating,
      dp.consultations_count
    FROM users u
    JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.role='DOCTOR'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
      AND UPPER(
            REPLACE(
              REPLACE(
                REPLACE(TRIM(dp.specialization), '/', '_'),
              '-', '_'),
            ' ', '_')
          ) IN ($in)
    ORDER BY dp.rating DESC, dp.consultations_count DESC
    LIMIT 50
  ");

  $stmt->execute($aliases);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $docs = [];
  foreach ($rows as $r) {
    $docs[] = [
      "doctorId" => (int)$r["id"],
      "name" => (string)$r["full_name"],
      "specialization" => (string)($r["specialization"] ?? ""),
      "experienceYears" => (int)($r["experience_years"] ?? 0),
      "fee" => (int)($r["fee_amount"] ?? 0),
      "languages" => (string)($r["languages_csv"] ?? ""),
      "rating" => (float)($r["rating"] ?? 0),
      "consultations" => (int)($r["consultations_count"] ?? 0),
      "availability" => "Available Now"
    ];
  }

  json_response(200, [
    "ok" => true,
    "data" => ["doctors" => $docs],
    // harmless debug to confirm what key/aliases were used (helps you verify fast)
    "debug" => ["input" => $speciality, "key" => $key, "aliases" => $aliases]
  ]);
} catch (Throwable $e) {
  json_response(500, ["ok" => false, "error" => "Server error"]);
}
