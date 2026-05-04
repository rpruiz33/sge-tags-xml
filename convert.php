<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/DocxParser.php';

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el archivo');
    }

    $tmp = $_FILES['file']['tmp_name'];
    $name = $_FILES['file']['name'] ?? 'documento';

    $parser = new DocxParser($tmp);
    $lines = $parser->extractLines();
    $meta = $parser->parseMetadata($lines);
    $xml = $parser->buildJatsXml($meta);

    echo json_encode([
        'success' => true,
        'xml' => $xml,
        'metadata' => $meta,
        'filename' => pathinfo($name, PATHINFO_FILENAME)
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
