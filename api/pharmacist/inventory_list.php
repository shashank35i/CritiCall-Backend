<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok" => false, "error" => "POST only"]);
}

$auth = require_auth();
$uid  = (int)($auth["uid"] ?? 0);

if ($uid <= 0) {
  json_response(401, ["ok" => false, "error" => "Unauthorized"]);
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // role check (kept exactly like your logic)
  $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
    json_response(403, ["ok" => false, "error" => "Pharmacist only"]);
  }

  // same columns, same sorting, same output shape
  $q = $pdo->prepare("
    SELECT
      id,
      medicine_name,
      strength,
      quantity,
      reorder_level,
      price_amount,
      updated_at
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=?
    ORDER BY medicine_name ASC, strength ASC
  ");
  $q->execute([$uid]);

  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  json_response(200, [
    "ok" => true,
    "data" => [
      "items" => $items
    ]
  ]);

} catch (Throwable $e) {
  // Keep it generic so you don't leak internals in production
  json_response(500, ["ok" => false, "error" => "Server error"]);
}
