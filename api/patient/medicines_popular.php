<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)$auth["uid"];

// optional: ensure patient
$stmt = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

$q = isset($_GET["q"]) ? trim((string)$_GET["q"]) : "";
$limit = isset($_GET["limit"]) ? (int)$_GET["limit"] : 30;
if ($limit <= 0 || $limit > 60) $limit = 30;

$params = [];
$whereQ = "";
if ($q !== "") {
  $whereQ = " AND (pi.medicine_name LIKE ? OR pi.strength LIKE ?)";
  $params[] = "%".$q."%";
  $params[] = "%".$q."%";
}

/**
 * We fetch top rows ordered by quantity desc, then dedupe in PHP to build
 * a "popular medicines" list that also includes a best pharmacy to open details.
 */
$sql = "
  SELECT
    pi.pharmacist_user_id,
    pi.medicine_name,
    pi.strength,
    pi.quantity,
    pi.reorder_level,
    pi.price,
    pp.pharmacy_name
  FROM pharmacy_inventory pi
  JOIN users u2 ON u2.id = pi.pharmacist_user_id
  LEFT JOIN pharmacist_profiles pp ON pp.user_id = pi.pharmacist_user_id
  WHERE
    u2.role='PHARMACIST'
    AND u2.is_active=1
    AND u2.admin_verification_status='VERIFIED'
    $whereQ
  ORDER BY pi.quantity DESC, pi.updated_at DESC
  LIMIT 200
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$out = [];
$seen = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $name = trim((string)$row["medicine_name"]);
  $strength = trim((string)($row["strength"] ?? ""));
  if ($name === "") continue;

  $key = strtolower($name."|".$strength);
  if (isset($seen[$key])) continue;

  $seen[$key] = true;

  $qty = (int)$row["quantity"];
  $reorder = (int)$row["reorder_level"];
  $status = "IN_STOCK";
  if ($qty <= 0) $status = "OUT_OF_STOCK";
  else if ($qty <= $reorder) $status = "LOW_STOCK";

  $out[] = [
    "medicine_name" => $name,
    "strength" => $strength,
    "display_name" => $strength !== "" ? ($name . " " . $strength) : $name,
    "quantity" => $qty,
    "reorder_level" => $reorder,
    "price" => $row["price"] !== null ? (float)$row["price"] : null,
    "status" => $status,
    "pharmacist_user_id" => (int)$row["pharmacist_user_id"],
    "pharmacy_name" => (string)($row["pharmacy_name"] ?? "")
  ];

  if (count($out) >= $limit) break;
}

json_response(200, ["ok"=>true, "items"=>$out]);
