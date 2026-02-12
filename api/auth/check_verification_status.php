<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

function require_auth(): array {
  $t = get_bearer_token(); // ✅ from helpers.php (XAMPP-safe)
  if ($t === "") json_response(401, ["ok"=>false,"error"=>"Missing token"]);
  $p = jwt_decode($t);
  if (!$p || !isset($p["uid"])) json_response(401, ["ok"=>false,"error"=>"Invalid/expired token"]);
  return $p;
}

$auth = require_auth();
$userId = (int)$auth["uid"];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ✅ Always fetch fresh from DB
$st = $pdo->prepare("
  SELECT
    role,
    admin_verification_status,
    admin_rejection_reason
  FROM users
  WHERE id=?
  LIMIT 1
");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u) json_response(404, ["ok"=>false,"error"=>"User not found"]);

json_response(200, [
  "ok" => true,
  "role" => $u["role"],
  "admin_verification_status" => $u["admin_verification_status"] ?? "UNVERIFIED",
  "admin_verification_reason" => $u["admin_rejection_reason"] ?? ""
]);
