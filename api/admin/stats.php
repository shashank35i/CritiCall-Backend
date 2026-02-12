<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

if (!function_exists("require_auth")) {
  function require_auth() {
    $t = get_bearer_token();
    if ($t === "") json_response(401, ["ok"=>false,"error"=>"Missing token"]);

    if (!function_exists("jwt_decode")) json_response(500, ["ok"=>false,"error"=>"JWT helpers missing"]);
    $p = jwt_decode($t);
    if (!$p || !isset($p["uid"])) json_response(401, ["ok"=>false,"error"=>"Invalid/expired token"]);
    return $p;
  }
}

if (!function_exists("require_admin")) {
  function require_admin($auth) {
    $r = strtoupper(trim((string)($auth["role"] ?? "")));
    if ($r !== "ADMIN") json_response(403, ["ok"=>false,"error"=>"Admin only"]);
  }
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method !== "GET") json_response(405, ["ok"=>false,"error"=>"GET only"]);

$auth = require_auth();
require_admin($auth);

$role = strtolower(trim($_GET["role"] ?? "all")); // all|doctor|pharmacist
$roles = ["DOCTOR","PHARMACIST"];
if ($role === "doctor") $roles = ["DOCTOR"];
if ($role === "pharmacist") $roles = ["PHARMACIST"];

$pdo = db();
$in = implode(",", array_fill(0, count($roles), "?"));

$sql = "
  SELECT admin_verification_status AS st, COUNT(*) AS c
  FROM users
  WHERE is_active=1 AND role IN ($in)
  GROUP BY admin_verification_status
";
$stmt = $pdo->prepare($sql);
$stmt->execute($roles);

$pending = 0;   // PENDING + UNDER_REVIEW
$approved = 0;  // VERIFIED
$rejected = 0;  // REJECTED
$total = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $st = strtoupper((string)($row["st"] ?? ""));
  $c  = (int)($row["c"] ?? 0);
  $total += $c;

  if ($st === "VERIFIED") $approved += $c;
  else if ($st === "REJECTED") $rejected += $c;
  else $pending += $c;
}

json_response(200, [
  "ok" => true,
  "pending" => $pending,
  "approved" => $approved,
  "rejected" => $rejected,
  "total" => $total
]);
