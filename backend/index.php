<?php
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'service' => 'backend',
    'message' => 'Backend activo. Usa /backend/convert.php y /backend/pdf.php'
]);
