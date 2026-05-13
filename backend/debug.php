<?php
// Este script de diagnóstico ejecuta el flujo completo de parseo sobre un DOCX de prueba
// para identificar en qué etapa aparece un fallo (ZIP, extracción XML, parser, JATS o JSON).

// Activa todos los errores para facilitar la depuración durante pruebas locales.
error_reporting(E_ALL);
// En modo debug sí muestra errores en pantalla para ver el detalle exacto.
ini_set('display_errors', 1);
// La salida es texto plano para que el resultado sea legible en terminal o navegador.
header('Content-Type: text/plain; charset=utf-8');

// Carga el backend/base donde está disponible la clase DocxParser usada por el diagnóstico.
require_once 'index.php';

// Busca archivos de prueba previamente subidos con el patrón esperado.
$files = glob('uploads/doc_*.docx');

// Si no hay muestras en uploads, termina para evitar errores de archivo inexistente.
if (empty($files)) {
    echo "No hay archivos en uploads/\n";
    exit;
}

// Toma el primer DOCX encontrado como muestra de prueba.
$testFile = $files[0];
echo "Probando con: " . basename($testFile) . "\n";
echo "Tamaño: " . filesize($testFile) . " bytes\n\n";

// Encapsula todo el diagnóstico para reportar claramente excepciones y errores fatales.
try {
    // 1) Verifica que el DOCX pueda abrirse como ZIP.
    echo "1. Abriendo DOCX...\n";
    $zip = new ZipArchive();
    if ($zip->open($testFile) !== true) {
        die("No se pudo abrir el ZIP\n");
    }
    
    // 2) Comprueba que exista el XML principal de Word dentro del contenedor.
    echo "2. Extrayendo word/document.xml...\n";
    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();
    
    if ($xmlContent === false) {
        die("No contiene word/document.xml\n");
    }
    
    echo "   XML size: " . strlen($xmlContent) . " bytes\n";
    echo "   First 200 chars: " . substr($xmlContent, 0, 200) . "\n\n";
    
    // 3) Ejecuta el parser de líneas para validar extracción de contenido textual.
    echo "3. Parseando con DocxParser...\n";
    $parser = new DocxParser($testFile);
    $lines = $parser->extractLines();
    echo "   Líneas extraídas: " . count($lines) . "\n";
    
    if (count($lines) > 0) {
        echo "   Primeras 3 líneas:\n";
        foreach (array_slice($lines, 0, 3) as $i => $line) {
            echo "   " . ($i+1) . ": " . substr($line, 0, 100) . "\n";
        }
    }
    echo "\n";
    
    // 4) Ejecuta el parseo de metadatos y muestra campos clave para inspección rápida.
    echo "4. Parseando metadata...\n";
    $meta = $parser->parseMetadata($lines);
    echo "   Keys en metadata: " . implode(', ', array_keys($meta)) . "\n";
    echo "   Title: " . ($meta['articleTitle'] ?? '[sin título]') . "\n";
    echo "   Authors: " . count($meta['authors']) . "\n\n";
    
    // 5) Construye el XML JATS para confirmar que la etapa de render no falla.
    echo "5. Generando XML JATS...\n";
    $xml = $parser->buildJatsXml($meta);
    echo "   XML size: " . strlen($xml) . " bytes\n";
    echo "   First 300 chars: " . substr($xml, 0, 300) . "\n\n";
    
    // 6) Valida que la respuesta final pueda serializarse correctamente a JSON.
    echo "6. Verificando JSON encoding...\n";
    $response = [
        'success' => true,
        'xml' => $xml,
        'metadata' => $meta,
        'filename' => 'test'
    ];
    
    $json = json_encode($response);
    if ($json === false) {
        echo "   ERROR: " . json_last_error_msg() . "\n";
    } else {
        echo "   SUCCESS - JSON size: " . strlen($json) . " bytes\n";
    }
    
    // Si llegó hasta aquí, todas las etapas del flujo pasaron sin error.
    echo "\n✓ TODO FUNCIONÓ CORRECTAMENTE\n";
    
} catch (Exception $e) {
    // Captura excepciones clásicas y muestra ubicación para depurar rápido.
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
} catch (Throwable $e) {
    // Captura errores de bajo nivel (TypeError, ParseError, etc.).
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   Tipo: " . get_class($e) . "\n";
}
?>
