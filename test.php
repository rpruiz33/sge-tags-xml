<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Test básico
$testData = [
    'success' => true,
    'xml' => '<?xml version="1.0"?><article><front><journal-meta></journal-meta></front></article>',
    'metadata' => ['title' => 'Test'],
    'filename' => 'test'
];

echo "JSON Encode Test:\n";
$json = json_encode($testData);
if ($json === false) {
    echo "ERROR: " . json_last_error_msg() . "\n";
} else {
    echo "SUCCESS\n";
    echo "JSON Length: " . strlen($json) . "\n";
    echo "First 100 chars: " . substr($json, 0, 100) . "\n";
}

// Test con DocxParser
require_once 'index.php';

$files = glob('uploads/doc_*.docx');
if (!empty($files)) {
    echo "\nTesting with actual file: " . basename($files[0]) . "\n";
    try {
        $parser = new DocxParser($files[0]);
        $lines = $parser->extractLines();
        echo "Extracted " . count($lines) . " lines\n";
        
        $meta = $parser->parseMetadata($lines);
        echo "Parsed metadata\n";
        
        $xml = $parser->buildJatsXml($meta);
        echo "Generated XML, length: " . strlen($xml) . " chars\n";
        
        $response = [
            'success' => true,
            'xml' => $xml,
            'metadata' => $meta,
            'filename' => 'test'
        ];
        
        $json = json_encode($response);
        if ($json === false) {
            echo "JSON Encode ERROR: " . json_last_error_msg() . "\n";
        } else {
            echo "JSON Encode SUCCESS, length: " . strlen($json) . "\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "\nNo test files found\n";
}
?>
