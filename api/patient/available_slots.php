<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../config.php';

cors_json();
$auth = require_auth();
$pdo = db();

$q = read_json();
$doctorId = (int)($q["doctorId"] ?? 0);
if ($doctorId <= 0) json_response(400, ["ok"=>false, "error"=>"Missing doctorId"]);

$days = 5;

$slotSets = [
  "Morning" => ["9:00 AM","9:30 AM","10:00 AM","10:30 AM","11:00 AM","11:30 AM"],
  "Afternoon" => ["2:00 PM","2:30 PM","3:00 PM","3:30 PM","4:00 PM"],
  "Evening" => ["5:00 PM","5:30 PM","6:00 PM","6:30 PM","7:00 PM"],
];

try {
  $outDays = [];
  for ($i=0; $i<$days; $i++) {
    $date = date("Y-m-d", strtotime("+$i day"));
    $label = ($i===0) ? "Today" : (($i===1) ? "Tomorrow" : date("D", strtotime($date)));

    // booked times for that date
    $stmt = $pdo->prepare("
      SELECT DATE_FORMAT(scheduled_at, '%l:%i %p') t
      FROM appointments
      WHERE doctor_id=? AND status='BOOKED' AND DATE(scheduled_at)=?
    ");
    $stmt->execute([$doctorId, $date]);
    $booked = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $bookedSet = [];
    foreach ($booked as $b) $bookedSet[trim($b)] = true;

    $sections = [];
    foreach ($slotSets as $section => $times) {
      $items = [];
      foreach ($times as $t) {
        $items[] = [
          "time" => $t,
          "disabled" => isset($bookedSet[$t])
        ];
      }
      $sections[] = ["title"=>$section, "slots"=>$items];
    }

    $outDays[] = [
      "date" => $date,
      "label" => $label,
      "dayNum" => (int)date("j", strtotime($date)),
      "sections" => $sections
    ];
  }

  json_response(200, ["ok"=>true, "data"=>["days"=>$outDays]]);
} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
