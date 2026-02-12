<?php
/**
 * Unified application bootstrap: configuration + database helper.
 * - app_config(): returns the config array (with static cache).
 * - db(): shared PDO connection built from config.
 */

if (!function_exists('app_config')) {
  function app_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    // defaults
    $cfg = [
      'CRON_KEY' => 'sehatsethu_cron_9f3c7a1b2e5d4c8f',
      'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'sehatsethu',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
        'dsn' => null, // optional full DSN override
        'unix_socket' => null,
        'timeout' => 5, // seconds
        'ssl_ca' => null,
        'ssl_cert' => null,
        'ssl_key' => null,
        'ssl_verify' => true,
        'persistent' => false,
      ],
      'app' => [
        'base_url' => 'http://localhost/sehatsethu_api',
        'api_prefix' => '/api',
      ],
      'jwt' => [
        'secret' => '7734f8c42da176949239518384b261819532b4ddb0f9ad08276a4f3748e1b356',
        'issuer' => 'sehatsethu',
        'audience' => 'sehatsethu_mobile',
        'ttl_seconds' => 60 * 60 * 24, // 24h
      ],
      'mail' => [
        'from_email' => 'sehatsethu@gmail.com',
        'from_name' => 'SehatSethu',
        'smtp' => [
          'enabled' => true,
          'host' => 'smtp.gmail.com',
          'port' => 587,
          'username' => 'sehatsethu@gmail.com',
          'password' => 'hmzhjdfhynkjdpjv',
          'secure' => 'tls',
        ],
      ],
    ];

    // env helper for flexibility
    $env = function (string $key, $default = null) {
      $v = getenv($key);
      if ($v === false) $v = $_ENV[$key] ?? null;
      if ($v === null || $v === '') return $default;
      return $v;
    };

    // DB overrides (host/port/name/user/pass/charset/full DSN)
    $cfg['db']['dsn']     = $env('DB_DSN', $cfg['db']['dsn']);
    $cfg['db']['host']    = $env('DB_HOST', $cfg['db']['host']);
    $cfg['db']['port']    = $env('DB_PORT', $cfg['db']['port']);
    $cfg['db']['name']    = $env('DB_NAME', $cfg['db']['name']);
    $cfg['db']['user']    = $env('DB_USER', $cfg['db']['user']);
    $cfg['db']['pass']    = $env('DB_PASS', $cfg['db']['pass']);
    $cfg['db']['charset'] = $env('DB_CHARSET', $cfg['db']['charset']);
    $cfg['db']['unix_socket'] = $env('DB_UNIX_SOCKET', $cfg['db']['unix_socket']);
    $cfg['db']['timeout'] = (int)$env('DB_TIMEOUT', $cfg['db']['timeout']);
    $cfg['db']['ssl_ca'] = $env('DB_SSL_CA', $cfg['db']['ssl_ca']);
    $cfg['db']['ssl_cert'] = $env('DB_SSL_CERT', $cfg['db']['ssl_cert']);
    $cfg['db']['ssl_key'] = $env('DB_SSL_KEY', $cfg['db']['ssl_key']);
    $cfg['db']['ssl_verify'] = strtolower((string)$env('DB_SSL_VERIFY', $cfg['db']['ssl_verify'] ? '1' : '0')) !== '0';
    $cfg['db']['persistent'] = strtolower((string)$env('DB_PERSISTENT', $cfg['db']['persistent'] ? '1' : '0')) === '1';

    // JWT override
    $cfg['jwt']['secret'] = $env('JWT_SECRET', $cfg['jwt']['secret']);

    return $cfg;
  }
}

if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = app_config();
    $db = $cfg['db'];

    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => max(1, (int)$db['timeout']),
    ];
    if ($db['persistent']) $options[PDO::ATTR_PERSISTENT] = true;

    // SSL options if provided
    if ($db['ssl_ca'])   $options[PDO::MYSQL_ATTR_SSL_CA] = $db['ssl_ca'];
    if ($db['ssl_cert']) $options[PDO::MYSQL_ATTR_SSL_CERT] = $db['ssl_cert'];
    if ($db['ssl_key'])  $options[PDO::MYSQL_ATTR_SSL_KEY] = $db['ssl_key'];
    if ($db['ssl_ca'] && !$db['ssl_verify']) {
      // disable peer verification only if explicitly requested
      $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    // Build DSN
    if (!empty($db['dsn'])) {
      $dsn = $db['dsn'];
    } elseif (!empty($db['unix_socket'])) {
      $dsn = sprintf(
        'mysql:unix_socket=%s;dbname=%s;charset=%s',
        $db['unix_socket'],
        $db['name'],
        $db['charset']
      );
    } else {
      $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
      );
    }

    // connect with simple retry for transient network failures
    $attempts = 0;
    $lastErr = null;
    while ($attempts < 3) {
      try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
        break;
      } catch (Throwable $e) {
        $lastErr = $e;
        $attempts++;
        usleep(200000); // 200ms backoff
      }
    }
    if (!$pdo instanceof PDO) {
      // If helpers/json_response are available, emit clean JSON; otherwise rethrow
      if (function_exists('json_response')) {
        json_response(503, [
          "ok" => false,
          "error" => "Database connection failed",
          "hint" => "Check DB_* environment variables or network access to MySQL",
          "details" => __is_debug() ? $lastErr->getMessage() : null,
        ]);
      }
      throw $lastErr ?: new RuntimeException("Database connection failed");
    }

    return $pdo;
  }
}

// Keep backward compatibility: requiring this file still yields the config array.
return app_config();
