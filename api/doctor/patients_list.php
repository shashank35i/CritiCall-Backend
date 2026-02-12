<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

date_default_timezone_set("Asia/Kolkata");

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "DOCTOR") json_response(403, ["ok"=>false, "error"=>"Doctor only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") json_response(405, ["ok"=>false, "error"=>"GET only"]);

$view   = strtoupper(trim((string)($_GET["view"] ?? "ALL"))); // ALL|ACTIVE|RECOVERED
$q      = trim((string)($_GET["q"] ?? ""));
$limit  = (int)($_GET["limit"] ?? 200);
$offset = (int)($_GET["offset"] ?? 0);

if ($limit < 1) $limit = 50;
if ($limit > 500) $limit = 500;
if ($offset < 0) $offset = 0;

try {
  $today = date("Y-m-d");

  // ✅ IMPORTANT FIX:
  // Earlier ALL used a count query that didn't include :today but you still passed :today param.
  // In PDO this can break (HY093). Active worked because it didn't run that count query.
  // Now BOTH items + total use queries that include the same params (including :today).

  $where = "a.doctor_id = :docId";
  $params = [
    ":docId" => $uid,
    ":today" => $today
  ];

  if ($q !== "") {
    $where .= " AND (p.full_name LIKE :q OR a.symptoms LIKE :q)";
    $params[":q"] = "%".$q."%";
  }

  // One row per patient (derived_status uses :today)
  $base = "
    SELECT
      x.patient_id,
      u.full_name AS patient_name,
      pp.age AS patient_age,
      pp.gender AS patient_gender,
      x.last_visit_at,
      x.last_issue,
      x.derived_status AS status
    FROM (
      SELECT
        a.patient_id,
        MAX(a.scheduled_at) AS last_visit_at,
        SUBSTRING_INDEX(
          GROUP_CONCAT(COALESCE(a.symptoms,'') ORDER BY a.scheduled_at DESC SEPARATOR '|||'),
          '|||',
          1
        ) AS last_issue,
        CASE
          WHEN SUM(
            CASE
              WHEN UPPER(a.status) IN ('BOOKED','CONFIRMED')
               AND DATE(a.scheduled_at) >= :today
              THEN 1 ELSE 0
            END
          ) > 0 THEN 'ACTIVE'
          WHEN SUBSTRING_INDEX(
            GROUP_CONCAT(UPPER(COALESCE(a.status,'')) ORDER BY a.scheduled_at DESC SEPARATOR ','),
            ',',
            1
          ) IN ('COMPLETED','DONE') THEN 'RECOVERED'
          ELSE 'ACTIVE'
        END AS derived_status
      FROM appointments a
      JOIN users p ON p.id = a.patient_id
      WHERE $where
      GROUP BY a.patient_id
    ) x
    JOIN users u ON u.id = x.patient_id
    LEFT JOIN patient_profiles pp ON pp.user_id = x.patient_id
  ";

  $viewFilter = "";
  if ($view === "ACTIVE") $viewFilter = " WHERE x.derived_status = 'ACTIVE' ";
  else if ($view === "RECOVERED") $viewFilter = " WHERE x.derived_status = 'RECOVERED' ";

  // ✅ total (uses same params, includes :today)
  $countSql = "SELECT COUNT(*) FROM ( $base $viewFilter ) t";
  $stC = $pdo->prepare($countSql);
  $stC->execute($params);
  $total = (int)($stC->fetchColumn() ?: 0);

  // ✅ items
  $itemsSql = $base . $viewFilter . " ORDER BY x.last_visit_at DESC LIMIT $limit OFFSET $offset ";
  $st = $pdo->prepare($itemsSql);
  $st->execute($params);
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
    "error"=>"Failed to load patients",
    "debug"=>$e->getMessage()
  ]);
}
