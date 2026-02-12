<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$data = read_json();

require_fields($data, ["medicine_name","quantity","reorder_level"]);

$uid = (int)($auth["uid"] ?? 0);
$name = trim((string)$data["medicine_name"]);
$strength = isset($data["strength"]) ? trim((string)$data["strength"]) : "";
$qty = (int)$data["quantity"];
$reorder = (int)$data["reorder_level"];

// Accept multiple keys for price from app, but store as price_amount
$priceRaw = $data["price_amount"] ?? ($data["price"] ?? ($data["unit_price"] ?? 0));
$price = is_numeric($priceRaw) ? (float)$priceRaw : 0.0;

if ($name === "") json_response(422, ["ok"=>false,"error"=>"medicine_name required"]);
if ($qty < 0) json_response(422, ["ok"=>false,"error"=>"Invalid quantity"]);
if ($reorder < 0) json_response(422, ["ok"=>false,"error"=>"Invalid reorder_level"]);
if ($price < 0) json_response(422, ["ok"=>false,"error"=>"Invalid price_amount"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// role check
$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

// ✅ Ensure column exists (so price saving never fails)
try {
  $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
  $col = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME='pharmacy_inventory' AND COLUMN_NAME='price_amount'
    LIMIT 1
  ");
  $col->execute([$dbName]);
  $hasPrice = (bool)$col->fetchColumn();

  if (!$hasPrice) {
    $pdo->exec("ALTER TABLE pharmacy_inventory ADD COLUMN price_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER reorder_level");
  }
} catch (Throwable $e) {
  // If hosting blocks INFORMATION_SCHEMA/ALTER, continue without breaking.
}

try {
  $pdo->prepare("
    INSERT INTO pharmacy_inventory
      (pharmacist_user_id, medicine_name, strength, quantity, reorder_level, price_amount)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      quantity = VALUES(quantity),
      reorder_level = VALUES(reorder_level),
      price_amount = VALUES(price_amount),
      updated_at = CURRENT_TIMESTAMP
  ")->execute([$uid, $name, $strength, $qty, $reorder, $price]);

  $s = $pdo->prepare("
    SELECT id FROM pharmacy_inventory
    WHERE pharmacist_user_id=? AND medicine_name=? AND strength=?
    LIMIT 1
  ");
  $s->execute([$uid, $name, $strength]);
  $row = $s->fetch(PDO::FETCH_ASSOC);

  json_response(200, ["ok"=>true, "message"=>"Saved", "id"=>(int)($row["id"] ?? 0)]);
} catch (Throwable $e) {
  json_response(500, ["ok"=>false,"error"=>"Could not save item"]);
}
