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

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);

$body = read_json();
if (!is_array($body)) $body = [];

$systolic = isset($body["systolic"]) ? (int)$body["systolic"] : null;
$diastolic = isset($body["diastolic"]) ? (int)$body["diastolic"] : null;
$sugar = isset($body["sugar"]) ? (int)$body["sugar"] : null;
$sugar_context = strtoupper(trim((string)($body["sugar_context"] ?? "FASTING")));
if (!in_array($sugar_context, ["FASTING","AFTER_MEAL","RANDOM"], true)) $sugar_context = "FASTING";

$tempF = isset($body["temperature_f"]) ? (float)$body["temperature_f"] : null;
$weightKg = isset($body["weight_kg"]) ? (float)$body["weight_kg"] : null;

$notes = trim((string)($body["notes"] ?? ""));
if (strlen($notes) > 600) $notes = substr($notes, 0, 600);

$client_ms = isset($body["client_recorded_at_ms"]) ? (int)$body["client_recorded_at_ms"] : null;

$any =
  ($systolic !== null) || ($diastolic !== null) || ($sugar !== null) ||
  ($tempF !== null) || ($weightKg !== null) || ($notes !== "");

if (!$any) json_response(422, ["ok"=>false, "error"=>"No vitals provided"]);

if (($systolic !== null && $diastolic === null) || ($systolic === null && $diastolic !== null)) {
  json_response(422, ["ok"=>false, "error"=>"Both systolic and diastolic required"]);
}

try {
  $st = $pdo->prepare("
    INSERT INTO patient_vitals
      (patient_id, client_recorded_at_ms, systolic, diastolic, sugar, sugar_context, temperature_f, weight_kg, notes)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $st->execute([
    $uid,
    $client_ms,
    $systolic,
    $diastolic,
    $sugar,
    $sugar_context,
    $tempF,
    $weightKg,
    $notes
  ]);

  $id = (int)$pdo->lastInsertId();

  json_response(200, [
    "ok" => true,
    "data" => [
      "id" => $id
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to save vitals",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
