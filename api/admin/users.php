<?php
// ✅ Prevent random warnings/whitespace from breaking JSON
ob_start();
ini_set("display_errors", "0");
error_reporting(E_ALL);

// Always JSON
header("Content-Type: application/json; charset=utf-8");

// ✅ Handle CORS preflight (if any client sends OPTIONS)
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method === "OPTIONS") {
  http_response_code(204);
  // nothing
  exit;
}

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../helpers.php";

/**
 * ✅ Safe JSON response that clears any accidental output.
 */
if (!function_exists("safe_json_response")) {
  function safe_json_response(int $code, array $data): void {
    // clear anything printed by accident
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// If helpers.php has json_response, still route through safe_json_response
if (!function_exists("json_ok")) {
  function json_ok(array $data): void { safe_json_response(200, $data); }
}
if (!function_exists("json_fail")) {
  function json_fail(int $code, string $err, array $extra = []): void {
    safe_json_response($code, array_merge(["ok"=>false, "error"=>$err], $extra));
  }
}

/**
 * ✅ Safe bearer token reader for XAMPP/Apache.
 * If helpers.php already defines get_bearer_token(), we use it.
 */
if (!function_exists("get_bearer_token")) {
  function get_bearer_token(): string {
    $hdr = "";

    if (!empty($_SERVER["HTTP_AUTHORIZATION"])) $hdr = $_SERVER["HTTP_AUTHORIZATION"];
    if ($hdr === "" && !empty($_SERVER["Authorization"])) $hdr = $_SERVER["Authorization"];

    if ($hdr === "" && function_exists("apache_request_headers")) {
      $h = apache_request_headers();
      if (is_array($h)) {
        foreach ($h as $k => $v) {
          if (strtolower($k) === "authorization") { $hdr = $v; break; }
        }
      }
    }

    if ($hdr === "" && function_exists("getallheaders")) {
      $h = getallheaders();
      if (is_array($h)) {
        foreach ($h as $k => $v) {
          if (strtolower($k) === "authorization") { $hdr = $v; break; }
        }
      }
    }

    $hdr = trim((string)$hdr);
    if (stripos($hdr, "Bearer ") !== 0) return "";
    return trim(substr($hdr, 7));
  }
}

if (!function_exists("require_auth")) {
  function require_auth(): array {
    $t = get_bearer_token();
    if ($t === "") json_fail(401, "Missing token");

    if (!function_exists("jwt_decode")) {
      json_fail(500, "JWT helpers missing");
    }

    $p = jwt_decode($t);
    if (!$p || !isset($p["uid"])) json_fail(401, "Invalid/expired token");
    return $p;
  }
}

if (!function_exists("require_admin")) {
  function require_admin($auth) {
    $r = strtoupper(trim((string)($auth["role"] ?? "")));
    if ($r !== "ADMIN") json_fail(403, "Admin only");
  }
}

if ($method !== "GET" && $method !== "POST") json_fail(405, "GET/POST only");

$auth = require_auth();
require_admin($auth);

$body = [];
if ($method === "POST") {
  $body = read_json();
  if (!is_array($body)) $body = [];
}

// ✅ Validate filter/role strictly so random values never break SQL logic
$filter = strtolower(trim((string)($_GET["filter"] ?? ($body["filter"] ?? "all"))));
$allowedFilters = ["all","verified","unverified","rejected","under_review"];
if (!in_array($filter, $allowedFilters, true)) $filter = "all";

$role = strtolower(trim((string)($_GET["role"] ?? ($body["role"] ?? "all"))));
$allowedRoles = ["all","doctor","pharmacist"];
if (!in_array($role, $allowedRoles, true)) $role = "all";

$limit = (int)($_GET["limit"] ?? ($body["limit"] ?? 200));
if ($limit <= 0) $limit = 200;
if ($limit > 500) $limit = 500;

$roles = ["DOCTOR","PHARMACIST"];
if ($role === "doctor") $roles = ["DOCTOR"];
if ($role === "pharmacist") $roles = ["PHARMACIST"];

$where = "u.is_active=1";
$params = [];

$in = implode(",", array_fill(0, count($roles), "?"));
$where .= " AND u.role IN ($in)";
$params = array_merge($params, $roles);

// ✅ status filter (robust with COALESCE)
if ($filter === "verified") {
  $where .= " AND COALESCE(u.admin_verification_status,'PENDING')='VERIFIED'";
} else if ($filter === "rejected") {
  $where .= " AND COALESCE(u.admin_verification_status,'PENDING')='REJECTED'";
} else if ($filter === "unverified") {
  $where .= " AND COALESCE(u.admin_verification_status,'PENDING') IN ('PENDING','UNDER_REVIEW')";
} else if ($filter === "under_review") {
  $where .= " AND COALESCE(u.admin_verification_status,'PENDING')='UNDER_REVIEW'";
}

try {
  $pdo = db();

  $sql = "
    SELECT
      u.id,
      u.full_name,
      u.role,
      COALESCE(u.admin_verification_status,'PENDING') AS status,

      CASE
        WHEN u.profile_submitted_at IS NULL THEN u.created_at
        WHEN u.profile_submitted_at < u.created_at THEN u.created_at
        ELSE u.profile_submitted_at
      END AS applied_at,

      CASE
        WHEN u.role = 'DOCTOR' THEN IFNULL(dp.specialization, '')
        WHEN u.role = 'PHARMACIST' THEN IFNULL(pp.village_town, '')
        ELSE ''
      END AS subtitle,

      CASE
        WHEN u.role = 'DOCTOR' THEN IFNULL(ddc.cnt, 0)
        WHEN u.role = 'PHARMACIST' THEN IFNULL(pdc.cnt, 0)
        ELSE 0
      END AS docs_count

    FROM users u
    LEFT JOIN doctor_profiles dp ON dp.user_id = u.id
    LEFT JOIN pharmacist_profiles pp ON pp.user_id = u.id

    LEFT JOIN (
      SELECT user_id, COUNT(*) AS cnt
      FROM doctor_documents
      GROUP BY user_id
    ) ddc ON ddc.user_id = u.id

    LEFT JOIN (
      SELECT user_id, COUNT(*) AS cnt
      FROM pharmacist_documents
      GROUP BY user_id
    ) pdc ON pdc.user_id = u.id

    WHERE $where
    ORDER BY applied_at DESC
    LIMIT $limit
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $users = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $users[] = [
      "id" => (int)$r["id"],
      "full_name" => (string)($r["full_name"] ?? "User"),
      "role" => (string)($r["role"] ?? ""),
      "status" => (string)($r["status"] ?? "PENDING"),
      "applied_at" => (string)($r["applied_at"] ?? ""),
      "subtitle" => (string)($r["subtitle"] ?? ""),
      "docs_count" => (int)($r["docs_count"] ?? 0),
    ];
  }

  json_ok(["ok"=>true, "users"=>$users]);

} catch (Throwable $e) {
  // ✅ log server-side, never break JSON for client
  @error_log("[admin/users_list] ".$e->getMessage());
  json_fail(500, "Server error", [
    // keep minimal; remove in production if you want
    "hint" => "Check server logs (Apache/PHP error_log)"
  ]);
}
