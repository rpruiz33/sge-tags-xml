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
require_once __DIR__ . '/JatsRichParser.php';
require_once __DIR__ . '/PatternTemplateEngine.php';

// Agrupa todo el proceso de conversión para poder capturar cualquier error y responderlo como JSON.
try {
    // Helper: sanitiza un nombre de archivo devolviendo solo ASCII seguro.
    $sanitize_filename = function ($raw) {
        $raw = (string) ($raw ?? 'documento');
        // Forzar UTF-8
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        // Quitar separador de extensión si viene
        $base = pathinfo($raw, PATHINFO_FILENAME);
        // Transliterar a ASCII donde sea posible
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        if ($trans === false || trim($trans) === '') {
            $trans = $base;
        }
        // Reemplaza cualquier caracter no permitido por guion bajo
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $trans);
        // Colapsa guiones bajos multiples y recorta
        $clean = preg_replace('/_+/', '_', trim($clean, '_'));
        return $clean ?: 'documento';
    };
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

        // Si el origen era XML y tenemos el XML original, preservarlo y aplicar merges parciales
        if (!empty($meta['source']) && $meta['source'] === 'xml' && !empty($meta['originalXml'])) {
            $jparser = new JatsRichParser();
            $xml = $jparser->mergeMetaIntoOriginalXml($meta['originalXml'], $meta);
        } else {
            // Intentar usar archivo patrón como template
            $engine = new PatternTemplateEngine();
            $patternFile = $engine->findPatternFile($meta['elocation-id'] ?? '');
            if ($patternFile) {
                $bodyParser = new BodyParser();
                $backParser = new BackParser();
                $bodyXml = $bodyParser->buildBodyXml($meta);
                $backXml = $backParser->buildBackXml($meta);
                $xml = $engine->generateXml($patternFile, $meta, $bodyXml, $backXml);
            } else {
                $parser = new DocxParser('');
                $xml = $parser->buildJatsXml($meta);
            }
        }

        $safe = $sanitize_filename($payload['filename'] ?? 'documento');
        echo json_encode([
            'success' => true,
            'xml' => $xml,
            'metadata' => $meta,
            'filename' => $safe
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
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xml') {
        // Si suben un JATS XML, decidir si usamos el parser enriquecido
        $xmlContent = file_get_contents($tmp);
        $hasRich = preg_match('/<fig\b|<table-wrap\b|<graphic\b/i', $xmlContent);
        if ($hasRich) {
            $jparser = new JatsRichParser();
            $meta = $jparser->parseXmlToMeta($xmlContent);
            // Devolver XML original para preservar figuras/tablas exactamente
            $xml = $xmlContent;
        } else {
            // XML sin figuras/tablas: intentar extraer metadatos ligeros
            $jparser = new JatsRichParser();
            $meta = $jparser->parseXmlToMeta($xmlContent);
            $xml = $xmlContent;
        }
    } else {
        // Asumir DOCX por compatibilidad hacia atrás
        $parser = new DocxParser($tmp);

        // Abre el DOCX, lee word/document.xml y devuelve sus párrafos como líneas de texto.
        $lines = $parser->extractLines();

        // Analiza esas líneas para detectar DOI, títulos, autores, afiliaciones, fechas y otros metadatos.
        $meta = $parser->parseMetadata($lines);

        // Intentar usar archivo patrón como template
        $engine = new PatternTemplateEngine();
        $patternFile = $engine->findPatternFile($meta['elocation-id'] ?? '');
        if ($patternFile) {
            $bodyParser = new BodyParser();
            $backParser = new BackParser();
            $bodyXml = $bodyParser->buildBodyXml($meta);
            $backXml = $backParser->buildBackXml($meta);
            $xml = $engine->generateXml($patternFile, $meta, $bodyXml, $backXml);
        } else {
            // Construye el XML JATS usando los metadatos detectados.
            $xml = $parser->buildJatsXml($meta);
        }
    }

    // Envía una respuesta exitosa al frontend con el XML generado, los metadatos y el nombre base del archivo.
    $safeName = $sanitize_filename($name);
    echo json_encode([
        'success' => true,
        'xml' => $xml,
        'metadata' => $meta,
        'filename' => $safeName
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
