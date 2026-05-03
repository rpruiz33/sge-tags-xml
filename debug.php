<?php
// Debug script para ver el error exacto
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

require_once 'index.php';

$files = glob('uploads/doc_*.docx');

if (empty($files)) {
    echo "No hay archivos en uploads/\n";
    exit;
}

$testFile = $files[0];
echo "Probando con: " . basename($testFile) . "\n";
echo "Tamaño: " . filesize($testFile) . " bytes\n\n";

try {
    echo "1. Abriendo DOCX...\n";
    $zip = new ZipArchive();
    if ($zip->open($testFile) !== true) {
        die("No se pudo abrir el ZIP\n");
    }
    
    echo "2. Extrayendo word/document.xml...\n";
    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();
    
    if ($xmlContent === false) {
        die("No contiene word/document.xml\n");
    }
    
    echo "   XML size: " . strlen($xmlContent) . " bytes\n";
    echo "   First 200 chars: " . substr($xmlContent, 0, 200) . "\n\n";
    
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
    
    echo "4. Parseando metadata...\n";
    $meta = $parser->parseMetadata($lines);
    echo "   Keys en metadata: " . implode(', ', array_keys($meta)) . "\n";
    echo "   Title: " . ($meta['articleTitle'] ?? '[sin título]') . "\n";
    echo "   Authors: " . count($meta['authors']) . "\n\n";
    
    echo "5. Generando XML JATS...\n";
    $xml = $parser->buildJatsXml($meta);
    echo "   XML size: " . strlen($xml) . " bytes\n";
    echo "   First 300 chars: " . substr($xml, 0, 300) . "\n\n";
    
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
    
    echo "\n✓ TODO FUNCIONÓ CORRECTAMENTE\n";
    
} catch (Exception $e) {
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
    echo "   Archivo: " . $e->getFile() . "\n";
    echo "   Línea: " . $e->getLine() . "\n";
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   Tipo: " . get_class($e) . "\n";
}
?>
