<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid = (int)$auth["uid"];

$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$uid]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

$q = $pdo->prepare("
  SELECT
    COUNT(*) AS total_items,
    SUM(CASE WHEN quantity > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock_items
  FROM pharmacy_inventory
  WHERE pharmacist_user_id=?
");
$q->execute([$uid]);
$row = $q->fetch(PDO::FETCH_ASSOC);

json_response(200, [
  "ok" => true,
  "total_items" => (int)($row["total_items"] ?? 0),
  "low_stock_items" => (int)($row["low_stock_items"] ?? 0),
]);
