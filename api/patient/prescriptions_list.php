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
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") json_response(405, ["ok"=>false, "error"=>"GET only"]);

$q      = trim((string)($_GET["q"] ?? ""));
$limit  = (int)($_GET["limit"] ?? 200);
$offset = (int)($_GET["offset"] ?? 0);

if ($limit < 1) $limit = 50;
if ($limit > 500) $limit = 500;
if ($offset < 0) $offset = 0;

try {
  $where = "p.patient_id = :uid";
  $params = [":uid" => $uid];

  if ($q !== "") {
    $where .= " AND (
      p.title LIKE :q OR
      d.full_name LIKE :q OR
      COALESCE(dp.specialization,'') LIKE :q
    )";
    $params[":q"] = "%".$q."%";
  }

  // total
  $stC = $pdo->prepare("SELECT COUNT(*) FROM prescriptions p
                        JOIN users d ON d.id=p.doctor_id
                        LEFT JOIN doctor_profiles dp ON dp.user_id=p.doctor_id
                        WHERE $where");
  $stC->execute($params);
  $total = (int)($stC->fetchColumn() ?: 0);

  // list
  $sql = "
    SELECT
      p.id,
      p.title,
      DATE(p.created_at) AS date,
      d.full_name AS doctor_name,
      COALESCE(dp.specialization,'') AS specialization,
      COALESCE(cnt.meds_count, 0) AS medicines_count,
      1 AS doctor_verified
    FROM prescriptions p
    JOIN users d ON d.id = p.doctor_id
    LEFT JOIN doctor_profiles dp ON dp.user_id = p.doctor_id
    LEFT JOIN (
      SELECT prescription_id, COUNT(*) AS meds_count
      FROM prescription_items
      GROUP BY prescription_id
    ) cnt ON cnt.prescription_id = p.id
    WHERE $where
    ORDER BY p.created_at DESC
    LIMIT $limit OFFSET $offset
  ";

  $st = $pdo->prepare($sql);
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
    "ok" => false,
    "error" => "Failed to load prescriptions",
    "debug" => $e->getMessage()
  ]);
}
