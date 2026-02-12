<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$patientId = (int)($auth["uid"] ?? 0);
if ($patientId <= 0) json_response(401, ["ok"=>false,"error"=>"Unauthorized"]);

if (strtoupper((string)($auth["role"] ?? "")) !== "PATIENT") {
  // If your require_auth() doesn't include role, fallback to DB check:
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
  $st->execute([$patientId]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u || strtoupper((string)$u["role"]) !== "PATIENT") {
    json_response(403, ["ok"=>false,"error"=>"Patient only"]);
  }
}

$data = read_json();
require_fields($data, ["pharmacist_user_id","medicine_name","quantity"]);

$pharmacistId = (int)$data["pharmacist_user_id"];
$medicine = trim((string)$data["medicine_name"]);
$strength = isset($data["strength"]) ? trim((string)$data["strength"]) : "";
$qty = (int)$data["quantity"];

if ($pharmacistId <= 0) json_response(422, ["ok"=>false,"error"=>"Invalid pharmacist_user_id"]);
if ($medicine === "") json_response(422, ["ok"=>false,"error"=>"medicine_name required"]);
if ($qty <= 0 || $qty > 999) json_response(422, ["ok"=>false,"error"=>"Invalid quantity"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure pharmacist exists + verified
$st = $pdo->prepare("
  SELECT u.id
  FROM users u
  WHERE u.id=? AND u.role='PHARMACIST' AND u.is_active=1 AND u.admin_verification_status='VERIFIED'
  LIMIT 1
");
$st->execute([$pharmacistId]);
if (!$st->fetch(PDO::FETCH_ASSOC)) {
  json_response(404, ["ok"=>false,"error"=>"Pharmacy not found"]);
}

/**
 * Anti-spam merge: update if same pending within last 10 minutes
 */
$find = $pdo->prepare("
  SELECT id
  FROM medicine_requests
  WHERE patient_user_id=? AND pharmacist_user_id=? AND medicine_name=? AND strength=? AND status='PENDING'
    AND created_at >= (NOW() - INTERVAL 10 MINUTE)
  ORDER BY id DESC
  LIMIT 1
");
$find->execute([$patientId, $pharmacistId, $medicine, $strength]);
$existing = $find->fetch(PDO::FETCH_ASSOC);

if ($existing) {
  $rid = (int)$existing["id"];
  $upd = $pdo->prepare("UPDATE medicine_requests SET quantity=? WHERE id=?");
  $upd->execute([$qty, $rid]);

  // optional: also notify pharmacist about update (kept off to avoid spam)
  json_response(200, ["ok"=>true, "message"=>"Updated", "id"=>$rid]);
}

try {
  $pdo->beginTransaction();

  $ins = $pdo->prepare("
    INSERT INTO medicine_requests (patient_user_id, pharmacist_user_id, medicine_name, strength, quantity, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())
  ");
  $ins->execute([$patientId, $pharmacistId, $medicine, $strength, $qty]);
  $rid = (int)$pdo->lastInsertId();

  // patient name for notification text
  $stP = $pdo->prepare("SELECT full_name, phone FROM users WHERE id=? LIMIT 1");
  $stP->execute([$patientId]);
  $pRow = $stP->fetch(PDO::FETCH_ASSOC);
  $patientName = trim((string)($pRow["full_name"] ?? "Patient"));
  if ($patientName === "") $patientName = "Patient";

  $disp = $strength === "" ? $medicine : ($medicine . " " . $strength);

  $title = "New Medicine Request";
  $bodyTxt = $patientName . " requested " . $disp . " - Qty " . $qty;

  $dataJson = json_encode([
    "type" => "MEDICINE_REQUEST",
    "request_id" => $rid,
    "patient_id" => $patientId,
    "pharmacist_id" => $pharmacistId,
    "medicine_name" => $medicine,
    "strength" => $strength,
    "quantity" => $qty
  ], JSON_UNESCAPED_UNICODE);

  // create pharmacist notification
  $pdo->prepare("
    INSERT INTO notifications (user_id, title, body, data_json, is_read, created_at)
    VALUES (?, ?, ?, ?, 0, NOW())
  ")->execute([$pharmacistId, $title, $bodyTxt, $dataJson]);

  $pdo->commit();
  json_response(200, ["ok"=>true, "message"=>"Created", "id"=>$rid]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Server error"]);
}
