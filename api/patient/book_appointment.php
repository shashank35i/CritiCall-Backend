<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();

$auth = require_auth();
$pdo  = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  if ($ip === "127.0.0.1" || $ip === "::1") return true;
  if (strpos($ip, "10.") === 0 || strpos($ip, "192.168.") === 0 || strpos($ip, "172.16.") === 0) return true;
  return false;
}

function norm_hhmm(string $t): string {
  $t = trim($t);
  if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return substr($t, 0, 5);
  return "";
}

function to_minutes(string $hm): int {
  $hm = norm_hhmm($hm);
  if ($hm === "") return 0;
  [$h,$m] = array_pad(explode(":", $hm), 2, "0");
  return ((int)$h) * 60 + ((int)$m);
}

function gen_public_code(): string {
  return (string) random_int(100000, 999999);
}

/** ✅ same technique: accept number OR numeric string */
function int_from_any($v): int {
  if (is_int($v)) return $v;
  if (is_float($v)) return (int)$v;
  if (is_string($v)) {
    $t = trim($v);
    if ($t === "") return 0;
    if (preg_match('/^\d+$/', $t)) return (int)$t;
  }
  if (is_numeric($v)) return (int)$v;
  return 0;
}

/** ✅ read doctor id from ANY common key */
function read_doctor_id(array $data): int {
  $keys = ["doctorId","doctor_id","doctor_id_str","doctorIdLong","doctor_id_long","user_id","id"];
  foreach ($keys as $k) {
    if (array_key_exists($k, $data)) {
      $n = int_from_any($data[$k]);
      if ($n > 0) return $n;
    }
  }
  return 0;
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$data = read_json();
if (!is_array($data)) $data = [];

// ✅ robust doctor id (fix for your Invalid doctorId)
$doctorId = read_doctor_id($data);

$specKey  = trim((string)($data["speciality_key"] ?? $data["specialty"] ?? ""));
$ctypeRaw = strtoupper(trim((string)($data["consult_type"] ?? "")));
if (in_array($ctypeRaw, ["IN_PERSON","INPERSON","CLINIC","VISIT","PHYSICAL"], true)) {
  $ctype = "PHYSICAL";
} else {
  $ctype = $ctypeRaw;
}
$dateIso  = trim((string)($data["date"] ?? ""));      // yyyy-mm-dd
$timeHm   = norm_hhmm((string)($data["time"] ?? "")); // HH:mm
$symptoms = array_key_exists("symptoms", $data) ? trim((string)$data["symptoms"]) : null;

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid doctorId"]);
if ($specKey === "") json_response(422, ["ok"=>false, "error"=>"Invalid speciality_key"]);
if (!in_array($ctype, ["AUDIO","VIDEO","PHYSICAL"], true)) json_response(422, ["ok"=>false, "error"=>"Invalid consult_type"]);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) json_response(422, ["ok"=>false, "error"=>"Invalid date"]);
if ($timeHm === "") json_response(422, ["ok"=>false, "error"=>"Invalid time"]);

try {
  // ✅ doctor must be VERIFIED + must have doctor_profiles row (fee snapshot)
  $st = $pdo->prepare("
    SELECT
      u.id, u.role, u.is_active, u.admin_verification_status,
      dp.fee_amount, dp.specialization
    FROM users u
    JOIN doctor_profiles dp ON dp.user_id = u.id
    WHERE u.id=?
    LIMIT 1
  ");
  $st->execute([$doctorId]);
  $doc = $st->fetch(PDO::FETCH_ASSOC);

  if (!$doc || strtoupper((string)($doc["role"] ?? "")) !== "DOCTOR") {
    json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);
  }
  if ((int)($doc["is_active"] ?? 0) !== 1 || strtoupper((string)($doc["admin_verification_status"] ?? "")) !== "VERIFIED") {
    json_response(403, ["ok"=>false, "error"=>"Doctor not available"]);
  }

  // ✅ snapshot fee from doctor_profiles at booking time
  $feeAmount = (int)($doc["fee_amount"] ?? 0);

  // ✅ optional: ensure speciality_key matches doctor specialization key
  $docSpec = (string)($doc["specialization"] ?? "");
  if ($docSpec !== "" && $specKey !== "" && $specKey !== $docSpec) {
    json_response(422, ["ok"=>false, "error"=>"Invalid speciality_key for this doctor"]);
  }

  // build scheduled_at
  $scheduledAt = DateTime::createFromFormat("Y-m-d H:i", $dateIso . " " . $timeHm);
  if (!$scheduledAt) json_response(422, ["ok"=>false, "error"=>"Invalid date/time"]);
  $scheduledAtStr = $scheduledAt->format("Y-m-d H:i:s");

  // ✅ FIX: availability check supports overnight shifts (20:00 → 06:00)
  $slotMinutes = 30;

  $dowCur  = (int)$scheduledAt->format("N"); // 1..7
  $dowPrev = ($dowCur === 1) ? 7 : ($dowCur - 1);

  $avMap = [];

  $st = $pdo->prepare("
    SELECT day_of_week, enabled, start_time, end_time
    FROM doctor_availability
    WHERE user_id=? AND day_of_week IN (?, ?)
  ");
  $st->execute([$doctorId, $dowCur, $dowPrev]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $avMap[(int)($row["day_of_week"] ?? 0)] = $row;
  }

  $tMin = to_minutes($timeHm);

  $ok = false;

  // helper: normal window [start, end)
  $inNormal = function(int $t, int $start, int $end, int $dur): bool {
    return ($t >= $start) && (($t + $dur) <= $end);
  };

  // (A) Try CURRENT DAY availability first (covers normal day + overnight-evening part)
  $cur = $avMap[$dowCur] ?? null;
  if ($cur && (int)($cur["enabled"] ?? 0) === 1) {
    $curStart = to_minutes((string)($cur["start_time"] ?? "09:00"));
    $curEnd   = to_minutes((string)($cur["end_time"] ?? "17:00"));

    if ($curEnd > $curStart) {
      // normal same-day shift
      if ($inNormal($tMin, $curStart, $curEnd, $slotMinutes)) $ok = true;
    } else {
      // overnight shift: evening part on same date = [start → 24:00)
      if ($tMin >= $curStart && ($tMin + $slotMinutes) <= 1440) $ok = true;
    }
  }

  // (B) If not ok, allow PREVIOUS DAY overnight carry-over morning part (00:00 → prevEnd)
  if (!$ok) {
    $prev = $avMap[$dowPrev] ?? null;
    if ($prev && (int)($prev["enabled"] ?? 0) === 1) {
      $prevStart = to_minutes((string)($prev["start_time"] ?? "09:00"));
      $prevEnd   = to_minutes((string)($prev["end_time"] ?? "17:00"));

      // carry-over exists only if previous day is overnight (end <= start)
      if ($prevEnd <= $prevStart) {
        // morning part on current date = [00:00 → prevEnd)
        if ($tMin < $prevEnd && ($tMin + $slotMinutes) <= $prevEnd) $ok = true;
      }
    }
  }

  if (!$ok) {
    json_response(422, ["ok"=>false, "error"=>"Selected time is outside doctor's availability"]);
  }

  // past time check if today (IST)
  $tz = new DateTimeZone("Asia/Kolkata");
  $now = new DateTime("now", $tz);
  if ($dateIso === $now->format("Y-m-d")) {
    $nowMin = to_minutes($now->format("H:i"));
    if ($tMin <= $nowMin) json_response(422, ["ok"=>false, "error"=>"Selected time has already passed"]);
  }

  $pdo->beginTransaction();

  // ✅ Gate: already booked with SAME doctor TODAY (whole day)
  $gst = $pdo->prepare("
    SELECT id
    FROM appointments
    WHERE patient_id=?
      AND doctor_id=?
      AND DATE(scheduled_at)=?
      AND UPPER(status) IN ('BOOKED','CONFIRMED')
    ORDER BY scheduled_at DESC
    LIMIT 1
    FOR UPDATE
  ");
  $gst->execute([$uid, $doctorId, $dateIso]);
  $existingId = (int)($gst->fetchColumn() ?: 0);
  if ($existingId > 0) {
    $pdo->rollBack();
    json_response(409, ["ok"=>false, "error"=>"Already booked today", "data"=>["bookingId"=>(string)$existingId]]);
  }

  // lock slot to prevent race booking
  $cst = $pdo->prepare("
    SELECT id
    FROM appointments
    WHERE doctor_id=? AND scheduled_at=? AND UPPER(status) IN ('BOOKED','CONFIRMED')
    LIMIT 1 FOR UPDATE
  ");
  $cst->execute([$doctorId, $scheduledAtStr]);
  if ($cst->fetchColumn()) {
    $pdo->rollBack();
    json_response(409, ["ok"=>false, "error"=>"Someone else booked this time slot. Please choose another time."]);
  }

  // insert with retry for public_code collisions
  $apptId = 0;
  $publicCode = "";

  for ($try = 0; $try < 8; $try++) {
    $publicCode = gen_public_code();
    try {
      $ins = $pdo->prepare("
        INSERT INTO appointments
          (public_code, patient_id, doctor_id, specialty, consult_type, symptoms, fee_amount, scheduled_at, duration_min, status)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, 'BOOKED')
      ");
      $ins->execute([
        $publicCode,
        $uid,
        $doctorId,
        $specKey,
        $ctype,
        ($symptoms === null || $symptoms === "" ? null : $symptoms),
        $feeAmount,
        $scheduledAtStr,
        $slotMinutes
      ]);
      $apptId = (int)$pdo->lastInsertId();
      break;
    } catch (PDOException $e) {
      $errNo = (int)($e->errorInfo[1] ?? 0);
      $errMsg = (string)($e->errorInfo[2] ?? "");
      if ($errNo === 1062) {
        if (stripos($errMsg, "uq_doctor") !== false || stripos($errMsg, "scheduled") !== false) {
          $pdo->rollBack();
          json_response(409, ["ok"=>false, "error"=>"Someone else booked this time slot. Please choose another time."]);
        }
        continue; // likely public_code collision
      }
      throw $e;
    }
  }

  if ($apptId <= 0) {
    $pdo->rollBack();
    json_response(500, ["ok"=>false, "error"=>"Could not generate appointment code"]);
  }

  // notifications (DB) best effort
  try {
    $n = $pdo->prepare("INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)");
    $n->execute([$uid, "Appointment booked", "Your appointment is booked on {$dateIso} at {$timeHm} ({$ctype})."]);
    $n->execute([$doctorId, "New appointment", "You have a new appointment on {$dateIso} at {$timeHm} ({$ctype})."]);
  } catch (Throwable $e) {}

  $pdo->commit();

  json_response(200, ["ok"=>true, "data"=>[
    "bookingId" => (string)$apptId,
    "public_code" => $publicCode,
    "fee_amount" => $feeAmount
  ]]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to create appointment",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
