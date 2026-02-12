<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

$auth = require_auth();
if (strtoupper((string)($auth["role"] ?? "")) !== "ADMIN") {
  json_response(403, ["ok"=>false,"error"=>"Admin only"]);
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) json_response(422, ["ok"=>false,"error"=>"Missing id"]);

$pdo = db();

$uStmt = $pdo->prepare("
  SELECT
    u.id, u.full_name, u.role, u.admin_verification_status AS status,
    u.profile_submitted_at, u.created_at
  FROM users u
  WHERE u.id=? LIMIT 1
");
$uStmt->execute([$id]);
$u = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$u) json_response(404, ["ok"=>false,"error"=>"User not found"]);

$role = strtoupper((string)$u["role"]);

$appliedAt = $u["profile_submitted_at"];
if (!$appliedAt) $appliedAt = $u["created_at"];

$user = [
  "id" => (int)$u["id"],
  "full_name" => (string)$u["full_name"],
  "role" => $role,
  "status" => (string)$u["status"],
  "applied_at" => (string)$appliedAt,
  "subtitle" => "",
  "docs_count" => 0,

  // common fields (front-end can show/hide based on role)
  "registration_no" => "",
  "experience_years" => "",
  "practice_place" => "",
  "phone" => "",

  "pharmacy_name" => "",
  "drug_license_no" => "",
  "village_town" => "",
  "full_address" => "",

  "documents" => []
];

if ($role === "DOCTOR") {
  $p = $pdo->prepare("SELECT specialization, registration_no, practice_place, phone, experience_years FROM doctor_profiles WHERE user_id=? LIMIT 1");
  $p->execute([$id]);
  $dp = $p->fetch(PDO::FETCH_ASSOC) ?: [];

  $user["subtitle"] = (string)($dp["specialization"] ?? "");
  $user["registration_no"] = (string)($dp["registration_no"] ?? "");
  $user["practice_place"] = (string)($dp["practice_place"] ?? "");
  $user["phone"] = (string)($dp["phone"] ?? "");
  $user["experience_years"] = (string)($dp["experience_years"] ?? "");

  $d = $pdo->prepare("SELECT doc_type, file_url FROM doctor_documents WHERE user_id=? ORDER BY doc_type ASC");
  $d->execute([$id]);

  $docs = [];
  while ($r = $d->fetch(PDO::FETCH_ASSOC)) {
    $title = $r["doc_type"];
    if ($title === "MEDICAL_LICENSE") $title = "Medical License";
    if ($title === "AADHAAR") $title = "Aadhaar Card";
    if ($title === "MBBS_CERT") $title = "MBBS Certificate";

    $docs[] = [
      "title" => $title,
      "url" => make_public_url((string)$r["file_url"]) // helper below
    ];
  }

  $user["documents"] = $docs;
  $user["docs_count"] = count($docs);
}

if ($role === "PHARMACIST") {
  $p = $pdo->prepare("SELECT pharmacy_name, drug_license_no, village_town, full_address FROM pharmacist_profiles WHERE user_id=? LIMIT 1");
  $p->execute([$id]);
  $pp = $p->fetch(PDO::FETCH_ASSOC) ?: [];

  $user["subtitle"] = (string)($pp["village_town"] ?? "");
  $user["pharmacy_name"] = (string)($pp["pharmacy_name"] ?? "");
  $user["drug_license_no"] = (string)($pp["drug_license_no"] ?? "");
  $user["village_town"] = (string)($pp["village_town"] ?? "");
  $user["full_address"] = (string)($pp["full_address"] ?? "");

  $d = $pdo->prepare("SELECT doc_index, file_url FROM pharmacist_documents WHERE user_id=? ORDER BY doc_index ASC");
  $d->execute([$id]);

  $docs = [];
  while ($r = $d->fetch(PDO::FETCH_ASSOC)) {
    $docs[] = [
      "title" => "Document " . (int)$r["doc_index"],
      "url" => make_public_url((string)$r["file_url"])
    ];
  }

  $user["documents"] = $docs;
  $user["docs_count"] = count($docs);
}

json_response(200, ["ok"=>true, "user"=>$user]);

/**
 * Converts stored relative path "/sehatsethu_api/uploads/..." to absolute URL.
 * Uses your config base_url if available.
 */
function make_public_url(string $path): string {
  $path = trim($path);
  if ($path === "") return "";

  if (stripos($path, "http://") === 0 || stripos($path, "https://") === 0) return $path;

  // try from config if you have it
  $base = "";
  if (function_exists("app_base_url")) {
    $base = rtrim(app_base_url(), "/");
  }

  if ($base === "") {
    // fallback to localhost
    $base = "http://10.0.2.2";
  }

  return $base . $path;
}
