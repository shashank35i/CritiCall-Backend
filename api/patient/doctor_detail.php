<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
require_auth();
$pdo = db();

$q = read_json();
$doctorId = (int)($q["doctorId"] ?? 0);
if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Missing doctorId"]);

try {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.full_name,
      dp.specialization,
      dp.experience_years,
      dp.fee_amount,
      dp.languages_csv,
      dp.rating,
      dp.reviews_count,
      dp.consultations_count,
      dp.about_text,
      dp.education_text,
      dp.works_at_text
    FROM users u
    JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.id=?
      AND u.role='DOCTOR'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
    LIMIT 1
  ");
  $stmt->execute([$doctorId]);
  $r = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$r) json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);

  $doctor = [
    "doctorId" => (int)$r["id"],
    "name" => (string)$r["full_name"],
    "specialization" => (string)($r["specialization"] ?? ""),
    "experienceYears" => (int)($r["experience_years"] ?? 0),
    "fee" => (int)($r["fee_amount"] ?? 0),
    "languages" => (string)($r["languages_csv"] ?? ""),
    "rating" => (float)($r["rating"] ?? 0),
    "reviews" => (int)($r["reviews_count"] ?? 0),
    "patients" => (int)($r["consultations_count"] ?? 0),

    "about" => (string)($r["about_text"] ?? ""),
    "education" => (string)($r["education_text"] ?? ""),
    "worksAt" => (string)($r["works_at_text"] ?? "")
  ];

  json_response(200, ["ok"=>true, "data"=>["doctor"=>$doctor]]);
} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
