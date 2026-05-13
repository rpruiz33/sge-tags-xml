<?php
function renderPdfDocumentationHtml(): string
{
    $title = 'SGEtags-xml';
    $subtitle = 'Convertidor de Word (.docx) a XML JATS - SciELO Publishing Schema 1.9';
    $date = date('d/m/Y');

    return '<!DOCTYPE html>' .
        '<html lang="es">' .
        '<head>' .
        '  <meta charset="UTF-8">' .
        '  <meta name="viewport" content="width=device-width, initial-scale=1">' .
        '  <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' - Documentación</title>' .
        '  <style>' .
        '    * { box-sizing: border-box; }' .
        '    body { font-family: Arial, Helvetica, sans-serif; color: #243042; line-height: 1.55; margin: 0; padding: 40px; background: #fff; }' .
        '    @media print { body { padding: 18px; } h2 { page-break-before: always; } .no-print { display: none; } }' .
        '    .cover { text-align: center; padding: 12px 0 28px; border-bottom: 3px solid #0b74d1; margin-bottom: 28px; }' .
        '    .cover h1 { margin: 0 0 10px; font-size: 42px; color: #0b74d1; letter-spacing: -0.03em; }' .
        '    .cover h2 { margin: 0 0 14px; font-size: 20px; font-weight: 400; color: #556070; }' .
        '    .meta { font-size: 12px; color: #7a8494; }' .
        '    h2 { font-size: 28px; color: #0b74d1; margin: 34px 0 14px; padding-bottom: 8px; border-bottom: 2px solid #d9e7f5; }' .
        '    h3 { font-size: 18px; color: #163a63; margin: 22px 0 10px; }' .
        '    p { margin: 0 0 12px; text-align: justify; }' .
        '    ul, ol { margin: 12px 0 12px 26px; }' .
        '    li { margin: 0 0 8px; }' .
        '    code, pre { font-family: "Courier New", Courier, monospace; }' .
        '    code { background: #f4f7fb; border: 1px solid #e2e8f0; border-radius: 4px; padding: 1px 5px; }' .
        '    pre { background: #f7fafc; border: 1px solid #dbe5ef; border-left: 4px solid #0b74d1; padding: 14px; border-radius: 6px; overflow-x: auto; white-space: pre-wrap; }' .
        '    table { width: 100%; border-collapse: collapse; margin: 14px 0 20px; }' .
        '    th { background: #0b74d1; color: #fff; text-align: left; padding: 10px 12px; }' .
        '    td { border-bottom: 1px solid #e3e8ef; padding: 10px 12px; vertical-align: top; }' .
        '    tr:nth-child(even) td { background: #fbfdff; }' .
        '    .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin: 16px 0; }' .
        '    .card { background: #f6faff; border: 1px solid #d9e7f5; border-left: 4px solid #0b74d1; border-radius: 8px; padding: 14px; }' .
        '    .card strong { color: #0b74d1; }' .
        '    .note { background: #fff8e6; border: 1px solid #f1d28a; border-left: 4px solid #d59a00; padding: 14px; border-radius: 8px; }' .
        '    .footer { text-align: center; color: #8a94a3; font-size: 12px; margin-top: 30px; padding-top: 18px; border-top: 1px solid #e6ecf2; }' .
        '  </style>' .
        '</head>' .
        '<body>' .
        '<div class="cover">' .
        '  <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' .
        '  <h2>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</h2>' .
        '  <div class="meta">Versión documental 2026 · Fecha: ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . ' · Salida: XML JATS / SPS 1.9</div>' .
        '</div>' .
        '<h2>1. Qué hace el sistema</h2>' .
        '<p>SGEtags-xml convierte artículos académicos en <strong>Word (.docx)</strong> a <strong>XML JATS</strong> para SciELO. La conversión automática extrae texto y metadatos del DOCX, mientras que el front ofrece edición rápida para completar datos puntuales y revisar el resultado antes de descargarlo.</p>' .
        '<h2>2. Cómo funciona</h2>' .
        '<ol>' .
        '  <li>El usuario sube un archivo .docx desde la interfaz principal.</li>' .
        '  <li><code>convert.php</code> recibe el archivo y delega el análisis a <code>DocxParser.php</code>.</li>' .
        '  <li>El parser extrae líneas del documento, identifica autores, títulos, afiliaciones, fechas, resúmenes y referencias.</li>' .
        '  <li>El parser intenta detectar automáticamente todos los metadatos disponibles dentro del DOCX.</li>' .
        '  <li>Se genera el XML JATS final con la estructura requerida por SPS 1.9.</li>' .
        '  <li>El front permite revisar el XML, editar metadatos manuales y descargar el resultado.</li>' .
        '</ol>' .
        '<h2>3. Flujo automático y manual</h2>' .
        '<div class="grid">' .
        '  <div class="card"><strong>Ruta automática</strong><br>Extrae el contenido del DOCX, normaliza caracteres, corrige metadatos faltantes y produce el XML final.</div>' .
        '  <div class="card"><strong>Ruta manual</strong><br>Permite editar autores, ORCID, DOI, fechas, volumen, financiamiento y contribuciones desde el front.</div>' .
        '</div>' .
        '<h2>4. Componentes principales</h2>' .
        '<table>' .
        '  <tr><th>Archivo</th><th>Rol</th></tr>' .
        '  <tr><td><code>index.php</code></td><td>Interfaz principal con carga de DOCX, edición rápida y vista previa.</td></tr>' .
        '  <tr><td><code>js/app.js</code></td><td>Lógica del front, carga de DOCX, reconstrucción de autores y generación de XML manual.</td></tr>' .
        '  <tr><td><code>convert.php</code></td><td>Endpoint de conversión automática.</td></tr>' .
        '  <tr><td><code>DocxParser.php</code></td><td>Extracción, normalización y generación del XML JATS.</td></tr>' .
        '  <tr><td><code>pdf.php</code></td><td>Salida de documentación en PDF o HTML imprimible.</td></tr>' .
        '</table>' .
        '<h2>5. Qué detecta automáticamente</h2>' .
        '<div class="grid">' .
        '  <div class="card"><strong>Metadatos</strong><br>DOI, idioma, versión SPS, títulos, autores, ORCID, afiliaciones, abstract y palabras clave.</div>' .
        '  <div class="card"><strong>Estructura editorial</strong><br>Fechas, volumen, elocation-id, financiamiento, conflicto de intereses y contribuciones autorales.</div>' .
        '</div>' .
        '<h2>6. Qué se completa manualmente</h2>' .
        '<ul>' .
        '  <li>Cuerpo completo del artículo.</li>' .
        '  <li>Referencias bibliográficas en formato JATS.</li>' .
        '  <li>Paginación o identificadores editoriales cuando el DOCX no los contiene.</li>' .
        '</ul>' .
        '<h2>7. Validaciones y estándar</h2>' .
        '<p>La salida está pensada para <strong>JATS 1.3</strong> con requerimientos de <strong>SciELO Publishing Schema 1.9</strong>. El sistema también normaliza problemas comunes de origen documental, como guiones largos, ORCID duplicados, afiliaciones incompletas y líneas de formato que afectan la validación.</p>' .
        '<div class="note"><strong>Nota:</strong> La documentación PDF sigue el estado actual del proyecto y no sustituye la conversión automática como fuente final del XML.</div>' .
        '<h2>8. Resumen operativo</h2>' .
        '<p>En la práctica, el sistema combina una conversión automática robusta con una capa manual de control fino. Eso permite corregir metadatos sin tocar el XML a mano y mantener una salida estable para publicación.</p>' .
        '<div class="footer">SGEtags-xml · Documentación técnica generada automáticamente</div>' .
        '</body>' .
        '</html>';
}

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="SGEtags-xml_Documentacion.html"');
    echo renderPdfDocumentationHtml();
}
