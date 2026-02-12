<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false, "error"=>"GET only"]);
}

$auth = require_auth();
$uid = (int)($auth["uid"] ?? 0);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// role check
$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false, "error"=>"Pharmacist only"]);
}

// helper: safe table exists
function table_exists(PDO $pdo, string $table): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $q = $pdo->prepare("
      SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
      LIMIT 1
    ");
    $q->execute([$db, $table]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function time_ago(string $dt): string {
  $ts = strtotime($dt);
  if (!$ts) return "";
  $diff = time() - $ts;
  if ($diff < 60) return "Just now";
  $m = floor($diff/60);
  if ($m < 60) return $m . " min ago";
  $h = floor($m/60);
  if ($h < 24) return $h . " hr ago";
  $d = floor($h/24);
  if ($d < 7) return $d . " days ago";
  return date("d M", $ts);
}

// pharmacy name
$pharmacyName = "Store";
try {
  $p = $pdo->prepare("SELECT pharmacy_name FROM pharmacist_profiles WHERE user_id=? LIMIT 1");
  $p->execute([$uid]);
  $r = $p->fetch(PDO::FETCH_ASSOC);
  $nm = trim((string)($r["pharmacy_name"] ?? ""));
  if ($nm !== "") $pharmacyName = $nm;
} catch (Throwable $e) {}

// inventory stats
$totalStock = 0;
$alerts = 0;
try {
  $q = $pdo->prepare("
    SELECT
      COUNT(*) AS total_items,
      SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_items,
      SUM(CASE WHEN quantity > 0 AND quantity <= COALESCE(reorder_level,0) THEN 1 ELSE 0 END) AS low_items
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=?
  ");
  $q->execute([$uid]);
  $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
  $totalStock = (int)($row["total_items"] ?? 0);
  $alerts = (int)($row["out_items"] ?? 0) + (int)($row["low_items"] ?? 0);
} catch (Throwable $e) {}

// notifications unread count (works if notifications table exists; else 0)
$unreadNotifs = 0;
if (table_exists($pdo, "notifications")) {
  try {
    $n = $pdo->prepare("
      SELECT COUNT(*) 
      FROM notifications
      WHERE user_id=? AND COALESCE(is_dismissed,0)=0 AND COALESCE(is_read,0)=0
    ");
    $n->execute([$uid]);
    $unreadNotifs = (int)$n->fetchColumn();
  } catch (Throwable $e) {}
}

// low stock items (top 3) — mixes OUT first then LOW
$lowStock = [];
try {
  // OUT OF STOCK first
  $out = $pdo->prepare("
    SELECT medicine_name, strength, quantity
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=? AND quantity <= 0
    ORDER BY updated_at DESC, medicine_name ASC
    LIMIT 2
  ");
  $out->execute([$uid]);
  while ($r = $out->fetch(PDO::FETCH_ASSOC)) {
    $name = trim((string)($r["medicine_name"] ?? ""));
    if ($name === "") continue;
    $lowStock[] = [
      "name" => $name,
      "strength" => trim((string)($r["strength"] ?? "")),
      "qty" => (int)($r["quantity"] ?? 0)
    ];
    if (count($lowStock) >= 3) break;
  }

  // then LOW STOCK
  if (count($lowStock) < 3) {
    $low = $pdo->prepare("
      SELECT medicine_name, strength, quantity
      FROM pharmacy_inventory
      WHERE pharmacist_user_id=?
        AND quantity > 0
        AND quantity <= COALESCE(reorder_level,0)
      ORDER BY quantity ASC, medicine_name ASC
      LIMIT 5
    ");
    $low->execute([$uid]);
    while ($r = $low->fetch(PDO::FETCH_ASSOC)) {
      if (count($lowStock) >= 3) break;
      $name = trim((string)($r["medicine_name"] ?? ""));
      if ($name === "") continue;
      $lowStock[] = [
        "name" => $name,
        "strength" => trim((string)($r["strength"] ?? "")),
        "qty" => (int)($r["quantity"] ?? 0)
      ];
    }
  }
} catch (Throwable $e) {}

// recent requests (optional) — if your table exists; else empty
$recentRequests = [];
try {
  $reqTable = null;
  foreach (["medicine_requests", "pharmacy_requests", "pharmacy_medicine_requests"] as $t) {
    if (table_exists($pdo, $t)) { $reqTable = $t; break; }
  }

  if ($reqTable) {
    // Expected columns: pharmacist_user_id, patient_user_id, medicine_name, created_at
    $rq = $pdo->prepare("
      SELECT r.patient_user_id, r.medicine_name, r.created_at, u.full_name AS patient_name
      FROM {$reqTable} r
      LEFT JOIN users u ON u.id = r.patient_user_id
      WHERE r.pharmacist_user_id=?
      ORDER BY r.created_at DESC
      LIMIT 2
    ");
    $rq->execute([$uid]);

    while ($r = $rq->fetch(PDO::FETCH_ASSOC)) {
      $recentRequests[] = [
        "patientName" => trim((string)($r["patient_name"] ?? "Patient")),
        "medicine" => trim((string)($r["medicine_name"] ?? "--")),
        "timeAgo" => time_ago((string)($r["created_at"] ?? "")),
      ];
    }
  }
} catch (Throwable $e) {}

json_response(200, [
  "ok" => true,
  "data" => [
    "pharmacyName" => $pharmacyName,
    "notifications" => $unreadNotifs,
    "totalStock" => $totalStock,
    "alerts" => $alerts,
    "lowStock" => $lowStock,
    "recentRequests" => $recentRequests,
  ]
]);
