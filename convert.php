<?php
// Este script es el endpoint que recibe el archivo .docx del cliente
// y devuelve el XML JATS generado en formato JSON.

// Fijamos que la respuesta siempre sea JSON UTF-8.
header('Content-Type: application/json; charset=utf-8');

// Activamos el reporte completo de errores, pero no los mostramos en pantalla.
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Cargamos la clase que convierte DOCX a XML JATS.
require_once __DIR__ . '/DocxParser.php';

try {
    // Validamos que exista el archivo subido y que no haya errores de upload.
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el archivo');
    }

    // Obtenemos la ruta temporal del archivo subido en el servidor.
    $tmp = $_FILES['file']['tmp_name'];
    // Obtenemos el nombre original del archivo para usarlo luego en la respuesta.
    $name = $_FILES['file']['name'] ?? 'documento';

    // Creamos el parser con el archivo temporal.
    $parser = new DocxParser($tmp);

    // Extraemos las líneas de texto del DOCX.
    $lines = $parser->extractLines();

    // Interpretamos las líneas y construimos los metadatos.
    $meta = $parser->parseMetadata($lines);

    // Generamos el XML JATS a partir de los metadatos.
    $xml = $parser->buildJatsXml($meta);

    // Respondemos con JSON que incluye el XML, los metadatos y el nombre del archivo.
    echo json_encode([
        'success' => true,
        'xml' => $xml,
        'metadata' => $meta,
        'filename' => pathinfo($name, PATHINFO_FILENAME)
    ]);
} catch (Throwable $e) {
    // Si ocurre cualquier error, devolvemos un código 400 y mensaje en JSON.
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
