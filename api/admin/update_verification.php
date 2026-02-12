<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
if (strtoupper((string)($auth["role"] ?? "")) !== "ADMIN") {
  json_response(403, ["ok"=>false,"error"=>"Admin only"]);
}

$adminId = (int)($auth["uid"] ?? 0);
$data = read_json() ?: [];

$userId = (int)($data["user_id"] ?? 0);
$status = strtoupper(trim((string)($data["status"] ?? ""))); // VERIFIED / REJECTED
$reason = trim((string)($data["reason"] ?? ""));

if ($userId <= 0) json_response(422, ["ok"=>false,"error"=>"Missing user_id"]);
if (!in_array($status, ["VERIFIED","REJECTED"], true)) {
  json_response(422, ["ok"=>false,"error"=>"Invalid status"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $pdo->beginTransaction();

  // update users
  if ($status === "VERIFIED") {
    $pdo->prepare("
      UPDATE users
      SET admin_verification_status='VERIFIED',
          admin_verified_by=?,
          admin_verified_at=UTC_TIMESTAMP(),
          admin_rejection_reason=NULL
      WHERE id=?
    ")->execute([$adminId, $userId]);
  } else {
    $pdo->prepare("
      UPDATE users
      SET admin_verification_status='REJECTED',
          admin_verified_by=?,
          admin_verified_at=UTC_TIMESTAMP(),
          admin_rejection_reason=?
      WHERE id=?
    ")->execute([$adminId, $reason, $userId]);
  }

  // keep professional_verifications in sync
  $pdo->prepare("
    UPDATE professional_verifications
    SET status=?,
        reviewed_by=?,
        reviewed_at=UTC_TIMESTAMP(),
        rejection_reason=?
    WHERE user_id=?
  ")->execute([$status, $adminId, ($status==="REJECTED" ? $reason : null), $userId]);

  $pdo->commit();

  json_response(200, ["ok"=>true, "message"=>"Updated", "status"=>$status]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Failed to update status"]);
}
