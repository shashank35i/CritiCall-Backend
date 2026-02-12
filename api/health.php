<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  "ok" => true,
  "service" => "sehatsethu_api",
  "time" => date('c')
]);
