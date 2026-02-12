<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth(); // patient/doctor logged in (JWT)
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // ✅ surface SQL errors

$q = read_json();
if (!is_array($q)) $q = []; // ✅ PHP 8 safety

$doctorId  = (int)($q["doctorId"] ?? $q["doctor_id"] ?? 0);
$daysAhead = (int)($q["daysAhead"] ?? $q["days"] ?? 7);

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Missing doctorId"]);
if ($daysAhead < 1) $daysAhead = 1;
if ($daysAhead > 14) $daysAhead = 14;

function norm_time($t) {
  if (!is_string($t)) return "";
  $t = trim($t);
  if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return substr($t, 0, 5);
  return "";
}
function valid_time($t) { return norm_time($t) !== ""; }
function to_minutes($t) {
  $t = norm_time($t);
  if ($t === "") return 0;
  $p = explode(":", $t);
  return ((int)($p[0] ?? 0))*60 + ((int)($p[1] ?? 0));
}
function fmt_hhmm($mins) {
  $h = intdiv($mins, 60); $m = $mins % 60;
  return sprintf("%02d:%02d", $h, $m);
}
function label_12h($hhmm) {
  $p = explode(":", $hhmm);
  $h = (int)($p[0] ?? 0); $m = (int)($p[1] ?? 0);
  $ampm = $h >= 12 ? "PM" : "AM";
  $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
  return $h12 . ":" . sprintf("%02d", $m) . " " . $ampm;
}
function section_key($hhmm) {
  $h = (int)explode(":", $hhmm)[0];
  if ($h < 12) return "MORNING";
  if ($h < 17) return "AFTERNOON";
  return "EVENING";
}
function has_col(PDO $pdo, string $table, string $col): bool {
  $s = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $s->execute([$table, $col]);
  return (bool)$s->fetchColumn();
}

try {
  // doctor must be verified/active
  $chk = $pdo->prepare("
    SELECT u.id
    FROM users u
    WHERE u.id=?
      AND u.role='DOCTOR'
      AND u.is_active=1
      AND u.admin_verification_status='VERIFIED'
    LIMIT 1
  ");
  $chk->execute([$doctorId]);
  if (!$chk->fetch()) json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);

  // weekly availability
  $avStmt = $pdo->prepare("
    SELECT day_of_week, enabled, start_time, end_time
    FROM doctor_availability
    WHERE user_id=?
    ORDER BY day_of_week ASC
  ");
  $avStmt->execute([$doctorId]);
  $avRows = $avStmt->fetchAll(PDO::FETCH_ASSOC);

  $weekly = [];

  // Defaults if no availability rows
  if (!$avRows || count($avRows) === 0) {
    for ($d=1; $d<=7; $d++) {
      $weekly[$d] = [
        "enabled" => ($d >= 1 && $d <= 5),
        "start"   => "09:00",
        "end"     => "17:00",
      ];
    }
  } else {
    foreach ($avRows as $r) {
      $dow = (int)($r["day_of_week"] ?? 0);
      if ($dow < 1 || $dow > 7) continue;

      $en = ((int)($r["enabled"] ?? 0) === 1);
      $st = norm_time((string)($r["start_time"] ?? "09:00"));
      $et = norm_time((string)($r["end_time"] ?? "17:00"));

      // If enabled but invalid times -> disable
      if ($en && (!valid_time($st) || !valid_time($et) || to_minutes($et) <= to_minutes($st))) {
        $en = false;
      }

      $weekly[$dow] = [
        "enabled" => $en,
        "start"   => $st !== "" ? $st : "09:00",
        "end"     => $et !== "" ? $et : "17:00",
      ];
    }

    // Ensure all 7 exist
    for ($d=1; $d<=7; $d++) {
      if (!isset($weekly[$d])) $weekly[$d] = ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    }
  }

  $slotMinutes = 30;

  $startDate = new DateTime("today");
  $endDate   = (clone $startDate)->modify("+".($daysAhead-1)." day");
  $startIso  = $startDate->format("Y-m-d");
  $endIso    = $endDate->format("Y-m-d");

  // booked slots (schema-tolerant)
  $booked = []; // booked[date][time]=true
  $tbl = "appointments";

  $dateCol   = has_col($pdo, $tbl, "appointment_date") ? "appointment_date" : (has_col($pdo, $tbl, "date") ? "date" : null);
  $timeCol   = has_col($pdo, $tbl, "appointment_time") ? "appointment_time" : (has_col($pdo, $tbl, "time") ? "time" : null);
  $docCol    = has_col($pdo, $tbl, "doctor_id") ? "doctor_id" : null;
  $statusCol = has_col($pdo, $tbl, "status") ? "status" : null;

  if ($dateCol && $timeCol && $docCol) {
    $sql = "SELECT $dateCol AS d, $timeCol AS t" . ($statusCol ? ", $statusCol AS s" : "") . "
            FROM $tbl
            WHERE $docCol = ?
              AND $dateCol BETWEEN ? AND ?";

    $st = $pdo->prepare($sql);
    $st->execute([$doctorId, $startIso, $endIso]);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $d = (string)($row["d"] ?? "");
      $t = norm_time((string)($row["t"] ?? ""));
      if ($d === "" || $t === "") continue;

      if ($statusCol) {
        $s = strtoupper(trim((string)($row["s"] ?? "")));
        if (!in_array($s, ["BOOKED","CONFIRMED"], true)) continue;
      }

      if (!isset($booked[$d])) $booked[$d] = [];
      $booked[$d][$t] = true;
    }
  } else {
    error_log("available_slots: appointments schema mismatch. dateCol=" . ($dateCol ?: "null") . " timeCol=" . ($timeCol ?: "null") . " docCol=" . ($docCol ?: "null"));
  }

  // Build days
  $days = [];
  for ($i=0; $i<$daysAhead; $i++) {
    $dt     = (clone $startDate)->modify("+$i day");
    $iso    = $dt->format("Y-m-d");
    $dow    = (int)$dt->format("N"); // 1=Mon ... 7=Sun
    $dayNum = (int)$dt->format("j");

    $w = $weekly[$dow] ?? ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    $enabled = (bool)$w["enabled"];
    $st = (string)$w["start"];
    $et = (string)$w["end"];

    $sections = ["MORNING"=>[], "AFTERNOON"=>[], "EVENING"=>[]];

    if ($enabled && valid_time($st) && valid_time($et) && to_minutes($et) > to_minutes($st)) {
      $sMin = to_minutes($st);
      $eMin = to_minutes($et);

      for ($m=$sMin; $m + $slotMinutes <= $eMin; $m += $slotMinutes) {
        $hhmm = fmt_hhmm($m);
        $disabled = isset($booked[$iso]) && isset($booked[$iso][$hhmm]);
        $key = section_key($hhmm);

        $sections[$key][] = [
          "value" => $hhmm,
          "label" => label_12h($hhmm),
          "disabled" => $disabled
        ];
      }
    } else {
      $enabled = false;
    }

    $secArr = [];
    foreach (["MORNING","AFTERNOON","EVENING"] as $k) {
      $secArr[] = ["key"=>$k, "slots"=>$sections[$k]];
    }

    $hasAny = false;
    foreach ($secArr as $s) { if (!empty($s["slots"])) { $hasAny = true; break; } }
    if (!$hasAny) $enabled = false;

    $days[] = [
      "date" => $iso,
      "dayNum" => $dayNum,
      "enabled" => $enabled,
      "sections" => $secArr
    ];
  }

  json_response(200, ["ok"=>true, "data"=>["days"=>$days]]);
} catch (Throwable $e) {
  error_log("doctor/available_slots.php ERROR: " . $e->getMessage());

  $isLocal = in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);

  json_response(500, [
    "ok" => false,
    "error" => "Server error",
    "debug" => $isLocal ? $e->getMessage() : null
  ]);
}
