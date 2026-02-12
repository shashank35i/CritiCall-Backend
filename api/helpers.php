<?php
// helpers.php (UPDATED, SAFE) — keeps all existing function names/signatures
// Goal: never output HTML fatals; always return JSON on uncaught errors.
// Keep this file at: /api/helpers.php

// ---------------------------------------------------------
// 0) Global error handling: convert Fatal/Uncaught to JSON
// (Does NOT change normal successful responses.)
// ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

if (!function_exists('__is_debug')) {
  function __is_debug(): bool {
    // enable debug automatically for local dev
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $isLocal = (stripos($host, 'localhost') !== false) || (strpos($host, '127.0.0.1') !== false);

    // env override
    $env = (string)($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '');
    $env = strtolower(trim($env));
    if ($env === '1' || $env === 'true' || $env === 'yes') return true;
    if ($env === '0' || $env === 'false' || $env === 'no') return false;

    return $isLocal;
  }
}

if (!function_exists('__emit_fatal_json')) {
  function __emit_fatal_json(int $status, string $msg, array $debug = []): void {
    // wipe any buffered output so response is pure JSON
    while (ob_get_level() > 0) { @ob_end_clean(); }

    if (!headers_sent()) {
      http_response_code($status);
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
      header('Connection: close');
    }

    $payload = ["ok" => false, "error" => $msg];
    if (__is_debug() && !empty($debug)) $payload["debug"] = $debug;

    $out = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($out === false) $out = '{"ok":false,"error":"Server error"}';

    echo $out;

    if (function_exists('fastcgi_finish_request')) {
      @fastcgi_finish_request();
    } else {
      @flush();
    }
    exit;
  }
}

set_exception_handler(function ($e) {
  __emit_fatal_json(500, "Server error", [
    "type" => is_object($e) ? get_class($e) : "Exception",
    "message" => is_object($e) ? $e->getMessage() : "unknown",
    "file" => is_object($e) ? $e->getFile() : "",
    "line" => is_object($e) ? $e->getLine() : 0,
  ]);
});

register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array((int)$err["type"], $fatalTypes, true)) return;

  __emit_fatal_json(500, "Server error", $err);
});

// ---------------------------------------------------------
// 1) CORS helper (used by your endpoints)
// ---------------------------------------------------------
if (!function_exists('cors_json')) {
  function cors_json(): void {
    // If you want strict origin later, change here.
    if (!headers_sent()) {
      header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
      header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
      header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
      http_response_code(204);
      exit;
    }
  }
}

// ------------------------------
// 2) JSON response helper
// ------------------------------
if (!function_exists('json_response')) {
  function json_response(int $status, array $payload) : void {
    // clear buffers FIRST so headers can be sent safely
    while (ob_get_level() > 0) { @ob_end_clean(); }

    http_response_code($status);

    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
      header('Connection: close');
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Pragma: no-cache');
    }

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');

    $out = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($out === false) {
      $out = '{"ok":false,"error":"JSON encode failed"}';
    }

    echo $out;

    if (function_exists('fastcgi_finish_request')) {
      @fastcgi_finish_request();
    } else {
      @flush();
    }

    exit;
  }
}

// ------------------------------
// 3) Mail helper
// ------------------------------
if (!function_exists('send_email')) {
  function send_email(string $toEmail, string $toName, string $subject, string $htmlBody) : bool {
    require_once __DIR__ . "/mailer.php"; // ensures sendMail exists

    $ok = sendMail($toEmail, $toName, $subject, $htmlBody);
    if (!$ok) {
      $GLOBALS["MAILER_LAST_ERROR"] = $GLOBALS["MAILER_LAST_ERROR"] ?? "Mailer failed";
    }
    return $ok;
  }
}

// ------------------------------
// 4) Request helpers
// ------------------------------
if (!function_exists('read_json')) {
  function read_json() : array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
}

if (!function_exists('require_fields')) {
  function require_fields(array $data, array $fields) : void {
    foreach ($fields as $f) {
      if (!isset($data[$f]) || trim((string)$data[$f]) === "") {
        json_response(422, ["ok" => false, "error" => "Missing field: {$f}"]);
      }
    }
  }
}

if (!function_exists('normalize_email')) {
  function normalize_email(string $email) : string {
    return strtolower(trim($email));
  }
}

if (!function_exists('is_valid_role')) {
  function is_valid_role(string $role) : bool {
    $role = strtoupper(trim($role));
    return in_array($role, ["PATIENT","DOCTOR","PHARMACIST","ADMIN"], true);
  }
}

if (!function_exists('new_token')) {
  function new_token(int $bytes = 32) : string {
    return bin2hex(random_bytes($bytes));
  }
}

if (!function_exists('sha256')) {
  function sha256(string $s) : string {
    return hash("sha256", $s);
  }
}

if (!function_exists('otp6')) {
  function otp6() : string {
    return str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
  }
}

if (!function_exists('generate_unique_mci')) {
  function generate_unique_mci(PDO $pdo): string {
    $year = date("Y");

    for ($i = 0; $i < 15; $i++) {
      $rand = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
      $mci = "MCI-$year-$rand";

      $st = $pdo->prepare("SELECT 1 FROM professional_verifications WHERE mci_number=? LIMIT 1");
      $st->execute([$mci]);
      if (!$st->fetchColumn()) return $mci;
    }

    return "MCI-$year-" . bin2hex(random_bytes(4));
  }
}

// =========================================================
// 5) JWT Helpers (HS256) — NO external library needed
// =========================================================
if (!function_exists('jwt_secret')) {
  function jwt_secret(): string {
    $cfgPath = __DIR__ . "/config.php";
    if (!file_exists($cfgPath)) $cfgPath = __DIR__ . "/../config.php";

    $secret = "";
    if (file_exists($cfgPath)) {
      $cfg = require $cfgPath;
      if (is_array($cfg) && isset($cfg["jwt"]["secret"])) {
        $secret = (string)$cfg["jwt"]["secret"];
      }
    }

    if ($secret === "") $secret = (string)($_ENV["JWT_SECRET"] ?? "");
    if ($secret === "") $secret = (string)(getenv("JWT_SECRET") ?: "");

    $secret = trim($secret);
    if ($secret === "") {
      json_response(500, ["ok"=>false, "error"=>"JWT_SECRET missing on server. Set config.php jwt.secret"]);
    }
    return $secret;
  }
}

if (!function_exists('b64url_encode')) {
  function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
  }
}

if (!function_exists('b64url_decode')) {
  function b64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat("=", 4 - $remainder);
    $out = base64_decode(strtr($data, "-_", "+/"));
    return $out === false ? "" : $out;
  }
}

if (!function_exists('jwt_issue')) {
  function jwt_issue(array $claims, int $ttlSeconds): string {
    $header = ["alg" => "HS256", "typ" => "JWT"];
    $now = time();

    $payload = array_merge($claims, [
      "iat" => $now,
      "exp" => $now + $ttlSeconds,
    ]);

    $h64 = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p64 = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    $sig = hash_hmac("sha256", "$h64.$p64", jwt_secret(), true);
    $s64 = b64url_encode($sig);

    return "$h64.$p64.$s64";
  }
}

if (!function_exists('jwt_decode')) {
  function jwt_decode(string $jwt): ?array {
    $jwt = trim($jwt);
    $parts = explode(".", $jwt);
    if (count($parts) !== 3) return null;

    [$h64, $p64, $s64] = $parts;

    $headerJson = b64url_decode($h64);
    $payloadJson = b64url_decode($p64);
    $sigBin = b64url_decode($s64);

    if ($headerJson === "" || $payloadJson === "" || $sigBin === "") return null;

    $header = json_decode($headerJson, true);
    $payload = json_decode($payloadJson, true);

    if (!is_array($header) || !is_array($payload)) return null;
    if (($header["alg"] ?? "") !== "HS256") return null;

    $expected = hash_hmac("sha256", "$h64.$p64", jwt_secret(), true);
    if (!hash_equals($expected, $sigBin)) return null;

    $now = time();
    if (!isset($payload["exp"]) || !is_numeric($payload["exp"])) return null;
    if ($now >= (int)$payload["exp"]) return null;

    return $payload;
  }
}

// =========================================================
// 6) Authorization Helpers (Bearer token)
// =========================================================
if (!function_exists('get_authorization_header')) {
  function get_authorization_header(): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($hdr !== '') return trim($hdr);

    $hdr = $_SERVER['Authorization'] ?? '';
    if ($hdr !== '') return trim($hdr);

    $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($hdr !== '') return trim($hdr);

    if (function_exists('apache_request_headers')) {
      $headers = apache_request_headers();
      if (is_array($headers)) {
        foreach ($headers as $k => $v) {
          if (strcasecmp($k, 'Authorization') === 0) return trim((string)$v);
        }
      }
    }
    return '';
  }
}

if (!function_exists('get_bearer_token')) {
  function get_bearer_token(): string {
    $hdr = get_authorization_header();
    if ($hdr === '') return '';

    if (stripos($hdr, 'Bearer ') === 0) {
      return trim(substr($hdr, 7));
    }
    return '';
  }
}

if (!function_exists('require_auth')) {
  function require_auth(): array {
    $t = get_bearer_token();
    if ($t === '') json_response(401, ["ok"=>false,"error"=>"Missing token"]);

    if (!function_exists("jwt_decode")) {
      json_response(500, ["ok"=>false,"error"=>"JWT helpers missing"]);
    }

    $p = jwt_decode($t);
    if (!$p || !isset($p["uid"])) {
      json_response(401, ["ok"=>false,"error"=>"Invalid/expired token"]);
    }

    return $p;
  }
}
