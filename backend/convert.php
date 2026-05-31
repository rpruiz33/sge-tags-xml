<?php
// Este script es el endpoint que recibe el archivo .docx del cliente
// y devuelve el XML JATS generado en formato JSON.

// Le indica al navegador o al frontend que la respuesta será JSON codificado en UTF-8.
header('Content-Type: application/json; charset=utf-8');

// Activa el reporte de todos los errores para que PHP los registre internamente.
error_reporting(E_ALL);
// Evita mostrar errores crudos en la respuesta JSON; así el cliente recibe mensajes controlados.
ini_set('display_errors', '0');

// Carga la clase DocxParser, que contiene la lógica para leer DOCX, extraer metadatos y armar XML JATS.
require_once __DIR__ . '/DocxParser.php';

// Agrupa todo el proceso de conversión para poder capturar cualquier error y responderlo como JSON.
try {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('JSON invalido');
        }

        if (($payload['action'] ?? '') !== 'rebuild') {
            throw new RuntimeException('Accion JSON no soportada');
        }

        $meta = $payload['metadata'] ?? null;
        if (!is_array($meta)) {
            throw new RuntimeException('No se recibieron metadatos para reconstruir el XML');
        }

        $parser = new DocxParser('');
        $xml = $parser->buildJatsXml($meta);

        echo json_encode([
            'success' => true,
            'xml' => $xml,
            'metadata' => $meta,
            'filename' => preg_replace('/\s+/', '', $payload['filename'] ?? 'documento')
        ]);
        exit;
    }

    // Si no llegó el campo "file" o PHP marcó un error de subida, se corta el proceso.
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        // Lanza un error controlado que será capturado por el catch de abajo.
        throw new RuntimeException('No se pudo subir el archivo');
    }

    // Guarda la ruta temporal donde PHP dejó el DOCX subido.
    $tmp = $_FILES['file']['tmp_name'];
    // Guarda el nombre original del archivo; si no viene, usa "documento" como respaldo.
    $name = $_FILES['file']['name'] ?? 'documento';

    // Crea el parser apuntando al archivo temporal que se acaba de subir.
    $parser = new DocxParser($tmp);

    // Abre el DOCX, lee word/document.xml y devuelve sus párrafos como líneas de texto.
    $lines = $parser->extractLines();

    // Analiza esas líneas para detectar DOI, títulos, autores, afiliaciones, fechas y otros metadatos.
    $meta = $parser->parseMetadata($lines);

    // Construye el XML JATS usando los metadatos detectados.
    $xml = $parser->buildJatsXml($meta);

    // Envía una respuesta exitosa al frontend con el XML generado, los metadatos y el nombre base del archivo.
    echo json_encode([
        // Marca que la conversión terminó correctamente.
        'success' => true,
        // Incluye el XML JATS completo generado por DocxParser.
        'xml' => $xml,
        // Incluye los metadatos detectados para que el frontend pueda mostrarlos o depurarlos.
        'metadata' => $meta,
        // Devuelve el nombre del archivo sin extensión para sugerir un nombre de descarga.
        // Eliminar espacios en blanco del nombre sugerido para la descarga
        'filename' => preg_replace('/\s+/', '', pathinfo($name, PATHINFO_FILENAME))
    ]);
} catch (Throwable $e) {
    // Si algo falla en la subida, parseo o generación, responde como error de solicitud.
    http_response_code(400);
    // Devuelve un JSON de error para que el frontend pueda mostrar el mensaje sin romper la interfaz.
    echo json_encode([
        // Marca que la conversión no se pudo completar.
        'success' => false,
        // Incluye el mensaje de la excepción capturada.
        'error' => $e->getMessage()
    ]);
}
