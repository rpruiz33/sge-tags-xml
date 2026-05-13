<?php
/**
 * Script para descargar la documentación como PDF.
 * Usa TCPDF si está disponible, o HTML imprimible como fallback.
 */

require_once __DIR__ . '/generar_pdf.php';

$html = renderPdfDocumentationHtml();
$tcpdfPath = __DIR__ . '/vendor/autoload.php';
$hasTCPDF = false;

if (file_exists($tcpdfPath)) {
    try {
        require_once $tcpdfPath;
        $hasTCPDF = class_exists('TCPDF');
    } catch (Throwable $exception) {
        $hasTCPDF = false;
    }
}

if ($hasTCPDF) {
    $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_PAGE_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SGEtags-xml');
    $pdf->SetTitle('SGEtags-xml - Documentación del Proyecto');
    $pdf->SetSubject('Documentación técnica y flujo de conversión');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->AddPage();

    $pdfHtml = preg_replace('/<head>.*?<\/head>/is', '', $html);
    $pdf->writeHTML($pdfHtml, true, false, true, false, '');
    $pdf->Output('SGEtags-xml_Documentacion.pdf', 'D');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="SGEtags-xml_Documentacion.html"');
echo $html;
exit;
