<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false, "error"=>"GET only"]);
}

$auth = require_auth();
$role = strtoupper((string)($auth["role"] ?? ""));
if ($role !== "PATIENT") {
  json_response(403, ["ok"=>false, "error"=>"Patient only"]);
}

$pharmacistId = (int)($_GET["pharmacist_user_id"] ?? 0);
if ($pharmacistId <= 0) {
  json_response(400, ["ok"=>false, "error"=>"Missing pharmacist_user_id"]);
}

$lat = $_GET["lat"] ?? null;
$lng = $_GET["lng"] ?? null;
$lat = (is_numeric($lat) ? (float)$lat : null);
$lng = (is_numeric($lng) ? (float)$lng : null);

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
  $R = 6371.0;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat/2) * sin($dLat/2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLng/2) * sin($dLng/2);
  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $R * $c;
}

function time_to_minutes(string $t): ?int {
  $t = trim($t);
  if ($t === "") return null;
  $t = preg_replace('/\s+/', ' ', $t);

  if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([AaPp][Mm])$/', $t, $m)) {
    $h = (int)$m[1];
    $min = isset($m[2]) ? (int)$m[2] : 0;
    $ampm = strtoupper($m[3]);

    if ($h < 1 || $h > 12 || $min < 0 || $min > 59) return null;
    if ($h === 12) $h = 0;
    $base = $h * 60 + $min;
    if ($ampm === "PM") $base += 12 * 60;
    return $base;
  }

  if (preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $t, $m)) {
    $h = (int)$m[1];
    $min = isset($m[2]) ? (int)$m[2] : 0;
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
    return $h * 60 + $min;
  }

  return null;
}

function is_open_now_from_timings(string $timings): bool {
  $timings = trim($timings);
  if ($timings === "") return false;

  $nowMin = ((int)date("G") * 60) + (int)date("i");
  $parts = preg_split('/\s*[;,]\s*/', $timings);
  if (!$parts) $parts = [$timings];

  foreach ($parts as $part) {
    $part = trim($part);
    if ($part === "") continue;

    $norm = preg_replace('/\s+to\s+/i', ' - ', $part);
    $norm = preg_replace('/\s*-\s*/', '-', $norm);

    $range = explode('-', $norm);
    if (count($range) < 2) continue;

    $start = time_to_minutes(trim($range[0]));
    $end   = time_to_minutes(trim($range[1]));
    if ($start === null || $end === null) continue;

    if ($start <= $end) {
      if ($nowMin >= $start && $nowMin <= $end) return true;
    } else {
      if ($nowMin >= $start || $nowMin <= $end) return true;
    }
  }
  return false;
}

/**
 * ✅ FIX: return a COALESCE across whatever price columns exist.
 * This avoids the bug where `price` column exists but is NULL,
 * while `price_amount` has the actual value.
 *
 * Also uses NULLIF(col,0) so default 0 doesn't show as ₹0.
 */
function inventory_price_expr(PDO $pdo): string {
  // ✅ Prefer price_amount first (your app stores there)
  $priority = ["price_amount", "price", "unit_price", "mrp", "amount"];

  try {
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if (!$dbName) return "NULL AS price";

    $in = implode(",", array_fill(0, count($priority), "?"));
    $st = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = 'pharmacy_inventory'
        AND COLUMN_NAME IN ($in)
    ");
    $st->execute(array_merge([$dbName], $priority));
    $found = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$found || !is_array($found)) return "NULL AS price";

    $parts = [];
    foreach ($priority as $col) {
      if (in_array($col, $found, true)) {
        // treat 0 as null, cast to decimal
        $parts[] = "NULLIF(CAST($col AS DECIMAL(10,2)), 0)";
      }
    }
    if (!$parts) return "NULL AS price";

    return "COALESCE(" . implode(", ", $parts) . ") AS price";
  } catch (Throwable $e) {
    return "NULL AS price";
  }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$debug = (isset($_GET["debug"]) && $_GET["debug"] == "1");

try {
  $sql = "
    SELECT
      u.id,
      u.full_name AS owner_name,
      u.phone,
      pp.pharmacy_name,
      pp.village_town,
      pp.full_address,
      pp.availability_timings,
      pp.latitude,
      pp.longitude
    FROM users u
    JOIN pharmacist_profiles pp ON pp.user_id = u.id
    WHERE u.id = ?
      AND u.role='PHARMACIST'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
    LIMIT 1
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$pharmacistId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_response(404, ["ok"=>false, "error"=>"Pharmacy not found"]);
  }

  $address = trim((string)($row["full_address"] ?? ""));
  if ($address === "") $address = trim((string)($row["village_town"] ?? ""));

  $distKm = null;
  $pLat = $row["latitude"];
  $pLng = $row["longitude"];
  if ($lat !== null && $lng !== null && is_numeric($pLat) && is_numeric($pLng)) {
    $distKm = round(haversine_km($lat, $lng, (float)$pLat, (float)$pLng), 2);
  }

  $hours = trim((string)($row["availability_timings"] ?? ""));
  $openNow = ($hours !== "") ? is_open_now_from_timings($hours) : false;

  $statsSql = "
    SELECT
      COUNT(*) AS total_items,
      SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) AS available_items,
      SUM(CASE WHEN quantity > 0 AND quantity <= COALESCE(reorder_level,0) THEN 1 ELSE 0 END) AS low_items
    FROM pharmacy_inventory
    WHERE pharmacist_user_id = ?
  ";
  $st2 = $pdo->prepare($statsSql);
  $st2->execute([$pharmacistId]);
  $stats = $st2->fetch(PDO::FETCH_ASSOC) ?: ["total_items"=>0,"available_items"=>0,"low_items"=>0];

  $priceExpr = inventory_price_expr($pdo);

  $invSql = "
    SELECT medicine_name, strength, quantity, reorder_level, $priceExpr
    FROM pharmacy_inventory
    WHERE pharmacist_user_id = ?
    ORDER BY
      CASE
        WHEN quantity <= 0 THEN 2
        WHEN quantity <= COALESCE(reorder_level,0) THEN 1
        ELSE 0
      END ASC,
      quantity DESC,
      medicine_name ASC
    LIMIT 200
  ";
  $inv = $pdo->prepare($invSql);
  $inv->execute([$pharmacistId]);

  $meds = [];
  while ($r = $inv->fetch(PDO::FETCH_ASSOC)) {
    $name = trim((string)($r["medicine_name"] ?? ""));
    if ($name === "") continue;

    $strength = trim((string)($r["strength"] ?? ""));
    $display = trim($name . " " . $strength);

    $qty = (int)($r["quantity"] ?? 0);
    $reorder = (int)($r["reorder_level"] ?? 0);

    $status = "IN_STOCK";
    if ($qty <= 0) $status = "OUT_OF_STOCK";
    else if ($qty <= $reorder) $status = "LOW_STOCK";

    $price = null;
    if (array_key_exists("price", $r) && $r["price"] !== null && $r["price"] !== "") {
      $price = (float)$r["price"];
    }

    $meds[] = [
      "medicine_name" => $name,
      "strength" => $strength,
      "display_name" => ($display === "" ? $name : $display),
      "quantity" => $qty,
      "reorder_level" => $reorder,
      "status" => $status,
      "price" => $price,
    ];
  }

  $data = [
    "pharmacy_name" => (string)($row["pharmacy_name"] ?? ""),
    "owner_name" => (string)($row["owner_name"] ?? ""),
    "phone" => (string)($row["phone"] ?? ""),
    "address" => $address,
    "distance_km" => $distKm,
    "hours" => $hours,
    "open_now" => $openNow,
    "rating" => 0.0,
    "reviews_count" => 0,
    "stats" => [
      "total_stock" => (int)($stats["total_items"] ?? 0),
      "available_stock" => (int)($stats["available_items"] ?? 0),
      "low_stock_items" => (int)($stats["low_items"] ?? 0),
    ],
    "medicines" => $meds
  ];

  json_response(200, ["ok"=>true, "data"=>$data]);

} catch (Throwable $e) {
  if ($debug) {
    json_response(500, ["ok"=>false, "error"=>"Server error", "detail"=>$e->getMessage()]);
  }
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
