<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") json_response(405, ["ok"=>false, "error"=>"GET only"]);

$view   = strtoupper(trim((string)($_GET["view"] ?? "ALL")));
$q      = trim((string)($_GET["q"] ?? ""));
$limit  = (int)($_GET["limit"] ?? 200);
$offset = (int)($_GET["offset"] ?? 0);

if ($limit < 1) $limit = 50;
if ($limit > 500) $limit = 500;
if ($offset < 0) $offset = 0;

// Start window config (keep consistent with patient)
$startEarlySec = 120; // 2 minutes early

try {
  $where = " a.doctor_id = :docId ";
  $params = [":docId" => $uid];

  // End time expression
  $endExpr = "DATE_ADD(a.scheduled_at, INTERVAL COALESCE(a.duration_min,15) MINUTE)";

  if ($view === "UPCOMING") {
    // ✅ include IN_PROGRESS + only those not ended yet
    $where .= " AND a.status IN ('BOOKED','CONFIRMED','IN_PROGRESS')
                AND NOW() < $endExpr ";
    $orderBy = " ORDER BY a.scheduled_at ASC ";
  } else if ($view === "COMPLETED") {
    // ✅ completed bucket: completed + no_show (optional)
    $where .= " AND a.status IN ('COMPLETED','NO_SHOW') ";
    $orderBy = " ORDER BY a.scheduled_at DESC ";
  } else {
    // ALL
    $where .= " AND a.status IN ('BOOKED','CONFIRMED','IN_PROGRESS','COMPLETED','NO_SHOW','CANCELLED') ";
    $orderBy = " ORDER BY a.scheduled_at DESC ";
  }

  if ($q !== "") {
    $where .= " AND (p.full_name LIKE :q OR a.symptoms LIKE :q OR a.public_code LIKE :q) ";
    $params[":q"] = "%".$q."%";
  }

  // total
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    WHERE $where
  ");
  $st->execute($params);
  $total = (int)($st->fetchColumn() ?: 0);

  // items
  $sql = "
    SELECT
      a.id,
      a.public_code,
      a.status,
      a.consult_type,
      a.symptoms,
      a.scheduled_at,
      a.duration_min,
      p.full_name AS patient_name,
      p.phone     AS patient_phone,
      pp.age      AS patient_age,
      pp.gender   AS patient_gender,

      -- ✅ deterministic room for video (patient uses same)
      CONCAT('ss_appt_', REPLACE(REPLACE(REPLACE(a.public_code,'-','_'),' ','_'),'#','_')) AS room,

      -- ✅ server clock for reliable UI gating
      (UNIX_TIMESTAMP(NOW(3)) * 1000) AS server_now_ms,

      -- seconds until start (negative after start)
      TIMESTAMPDIFF(SECOND, NOW(), a.scheduled_at) AS seconds_to_start,

      -- ✅ can_start window: [start-early, end)
      (NOW() >= DATE_SUB(a.scheduled_at, INTERVAL :early SECOND) AND NOW() < $endExpr) AS can_start

    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    LEFT JOIN patient_profiles pp ON pp.user_id = a.patient_id
    WHERE $where
    $orderBy
    LIMIT $limit OFFSET $offset
  ";

  $params2 = $params;
  $params2[":early"] = $startEarlySec;

  $st = $pdo->prepare($sql);
  $st->execute($params2);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_response(200, [
    "ok" => true,
    "data" => [
      "total" => $total,
      "items" => $items
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to load consultations",
    "debug"=>$e->getMessage()
  ]);
}
