<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- protect cron ----
$cfg = app_config();
$cronKey = $cfg['CRON_KEY'] ?? '';

$reqKey = (string)($_GET['key'] ?? '');
if ($cronKey !== '' && !hash_equals($cronKey, $reqKey)) {
  json_response(403, ["ok"=>false, "error"=>"Forbidden"]);
}

// ---- stages in minutes (largest -> smallest) ----
$STAGES = [
  ["key"=>"2D",  "mins"=>2880, "window"=>30], // +/- 30 mins
  ["key"=>"1D",  "mins"=>1440, "window"=>20],
  ["key"=>"14H", "mins"=>840,  "window"=>15],
  ["key"=>"5H",  "mins"=>300,  "window"=>10],
  ["key"=>"1H",  "mins"=>60,   "window"=>5],
  ["key"=>"30M", "mins"=>30,   "window"=>3],
  ["key"=>"10M", "mins"=>10,   "window"=>2],
  ["key"=>"5M",  "mins"=>5,    "window"=>2],
  ["key"=>"1M",  "mins"=>1,    "window"=>1],
];

// ---- helpers ----
function stageLabel(string $stageKey): string {
  switch ($stageKey) {
    case "2D": return "in 2 days";
    case "1D": return "in 1 day";
    case "14H": return "in 14 hours";
    case "5H": return "in 5 hours";
    case "1H": return "in 1 hour";
    case "30M": return "in 30 minutes";
    case "10M": return "in 10 minutes";
    case "5M": return "in 5 minutes";
    case "1M": return "in 1 minute";
    default: return "soon";
  }
}

function softDeleteNotification(PDO $pdo, ?int $id): void {
  if (!$id || $id <= 0) return;

  // if deleted_at column exists, soft delete
  $hasDeletedAt = false;
  try {
    $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'deleted_at'")->fetch(PDO::FETCH_ASSOC);
    $hasDeletedAt = !!$col;
  } catch (Throwable $e) {}

  if ($hasDeletedAt) {
    $st = $pdo->prepare("UPDATE notifications SET deleted_at=NOW() WHERE id=?");
    $st->execute([$id]);
  } else {
    // fallback hard delete if your table doesn't have deleted_at
    $st = $pdo->prepare("DELETE FROM notifications WHERE id=?");
    $st->execute([$id]);
  }
}

function hasDataJson(PDO $pdo): bool {
  static $cached = null;
  if ($cached !== null) return $cached;
  try {
    $col = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'data_json'")->fetch(PDO::FETCH_ASSOC);
    $cached = !!$col;
  } catch (Throwable $e) { $cached = false; }
  return $cached;
}

function insertNotification(PDO $pdo, int $userId, string $title, string $body, array $data = []): int {
  $useData = hasDataJson($pdo);

  if ($useData) {
    $st = $pdo->prepare("INSERT INTO notifications (user_id, title, body, data_json) VALUES (?, ?, ?, ?)");
    $st->execute([$userId, $title, $body, json_encode($data, JSON_UNESCAPED_UNICODE)]);
  } else {
    $st = $pdo->prepare("INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)");
    $st->execute([$userId, $title, $body]);
  }
  return (int)$pdo->lastInsertId();
}

function pickDueStage(int $minsLeft, array $stages): ?array {
  // Find a stage whose trigger window contains minsLeft
  // Example: 60 mins stage triggers when minsLeft within [55..65]
  foreach ($stages as $s) {
    $t = (int)$s["mins"];
    $w = (int)$s["window"];
    if ($minsLeft >= ($t - $w) && $minsLeft <= ($t + $w)) return $s;
  }
  return null;
}

// ---- main ----
try {
  // Fetch upcoming appts only; keep it bounded
  // NOTE: This assumes appointments.scheduled_at is stored in server local time.
  $sql = "
    SELECT
      a.id,
      a.patient_id,
      a.doctor_id,
      a.consult_type,
      a.scheduled_at,
      UPPER(a.status) AS status,
      TIMESTAMPDIFF(MINUTE, NOW(), a.scheduled_at) AS mins_left,
      COALESCE(s.last_stage,'') AS last_stage,
      s.patient_notif_id,
      s.doctor_notif_id
    FROM appointments a
    LEFT JOIN appointment_reminder_state s ON s.appointment_id = a.id
    WHERE UPPER(a.status) IN ('BOOKED','CONFIRMED')
      AND a.scheduled_at > (NOW() - INTERVAL 10 MINUTE)
      AND a.scheduled_at < (NOW() + INTERVAL 3 DAY)
    ORDER BY a.scheduled_at ASC
    LIMIT 500
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  $sent = 0;
  $updated = 0;
  $skipped = 0;

  $upsertState = $pdo->prepare("
    INSERT INTO appointment_reminder_state (appointment_id, last_stage, patient_notif_id, doctor_notif_id)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      last_stage=VALUES(last_stage),
      patient_notif_id=VALUES(patient_notif_id),
      doctor_notif_id=VALUES(doctor_notif_id),
      updated_at=NOW()
  ");

  foreach ($rows as $a) {
    $apptId = (int)$a["id"];
    $pid    = (int)$a["patient_id"];
    $did    = (int)$a["doctor_id"];
    $ctype  = strtoupper((string)($a["consult_type"] ?? "AUDIO"));
    $when   = (string)($a["scheduled_at"] ?? "");
    $minsLeft = (int)($a["mins_left"] ?? 0);

    // past appointments -> cleanup state
    if ($minsLeft < -5) {
      $skipped++;
      continue;
    }

    $due = pickDueStage($minsLeft, $STAGES);
    if ($due === null) {
      $skipped++;
      continue;
    }

    $stageKey = (string)$due["key"];
    $lastStage = (string)($a["last_stage"] ?? "");

    // Already at this stage -> do nothing
    if ($lastStage === $stageKey) {
      $skipped++;
      continue;
    }

    $prevP = (int)($a["patient_notif_id"] ?? 0);
    $prevD = (int)($a["doctor_notif_id"] ?? 0);

    $title = "Appointment reminder";
    $inTxt = stageLabel($stageKey);

    $bodyP = "Your $ctype consultation is $inTxt ($when).";
    $bodyD = "You have a $ctype consultation $inTxt ($when).";

    $dataJson = [
      "type" => "APPOINTMENT_REMINDER",
      "appointment_id" => $apptId,
      "stage" => $stageKey,
      "scheduled_at" => $when,
      "consult_type" => $ctype,
    ];

    try {
      $pdo->beginTransaction();

      // delete previous reminder notification (replace with new one)
      if ($prevP > 0) softDeleteNotification($pdo, $prevP);
      if ($prevD > 0) softDeleteNotification($pdo, $prevD);

      $newP = insertNotification($pdo, $pid, $title, $bodyP, $dataJson);
      $newD = insertNotification($pdo, $did, $title, $bodyD, $dataJson);

      $upsertState->execute([$apptId, $stageKey, $newP, $newD]);

      $pdo->commit();
      $sent++;
      $updated++;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
    }
  }

  json_response(200, ["ok"=>true, "data"=>[
    "processed" => count($rows),
    "sent" => $sent,
    "updated" => $updated,
    "skipped" => $skipped
  ]]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
