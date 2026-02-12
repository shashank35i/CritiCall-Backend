<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

cors_json();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$uid  = (int)($auth["uid"] ?? 0);
if ($uid <= 0) json_response(401, ["ok"=>false,"error"=>"Unauthorized"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

// accept both names
$onlyUnread =
  ((isset($_GET["only_unread"]) && $_GET["only_unread"] == "1") ||
   (isset($_GET["unread"]) && $_GET["unread"] == "1"));

$where = "user_id=? AND deleted_at IS NULL";
$params = [$uid];

if ($onlyUnread) {
  $where .= " AND COALESCE(is_read,0)=0";
}

$q = $pdo->prepare("
  SELECT
    id,
    user_id,
    title,
    body,
    data_json,
    COALESCE(is_read,0) AS is_read,
    created_at
  FROM notifications
  WHERE $where
  ORDER BY created_at DESC
  LIMIT 200
");
$q->execute($params);

$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// add a derived "type" so Android can color icons (without requiring type column)
$out = [];
foreach ($rows as $r) {
  $type = "";
  $dj = $r["data_json"] ?? null;
  if (is_string($dj) && trim($dj) !== "") {
    $decoded = json_decode($dj, true);
    if (is_array($decoded) && isset($decoded["type"])) {
      $type = (string)$decoded["type"];
    }
  }
  $r["type"] = $type;
  $out[] = $r;
}

json_response(200, ["ok"=>true, "data"=>["items"=>$out]]);
