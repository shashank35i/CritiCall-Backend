<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$data = read_json();
require_fields($data, ["item_id","quantity"]);

$uid = (int)$auth["uid"];
$itemId = (int)$data["item_id"];
$qty = (int)$data["quantity"];

if ($itemId <= 0) json_response(422, ["ok"=>false,"error"=>"Invalid item_id"]);
if ($qty < 0 || $qty > 999999) json_response(422, ["ok"=>false,"error"=>"Invalid quantity"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// role check
$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

$up = $pdo->prepare("
  UPDATE pharmacy_inventory
  SET quantity=?, updated_at=CURRENT_TIMESTAMP
  WHERE id=? AND pharmacist_user_id=?
");
$up->execute([$qty, $itemId, $uid]);

if ($up->rowCount() <= 0) {
  json_response(404, ["ok"=>false,"error"=>"Item not found"]);
}

json_response(200, ["ok"=>true, "message"=>"Updated"]);
