<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false, "error"=>"GET only"]);
}

// ✅ Always allow debug if debug=1 (dev only)
$DEBUG = isset($_GET["debug"]) && (string)$_GET["debug"] === "1";
if ($DEBUG) {
  ini_set("display_errors", "1");
  ini_set("display_startup_errors", "1");
  error_reporting(E_ALL);
}

$auth = require_auth();
$uid  = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  // role check
  $st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
    json_response(403, ["ok"=>false, "error"=>"Pharmacist only"]);
  }

  $view  = strtoupper(trim((string)($_GET["view"] ?? "PENDING")));
  $limit = (int)($_GET["limit"] ?? 50);
  if ($limit < 1) $limit = 50;
  if ($limit > 200) $limit = 200;

  $status = ($view === "ALL") ? null : $view;

  // ✅ (optional) force pharmacist_user_id for testing
  $targetUid = $uid;
  if ($DEBUG && isset($_GET["pharmacist_user_id"])) {
    $tmp = (int)$_GET["pharmacist_user_id"];
    if ($tmp > 0) $targetUid = $tmp;
  }

  // pending count for that pharmacist
  $stC = $pdo->prepare("SELECT COUNT(*) FROM medicine_requests WHERE pharmacist_user_id=? AND status='PENDING'");
  $stC->execute([$targetUid]);
  $pendingCount = (int)$stC->fetchColumn();

  // ✅ Minimal, schema-safe query (NO inventory subquery)
  $sql = "
    SELECT
      mr.id,
      mr.patient_user_id,
      mr.pharmacist_user_id,
      mr.medicine_name,
      mr.strength,
      mr.quantity,
      mr.status,
      mr.created_at,
      COALESCE(u.full_name, 'Patient') AS patient_name,
      COALESCE(u.phone, '') AS patient_phone
    FROM medicine_requests mr
    LEFT JOIN users u ON u.id = mr.patient_user_id
    WHERE mr.pharmacist_user_id = ?
  ";
  $args = [$targetUid];

  if ($status !== null) {
    $sql .= " AND mr.status = ? ";
    $args[] = $status;
  }

  $sql .= " ORDER BY mr.created_at DESC LIMIT $limit";

  $st = $pdo->prepare($sql);
  $st->execute($args);

  $items = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$r["id"],
      "patient_name" => (string)($r["patient_name"] ?? "Patient"),
      "patient_phone" => (string)($r["patient_phone"] ?? ""),
      "medicine_name" => (string)($r["medicine_name"] ?? ""),
      "strength" => (string)($r["strength"] ?? ""),
      "quantity" => (int)($r["quantity"] ?? 0),
      "status" => (string)($r["status"] ?? "PENDING"),
      // temporary until inventory join is fixed
      "stock_status" => "OUT_OF_STOCK",
      "created_at" => (string)($r["created_at"] ?? ""),
    ];
  }

  $out = ["ok"=>true, "data"=>["pending_count"=>$pendingCount, "items"=>$items]];

  if ($DEBUG) {
    $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $out["debug"] = [
      "db" => $dbName,
      "auth_uid" => $uid,
      "target_uid" => $targetUid,
      "view" => $view,
      "status_filter" => $status,
      "pending_count" => $pendingCount,
      "items_returned" => count($items),
      "sql_args" => $args
    ];
  }

  json_response(200, $out);

} catch (Throwable $e) {
  if ($DEBUG) {
    json_response(500, [
      "ok" => false,
      "error" => "Server error",
      "debug_error" => $e->getMessage(),
      "debug_file" => $e->getFile(),
      "debug_line" => $e->getLine(),
    ]);
  }
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
