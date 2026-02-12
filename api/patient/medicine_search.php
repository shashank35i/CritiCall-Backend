<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$role = strtoupper((string)($auth["role"] ?? ""));
if ($role !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

$q = trim((string)($_GET["q"] ?? ""));
$limit = (int)($_GET["limit"] ?? 50);
if ($limit < 1) $limit = 50;
if ($limit > 100) $limit = 100;

$lat = $_GET["lat"] ?? null;
$lng = $_GET["lng"] ?? null;
$lat = (is_numeric($lat) ? (float)$lat : null);
$lng = (is_numeric($lng) ? (float)$lng : null);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$like = "%" . $q . "%";

/**
 * Distance formula (km) with safe clamp for ACOS.
 * Uses positional params (?) so it works even when emulate prepares is OFF.
 */
$distExpr = "
  (6371 * ACOS(
    LEAST(1.0, GREATEST(-1.0,
      COS(RADIANS(?)) * COS(RADIANS(pp.latitude)) * COS(RADIANS(pp.longitude) - RADIANS(?))
      + SIN(RADIANS(?)) * SIN(RADIANS(pp.latitude))
    ))
  ))
";

$distExpr2 = "
  (6371 * ACOS(
    LEAST(1.0, GREATEST(-1.0,
      COS(RADIANS(?)) * COS(RADIANS(pp2.latitude)) * COS(RADIANS(pp2.longitude) - RADIANS(?))
      + SIN(RADIANS(?)) * SIN(RADIANS(pp2.latitude))
    ))
  ))
";

try {

  /**
   * Step 1: get distinct medicine+strength combos that match search.
   * We keep this lightweight and reliable.
   */
  $sqlCombos = "
    SELECT
      inv.medicine_name,
      COALESCE(inv.strength,'') AS strength_key,
      MAX(inv.quantity) AS max_qty
    FROM pharmacy_inventory inv
    JOIN users u ON u.id = inv.pharmacist_user_id
    WHERE u.role='PHARMACIST'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
      AND (
        inv.medicine_name LIKE ?
        OR CONCAT(inv.medicine_name, ' ', COALESCE(inv.strength,'')) LIKE ?
      )
    GROUP BY inv.medicine_name, COALESCE(inv.strength,'')
    ORDER BY max_qty DESC, inv.medicine_name ASC, strength_key ASC
    LIMIT $limit
  ";

  $st1 = $pdo->prepare($sqlCombos);
  $st1->execute([$like, $like]);
  $combos = $st1->fetchAll(PDO::FETCH_ASSOC);

  if (!$combos) {
    json_response(200, ["ok"=>true, "items"=>[]]);
  }

  $items = [];

  /**
   * Step 2: for each combo pick BEST pharmacy:
   *  1) nearest (if coords exist; else treated as far)
   *  2) highest quantity
   *  3) lowest pharmacist_user_id
   *
   * This avoids window functions and avoids repeating named placeholders.
   */
  $sqlPick = "
    SELECT
      inv.medicine_name,
      inv.strength,
      inv.quantity,
      inv.reorder_level,
      inv.price,
      inv.pharmacist_user_id,
      pp.pharmacy_name,
      u.full_name AS owner_name,
      u.phone,
      pp.village_town,
      pp.full_address,
      CASE
        WHEN inv.quantity <= 0 THEN 'OUT_OF_STOCK'
        WHEN inv.quantity <= inv.reorder_level THEN 'LOW_STOCK'
        ELSE 'IN_STOCK'
      END AS status,
      CASE
        WHEN ? IS NULL OR ? IS NULL OR pp.latitude IS NULL OR pp.longitude IS NULL THEN NULL
        ELSE $distExpr
      END AS distance_km,
      CASE
        WHEN ? IS NULL OR ? IS NULL OR pp.latitude IS NULL OR pp.longitude IS NULL THEN 1000000000
        ELSE $distExpr
      END AS dist_sort
    FROM pharmacy_inventory inv
    JOIN users u ON u.id = inv.pharmacist_user_id
    JOIN pharmacist_profiles pp ON pp.user_id = u.id
    WHERE u.role='PHARMACIST'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
      AND inv.medicine_name = ?
      AND COALESCE(inv.strength,'') = ?
      AND inv.pharmacist_user_id = (
        SELECT inv2.pharmacist_user_id
        FROM pharmacy_inventory inv2
        JOIN users u2 ON u2.id = inv2.pharmacist_user_id
        JOIN pharmacist_profiles pp2 ON pp2.user_id = u2.id
        WHERE u2.role='PHARMACIST'
          AND u2.is_active=1
          AND u2.admin_verification_status='VERIFIED'
          AND inv2.medicine_name = ?
          AND COALESCE(inv2.strength,'') = ?
        ORDER BY
          CASE
            WHEN ? IS NULL OR ? IS NULL OR pp2.latitude IS NULL OR pp2.longitude IS NULL THEN 1000000000
            ELSE $distExpr2
          END ASC,
          inv2.quantity DESC,
          inv2.pharmacist_user_id ASC
        LIMIT 1
      )
    LIMIT 1
  ";

  $st2 = $pdo->prepare($sqlPick);

  foreach ($combos as $c) {
    $name = (string)($c["medicine_name"] ?? "");
    $strengthKey = (string)($c["strength_key"] ?? "");

    // params for sqlPick in exact order
    $params = [];

    // distance_km CASE ? IS NULL OR ? IS NULL ...
    $params[] = $lat; $params[] = $lng;
    // distExpr uses 3 params (lat,lng,lat)
    $params[] = $lat; $params[] = $lng; $params[] = $lat;

    // dist_sort CASE ? IS NULL OR ? IS NULL ...
    $params[] = $lat; $params[] = $lng;
    // distExpr again
    $params[] = $lat; $params[] = $lng; $params[] = $lat;

    // inv.medicine_name, strength_key
    $params[] = $name;
    $params[] = $strengthKey;

    // subquery inv2.medicine_name, strength_key
    $params[] = $name;
    $params[] = $strengthKey;

    // ORDER BY distance CASE ? IS NULL OR ? IS NULL ...
    $params[] = $lat; $params[] = $lng;
    // distExpr2 uses 3 params (lat,lng,lat)
    $params[] = $lat; $params[] = $lng; $params[] = $lat;

    $st2->execute($params);
    $r = $st2->fetch(PDO::FETCH_ASSOC);
    if (!$r) continue;

    $medName = (string)($r["medicine_name"] ?? "");
    $strength = (string)($r["strength"] ?? "");
    $display = trim($medName . " " . $strength);

    $dist = $r["distance_km"];
    $distKm = ($dist === null ? null : round((float)$dist, 2));

    $items[] = [
      "medicine_name" => $medName,
      "strength" => $strength,
      "display_name" => $display,
      "status" => (string)$r["status"],
      "quantity" => (int)$r["quantity"],
      "reorder_level" => (int)$r["reorder_level"],
      "price" => ($r["price"] === null ? null : (float)$r["price"]),
      "pharmacist_user_id" => (int)$r["pharmacist_user_id"],
      "pharmacy_name" => (string)($r["pharmacy_name"] ?? ""),
      "owner_name" => (string)($r["owner_name"] ?? ""),
      "phone" => (string)($r["phone"] ?? ""),
      "village_town" => (string)($r["village_town"] ?? ""),
      "full_address" => (string)($r["full_address"] ?? ""),
      "distance_km" => $distKm,
    ];
  }

  // final sort: distance first when present, else quantity
  usort($items, function($a, $b) {
    $da = $a["distance_km"]; $db = $b["distance_km"];
    if ($da === null && $db !== null) return 1;
    if ($da !== null && $db === null) return -1;
    if ($da !== null && $db !== null) {
      if ($da < $db) return -1;
      if ($da > $db) return 1;
    }
    return ($b["quantity"] ?? 0) <=> ($a["quantity"] ?? 0);
  });

  json_response(200, ["ok"=>true, "items"=>$items]);

} catch (Throwable $e) {
  // production-safe
  json_response(500, ["ok"=>false,"error"=>"Server error"]);
}
