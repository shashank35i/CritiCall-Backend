<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$q = read_json();
if (!is_array($q)) $q = [];

$doctorId  = (int)($q["doctorId"] ?? $q["doctor_id"] ?? 0);
$daysAhead = (int)($q["daysAhead"] ?? $q["days"] ?? 7);

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Missing doctorId"]);
if ($daysAhead < 1) $daysAhead = 1;
if ($daysAhead > 14) $daysAhead = 14;

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  return in_array($ip, ["127.0.0.1", "::1"], true);
}

/**
 * Accepts: H:mm, HH:mm, H:mm:ss, HH:mm:ss
 * Returns: HH:mm or ""
 */
function norm_time($t) {
  if ($t === null) return "";
  $t = trim((string)$t);
  if ($t === "") return "";

  // allow 9:00, 09:00, 9:00:00, 09:00:00
  if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) return "";

  $hh = (int)$m[1];
  $mm = (int)$m[2];

  if ($hh < 0 || $hh > 23) return "";
  if ($mm < 0 || $mm > 59) return "";

  return sprintf("%02d:%02d", $hh, $mm);
}

function to_minutes($t) {
  $t = norm_time($t);
  if ($t === "") return 0;
  [$h,$m] = array_pad(explode(":", $t), 2, "0");
  return ((int)$h) * 60 + ((int)$m);
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
  // ✅ doctor existence + policy
  $chk = $pdo->prepare("
    SELECT u.id, u.is_active, u.admin_verification_status
    FROM users u
    WHERE u.id=?
      AND u.role='DOCTOR'
    LIMIT 1
  ");
  $chk->execute([$doctorId]);
  $doc = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$doc) json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);

  if ((int)$doc["is_active"] !== 1 || strtoupper((string)$doc["admin_verification_status"]) !== "VERIFIED") {
    json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);
  }

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
  if (!$avRows || count($avRows) === 0) {
    // fallback defaults
    for ($d=1; $d<=7; $d++) {
      $weekly[$d] = ["enabled" => ($d <= 5), "start" => "09:00", "end" => "17:00"];
    }
  } else {
    foreach ($avRows as $r) {
      $dow = (int)($r["day_of_week"] ?? 0);
      if ($dow < 1 || $dow > 7) continue;

      $en = ((int)($r["enabled"] ?? 0) === 1);
      $st = norm_time($r["start_time"] ?? "09:00");
      $et = norm_time($r["end_time"] ?? "17:00");

      // If enabled but times invalid -> disable
      if ($en && ($st === "" || $et === "")) $en = false;

      // NOTE: DO NOT disable overnight shifts.
      // Overnight means: end_time < start_time (e.g., 20:00 -> 06:00)
      // Only disable if start == end (no working window).
      if ($en && $st !== "" && $et !== "" && to_minutes($et) === to_minutes($st)) $en = false;

      $weekly[$dow] = [
        "enabled" => $en,
        "start" => $st !== "" ? $st : "09:00",
        "end"   => $et !== "" ? $et : "17:00",
      ];
    }
    for ($d=1; $d<=7; $d++) {
      if (!isset($weekly[$d])) $weekly[$d] = ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    }
  }

  // date window
  $startDate = new DateTime("today");
  $endDate   = (clone $startDate)->modify("+".($daysAhead-1)." day");
  $startIso  = $startDate->format("Y-m-d");
  $endIso    = $endDate->format("Y-m-d");

  // booked slots (schema tolerant)
  $booked = [];

  $tbl = "appointments";
  $dateCol = has_col($pdo,$tbl,"appointment_date") ? "appointment_date" : (has_col($pdo,$tbl,"date") ? "date" : null);
  $timeCol = has_col($pdo,$tbl,"appointment_time") ? "appointment_time" : (has_col($pdo,$tbl,"time") ? "time" : null);
  $docCol  = has_col($pdo,$tbl,"doctor_id") ? "doctor_id" : (has_col($pdo,$tbl,"doctorId") ? "doctorId" : null);
  $statusCol = has_col($pdo,$tbl,"status") ? "status" : null;

  $blocking = [
    "BOOKED",
    "CONFIRMED",
    "SCHEDULED",
    "APPROVED",
    "PENDING",
    "IN_PROGRESS",
    "INPROGRESS",
    "ONGOING",
    "STARTED",
    "PAID",
    "PAYMENT_DONE"
  ];

  if ($dateCol && $timeCol && $docCol) {
    $sql = "SELECT $dateCol AS d, $timeCol AS t" . ($statusCol ? ", $statusCol AS s" : "") . "
            FROM $tbl
            WHERE $docCol = ?
              AND $dateCol BETWEEN ? AND ?";

    $stB = $pdo->prepare($sql);
    $stB->execute([$doctorId, $startIso, $endIso]);

    while ($row = $stB->fetch(PDO::FETCH_ASSOC)) {
      $d = (string)($row["d"] ?? "");
      $t = norm_time($row["t"] ?? "");
      if ($d === "" || $t === "") continue;

      if ($statusCol) {
        $s = strtoupper(trim((string)($row["s"] ?? "")));
        if (!in_array($s, $blocking, true)) continue;
      }

      if (!isset($booked[$d])) $booked[$d] = [];
      $booked[$d][$t] = true;
    }
  }

  // helpers for slot building (no behavior change for non-overnight)
  $slotMinutes = 30;

  $tz = new DateTimeZone("Asia/Kolkata");
  $now = new DateTime("now", $tz);
  $todayIso = $now->format("Y-m-d");
  $nowMinToday = to_minutes($now->format("H:i"));

  $addRange = function(string $iso, int $fromMin, int $toMin, array &$sections, array &$seen, array $booked) use ($slotMinutes, $todayIso, $nowMinToday) {
    // clamp
    if ($fromMin < 0) $fromMin = 0;
    if ($toMin > 1440) $toMin = 1440;
    if ($toMin <= $fromMin) return;

    for ($m = $fromMin; $m + $slotMinutes <= $toMin; $m += $slotMinutes) {
      $hhmm = fmt_hhmm($m);
      if (isset($seen[$hhmm])) continue; // avoid duplicates if ranges overlap
      $seen[$hhmm] = true;

      $disabled = isset($booked[$iso]) && isset($booked[$iso][$hhmm]);
      // block past slots for today
      if ($iso === $todayIso && $m <= $nowMinToday) {
        $disabled = true;
      }
      $key = section_key($hhmm);

      $sections[$key][] = [
        "value" => $hhmm,
        "label" => label_12h($hhmm),
        "disabled" => $disabled
      ];
    }
  };

  $isOvernight = function(string $st, string $et): bool {
    $stN = norm_time($st);
    $etN = norm_time($et);
    if ($stN === "" || $etN === "") return false;
    $s = to_minutes($stN);
    $e = to_minutes($etN);
    return $e < $s; // overnight
  };

  // build slots
  $days = [];

  for ($i=0; $i<$daysAhead; $i++) {
    $dt     = (clone $startDate)->modify("+$i day");
    $iso    = $dt->format("Y-m-d");
    $dow    = (int)$dt->format("N");
    $dayNum = (int)$dt->format("j");

    $w = $weekly[$dow] ?? ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    $wEnabled = (bool)($w["enabled"] ?? false);
    $st = (string)($w["start"] ?? "09:00");
    $et = (string)($w["end"] ?? "17:00");

    // previous day (for overnight spill into this date)
    $prevDt  = (clone $dt)->modify("-1 day");
    $prevIso = $prevDt->format("Y-m-d");
    $prevDow = (int)$prevDt->format("N");
    $pw = $weekly[$prevDow] ?? ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    $pEnabled = (bool)($pw["enabled"] ?? false);
    $pst = (string)($pw["start"] ?? "09:00");
    $pet = (string)($pw["end"] ?? "17:00");
    $prevOvernight = ($pEnabled && $isOvernight($pst, $pet));

    $sections = ["MORNING"=>[], "AFTERNOON"=>[], "EVENING"=>[]];
    $seen = []; // to avoid duplicates
    $totalSlots = 0;
    $freeSlots = 0;

    // 1) Carry-over from previous day overnight: 00:00 -> prev_end (for THIS iso)
    if ($prevOvernight) {
      $pEndMin = to_minutes($pet); // e.g., 06:00 => 360
      if ($pEndMin > 0) {
        $addRange($iso, 0, $pEndMin, $sections, $seen, $booked);
      }
    }

    // 2) Today's own availability
    $stN = norm_time($st);
    $etN = norm_time($et);

    if ($wEnabled && $stN !== "" && $etN !== "") {
      $sMin = to_minutes($stN);
      $eMin = to_minutes($etN);

      if ($eMin > $sMin) {
        // normal same-day
        $addRange($iso, $sMin, $eMin, $sections, $seen, $booked);
      } elseif ($eMin < $sMin) {
        // overnight for today: start -> 24:00 on THIS iso
        $addRange($iso, $sMin, 1440, $sections, $seen, $booked);
        // NOTE: the 00:00 -> end portion will appear on the NEXT day's loop
        // through the "previous day overnight" carry logic above.
      } else {
        // start == end => no range (kept disabled earlier)
      }
    }

    // compute totals
    foreach (["MORNING","AFTERNOON","EVENING"] as $k) {
      foreach ($sections[$k] as $slot) {
        $totalSlots++;
        if (empty($slot["disabled"])) $freeSlots++;
      }
    }

    // enabled means: there is at least 1 slot from either today's schedule OR previous overnight carry
    $enabled = ($totalSlots > 0) && ($wEnabled || $prevOvernight);

    $secArr = [];
    foreach (["MORNING","AFTERNOON","EVENING"] as $k) {
      $secArr[] = ["key"=>$k, "slots"=>$sections[$k]];
    }

    $dayObj = [
      "date" => $iso,
      "dayNum" => $dayNum,
      "enabled" => $enabled,
      "sections" => $secArr
    ];

    if (is_localhost()) {
      $dayObj["_debug"] = [
        "dow" => $dow,
        "weekly_enabled_today" => $wEnabled,
        "today_start" => $st,
        "today_end" => $et,
        "today_overnight" => ($wEnabled && $isOvernight($st, $et)),
        "prev_dow" => $prevDow,
        "prev_enabled" => $pEnabled,
        "prev_start" => $pst,
        "prev_end" => $pet,
        "prev_overnight" => $prevOvernight,
        "slot_total" => $totalSlots,
        "slot_free" => $freeSlots,
        "booked_count_for_day" => isset($booked[$iso]) ? count($booked[$iso]) : 0
      ];
    }

    $days[] = $dayObj;
  }

  $resp = ["ok"=>true, "data"=>["days"=>$days]];

  if (is_localhost()) {
    $resp["_meta"] = [
      "server_tz" => date_default_timezone_get(),
      "server_today" => (new DateTime("today"))->format("Y-m-d"),
      "range" => [$startIso, $endIso],
      "doctor_id" => $doctorId,
      "availability_rows" => is_array($avRows) ? count($avRows) : 0,
      "appointments_cols" => [
        "dateCol"=>$dateCol, "timeCol"=>$timeCol, "docCol"=>$docCol, "statusCol"=>$statusCol
      ]
    ];
  }

  json_response(200, $resp);

} catch (Throwable $e) {
  error_log("patient/doctor_slots.php ERROR: ".$e->getMessage());
  json_response(500, [
    "ok"=>false,
    "error"=>"Server error",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
