<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

function allowed_mime(string $m): bool {
  $m = strtolower(trim($m));
  return in_array($m, ["application/pdf","image/jpeg","image/jpg","image/png"], true);
}
function safe_ext(string $m): string {
  $m = strtolower(trim($m));
  if ($m === "application/pdf") return "pdf";
  if ($m === "image/png") return "png";
  return "jpg";
}

$auth = require_auth();
$data = read_json();
require_fields($data, ["documents"]);

$userId = (int)$auth["uid"];
$docs = is_array($data["documents"]) ? $data["documents"] : [];

$MIN = 1;
$MAX = 5;
if (count($docs) < $MIN) json_response(422, ["ok"=>false,"error"=>"Please upload at least {$MIN} file"]);
if (count($docs) > $MAX) json_response(422, ["ok"=>false,"error"=>"You can upload only {$MAX} files"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// must be patient
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

$projectRoot = realpath(__DIR__ . "/../..");
if (!$projectRoot) json_response(500, ["ok"=>false,"error"=>"Server path error"]);

$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads"
  . DIRECTORY_SEPARATOR . "patients"
  . DIRECTORY_SEPARATOR . $userId;

if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

$maxBytes = 5 * 1024 * 1024;
$stored = [];

try {
  $pdo->beginTransaction();

  foreach ($docs as $i => $d) {
    if (!is_array($d)) json_response(422, ["ok"=>false,"error"=>"Invalid documents format"]);

    $mime = strtolower(trim((string)($d["mime_type"] ?? "")));
    $b64  = (string)($d["file_base64"] ?? "");
    $nameIn = trim((string)($d["file_name"] ?? ""));

    if ($mime === "" || $b64 === "") json_response(422, ["ok"=>false,"error"=>"Invalid document payload"]);
    if (!allowed_mime($mime)) json_response(422, ["ok"=>false,"error"=>"Invalid mime_type: ".$mime]);

    $bin = base64_decode($b64, true);
    if ($bin === false) json_response(422, ["ok"=>false,"error"=>"Invalid base64 for file ".($i+1)]);

    $size = strlen($bin);
    if ($size <= 0 || $size > $maxBytes) json_response(422, ["ok"=>false,"error"=>"File too large (max 5MB)"]);

    $ext = safe_ext($mime);
    $safeName = preg_replace("/[^a-zA-Z0-9._-]+/", "_", $nameIn);
    if ($safeName === "" || strlen($safeName) < 3) $safeName = "medical_" . ($i+1) . "." . $ext;

    $rand = bin2hex(random_bytes(6));
    $final = "mh_" . gmdate("Ymd_His") . "_" . $rand . "." . $ext;

    $path = $uploadDir . DIRECTORY_SEPARATOR . $final;
    if (file_put_contents($path, $bin) === false) {
      json_response(500, ["ok"=>false,"error"=>"Failed to save file"]);
    }

    $public = "/sehatsethu_api/uploads/patients/" . $userId . "/" . $final;

    $stored[] = [
      "file_url" => $public,
      "file_name" => $safeName,
      "mime_type" => $mime,
      "file_size" => $size,
      "uploaded_at" => gmdate("c")
    ];
  }

  $pdo->prepare("
    UPDATE patient_profiles
    SET medical_history=?
    WHERE user_id=?
  ")->execute([json_encode($stored, JSON_UNESCAPED_SLASHES), $userId]);

  $pdo->commit();

  json_response(200, [
    "ok"=>true,
    "message"=>"Medical history saved",
    "count"=>count($stored),
    "medical_history"=>$stored
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not save medical history"]);
}
