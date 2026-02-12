<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false, "error"=>"POST only"]);
}

$auth = require_auth();
$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PHARMACIST") json_response(403, ["ok"=>false, "error"=>"Pharmacist only"]);

$data = read_json();
require_fields($data, ["request_id"]);

$requestId = (int)$data["request_id"];
if ($requestId <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid request_id"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $pdo->beginTransaction();

  // lock request row
  $st = $pdo->prepare("
    SELECT id, patient_user_id, pharmacist_user_id, medicine_name, strength, quantity, status
    FROM medicine_requests
    WHERE id=? AND pharmacist_user_id=?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$requestId, $uid]);
  $req = $st->fetch(PDO::FETCH_ASSOC);

  if (!$req) {
    $pdo->rollBack();
    json_response(404, ["ok"=>false, "error"=>"Request not found"]);
  }

  if ((string)$req["status"] !== "PENDING") {
    $pdo->rollBack();
    json_response(409, ["ok"=>false, "error"=>"Request already processed"]);
  }

  $patientId = (int)$req["patient_user_id"];
  $medicine  = (string)$req["medicine_name"];
  $strength  = (string)($req["strength"] ?? "");
  $reqQty    = (int)$req["quantity"];

  // ✅ Ensure inventory is IN STOCK (qty > 0)
  $inv = $pdo->prepare("
    SELECT quantity, reorder_level
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=? AND medicine_name=? AND COALESCE(strength,'')=COALESCE(?, '')
    LIMIT 1
  ");
  $inv->execute([$uid, $medicine, $strength]);
  $invRow = $inv->fetch(PDO::FETCH_ASSOC);

  $invQty = $invRow ? (int)$invRow["quantity"] : 0;
  if ($invQty <= 0) {
    $pdo->rollBack();
    json_response(409, ["ok"=>false, "error"=>"Still out of stock"]);
  }

  // mark request as fulfilled/available
  $upd = $pdo->prepare("
    UPDATE medicine_requests
    SET status='MARKED_AVAILABLE', marked_available_at=NOW(), updated_at=NOW()
    WHERE id=? AND pharmacist_user_id=?
  ");
  $upd->execute([$requestId, $uid]);

  // Notification payload
  $title = "Request Fulfilled";
  $body  = "Medicine request #".$requestId." has been marked as fulfilled";

  $dataJson = json_encode([
    "type" => "MEDICINE_REQUEST_FULFILLED",
    "request_id" => $requestId,
    "patient_id" => $patientId,
    "pharmacist_id" => $uid,
    "medicine_name" => $medicine,
    "strength" => $strength,
    "quantity" => $reqQty
  ], JSON_UNESCAPED_UNICODE);

  // ✅ create notification for pharmacist
  $pdo->prepare("
    INSERT INTO notifications (user_id, title, body, data_json, is_read, created_at)
    VALUES (?, ?, ?, ?, 0, NOW())
  ")->execute([$uid, $title, $body, $dataJson]);

  // ✅ create notification for patient
  $pdo->prepare("
    INSERT INTO notifications (user_id, title, body, data_json, is_read, created_at)
    VALUES (?, ?, ?, ?, 0, NOW())
  ")->execute([$patientId, $title, $body, $dataJson]);

  $pdo->commit();

  json_response(200, ["ok"=>true, "message"=>"Marked available", "id"=>$requestId]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
