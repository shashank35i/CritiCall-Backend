<?php
require __DIR__ . "/helpers.php";

// Basic CORS + fast exit for preflight
cors_json();
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
  json_response(204, ["ok" => true]);
}

// Resolve request path relative to this /api directory
$requestPath = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
$apiBase = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/") . "/";

$route = "";
if (strpos($requestPath, $apiBase) === 0) {
  $route = substr($requestPath, strlen($apiBase));
}
$route = trim($route, "/");

// Default to health check when hitting /api/ with no route
if ($route === "" || $route === "health" || $route === "health.php") {
  require __DIR__ . "/health.php";
  exit;
}

// Prevent path traversal and normalise extension
$clean = str_replace("\\", "/", $route);
$clean = preg_replace("#/+#", "/", $clean);
if (strpos($clean, "..") !== false || $clean === "") {
  json_response(404, ["ok" => false, "error" => "Route not found", "route" => $route]);
}
if (!str_ends_with($clean, ".php")) {
  $clean .= ".php";
}

$target = __DIR__ . "/" . $clean;

if (is_file($target)) {
  require $target;
} else {
  json_response(404, ["ok" => false, "error" => "Route not found", "route" => $route]);
}
