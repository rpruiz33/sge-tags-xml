<?php
/**
 * Script para descargar la documentación como PDF
 * Usa TCPDF si está disponible, o HTML imprimible como fallback
 */

// Intentar cargar TCPDF si está disponible
$tcpdfPath = __DIR__ . '/vendor/autoload.php';
$hasTCPDF = false;

if (file_exists($tcpdfPath)) {
    try {
        require_once $tcpdfPath;
        if (class_exists('TCPDF')) {
            $hasTCPDF = true;
        }
    } catch (Exception $e) {
        // TCPDF no disponible
    }
}

if ($hasTCPDF) {
    // Generar PDF con TCPDF
    generateWithTCPDF();
} else {
    // Generar HTML imprimible
    generateHTMLForPrint();
}

function generateWithTCPDF() {
    // Incluir el documento HTML generado
    ob_start();
    include __DIR__ . '/generar_pdf.php';
    ob_end_clean();
    
    // Crear instancia TCPDF
    $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_PAGE_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Establecer propiedades
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SGEtags-xml');
    $pdf->SetTitle('SGEtags-xml - Documentación del Proyecto');
    $pdf->SetSubject('Documentación técnica y funciones principales');
    
    // Márgenes
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Fuente
    $pdf->SetFont('helvetica', '', 11);
    
    // Agregar página y contenido
    $pdf->AddPage();
    
    ob_start();
    include __DIR__ . '/generar_pdf.php';
    $html = ob_get_clean();
    
    // Limpiar etiquetas HTML complejas
    $html = strip_tags($html, '<p><br><strong><em><u><h2><h3><h4><table><tr><td><th><ul><ol><li>');
    
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Descargar
    $pdf->Output('SGEtags-xml_Documentacion.pdf', 'D');
    exit;
}

function generateHTMLForPrint() {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="SGEtags-xml_Documentacion.html"');
    
    echo '<!DOCTYPE html>';
    echo '<html lang="es">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>SGEtags-xml - Documentación</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; line-height: 1.6; margin: 20px; color: #333; }';
    echo '@media print { body { margin: 0; } }';
    echo 'h1 { color: #007acc; border-bottom: 3px solid #007acc; padding-bottom: 10px; }';
    echo 'h2 { color: #0066cc; border-bottom: 2px solid #0066cc; padding-bottom: 10px; page-break-before: always; }';
    echo 'h3, h4 { color: #333; }';
    echo 'code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }';
    echo 'pre { background: #f5f5f5; padding: 15px; border-left: 4px solid #007acc; overflow-x: auto; }';
    echo 'table { width: 100%; border-collapse: collapse; margin: 15px 0; }';
    echo 'table th { background: #007acc; color: white; padding: 12px; text-align: left; }';
    echo 'table td { padding: 10px; border-bottom: 1px solid #ddd; }';
    echo '.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-right: 5px; color: white; }';
    echo '.badge.auto { background: #17a2b8; }';
    echo '.badge.manual { background: #ffc107; color: #333; }';
    echo '.feature-item { background: #f0f7ff; padding: 15px; border-radius: 5px; border-left: 4px solid #007acc; margin: 10px 0; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Incluir contenido de generar_pdf.php
    include __DIR__ . '/generar_pdf.php';
    
    echo '</body>';
    echo '</html>';
    exit;
}
?>
