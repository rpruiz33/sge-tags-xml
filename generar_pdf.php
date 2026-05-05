<?php
// PDF Generator for SGEtags-xml Project Documentation
// Using TCPDF library

// Check if TCPDF is available, if not use HTML export
require_once __DIR__ . '/DocxParser.php';

class PDFDocumentation {
    private $title = "SGEtags-xml";
    private $subtitle = "Convertidor de Word (.docx) a XML JATS - SciELO Publishing Schema 1.9";
    
    public function generate() {
        // Try to use TCPDF if available
        if ($this->hasTCPDF()) {
            return $this->generateWithTCPDF();
        } else {
            // Fallback to HTML/CSS for print to PDF
            return $this->generateHTMLPDF();
        }
    }
    
    private function hasTCPDF() {
        return class_exists('TCPDF') || file_exists(__DIR__ . '/vendor/autoload.php');
    }
    
    private function generateWithTCPDF() {
        // TCPDF implementation would go here
        return $this->generateHTMLPDF();
    }
    
    private function generateHTMLPDF() {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $this->title; ?> - Documentación</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            line-height: 1.6;
            background: white;
            padding: 40px;
        }
        
        @media print {
            body {
                padding: 20px;
            }
        }
        
        .header-page {
            text-align: center;
            margin-bottom: 60px;
            padding-bottom: 40px;
            border-bottom: 3px solid #007acc;
        }
        
        .header-page h1 {
            font-size: 48px;
            font-weight: 700;
            color: #007acc;
            margin-bottom: 10px;
        }
        
        .header-page h2 {
            font-size: 20px;
            color: #666;
            font-weight: 400;
            margin-bottom: 20px;
        }
        
        .doc-meta {
            font-size: 12px;
            color: #999;
            text-align: center;
        }
        
        .page-break {
            page-break-after: always;
            margin-top: 40px;
        }
        
        h2 {
            font-size: 32px;
            color: #007acc;
            margin: 40px 0 20px 0;
            border-bottom: 2px solid #007acc;
            padding-bottom: 10px;
        }
        
        h3 {
            font-size: 20px;
            color: #0066cc;
            margin: 25px 0 15px 0;
        }
        
        h4 {
            font-size: 16px;
            color: #333;
            margin: 15px 0 10px 0;
            font-weight: 600;
        }
        
        p {
            margin-bottom: 15px;
            text-align: justify;
        }
        
        ul, ol {
            margin: 15px 0 15px 30px;
        }
        
        li {
            margin-bottom: 8px;
        }
        
        code, pre {
            background: #f5f5f5;
            font-family: 'Courier New', monospace;
            padding: 2px 6px;
            border-radius: 3px;
            color: #d63384;
        }
        
        pre {
            padding: 15px;
            overflow-x: auto;
            border-left: 4px solid #007acc;
            margin: 15px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        table th {
            background: #007acc;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ddd;
        }
        
        table tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .feature-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .feature-item {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #007acc;
        }
        
        .feature-item strong {
            color: #007acc;
        }
        
        .requirement-grid {
            background: #fff3cd;
            padding: 20px;
            border-radius: 5px;
            border-left: 4px solid #ffc107;
            margin: 20px 0;
        }
        
        .badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .badge.auto {
            background: #17a2b8;
        }
        
        .badge.manual {
            background: #ffc107;
            color: #333;
        }
        
        .function-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            margin: 15px 0;
        }
        
        .function-box h4 {
            margin-top: 0;
            color: #007acc;
        }
        
        .toc {
            background: #f9f9f9;
            padding: 25px;
            border-radius: 5px;
            margin: 30px 0;
        }
        
        .toc h3 {
            margin-top: 0;
        }
        
        .toc ul {
            list-style: none;
            margin-left: 0;
        }
        
        .toc li {
            margin-bottom: 10px;
        }
        
        .toc a {
            color: #007acc;
            text-decoration: none;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            
            h2 {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <!-- Portada -->
    <div class="header-page">
        <h1><?php echo $this->title; ?></h1>
        <h2><?php echo $this->subtitle; ?></h2>
        <div class="doc-meta">
            <p><strong>Versión:</strong> 1.0</p>
            <p><strong>Fecha:</strong> <?php echo date('d/m/Y'); ?></p>
            <p><strong>Estándar JATS:</strong> 1.3 - SciELO Publishing Schema (SPS) 1.9</p>
        </div>
    </div>
    
    <!-- Tabla de contenidos -->
    <div class="toc">
        <h3>📋 Tabla de Contenidos</h3>
        <ul>
            <li>1. Descripción del Proyecto</li>
            <li>2. Arquitectura y Estructura</li>
            <li>3. Componentes Principales</li>
            <li>4. Funciones Clave del Sistema</li>
            <li>5. Flujo de Procesamiento</li>
            <li>6. Especificaciones Técnicas</li>
            <li>7. Requisitos y Limitaciones</li>
            <li>8. Validación y Cumplimiento de Estándares</li>
        </ul>
    </div>
    
    <!-- 1. Descripción del Proyecto -->
    <h2>1. Descripción del Proyecto</h2>
    
    <p><strong>SGEtags-xml</strong> es una aplicación web PHP que automatiza la conversión de artículos académicos desde formato Word (.docx) a XML JATS (Journal Article Tag Suite) cumpliendo con el estándar SciELO Publishing Schema (SPS) versión 1.9.</p>
    
    <h3>Objetivo Principal</h3>
    <p>Facilitar y agilizar el proceso de preparación de artículos académicos para publicación en plataformas de acceso abierto como SciELO, extrayendo automáticamente metadatos y estructura del documento Word y generando XML JATS válido.</p>
    
    <h3>Características Destacadas</h3>
    <div class="feature-list">
        <div class="feature-item">
            <strong>✓ Sin dependencias externas</strong><br>
            No requiere Composer ni librerías de terceros
        </div>
        <div class="feature-item">
            <strong>✓ Extracción automática de metadatos</strong><br>
            DOI, autores, afiliaciones, resúmenes, palabras clave
        </div>
        <div class="feature-item">
            <strong>✓ Interfaz intuitiva</strong><br>
            Drag & drop y vista previa de XML en vivo
        </div>
        <div class="feature-item">
            <strong>✓ Conformidad JATS/SPS</strong><br>
            Genera XML válido según estándares internacionales
        </div>
    </div>
    
    <!-- 2. Arquitectura y Estructura -->
    <h2>2. Arquitectura y Estructura</h2>
    
    <h3>Estilo de Arquitectura</h3>
    <p><strong>Monolítico Full-Stack:</strong> Aplicación web basada en arquitectura de 3 capas integradas en un único servidor.</p>
    
    <h3>Estructura de Directorios</h3>
    <pre>
SGEtags-xml/
├── index.php           ← Aplicación principal (interfaz HTML + JS)
├── convert.php         ← API REST endpoint para procesamiento
├── DocxParser.php      ← Clase analizadora de documentos Word
├── debug.php           ← Herramientas de depuración
├── test.php            ← Suite de pruebas unitarias
├── test_manual.php     ← Pruebas manuales
├── pattern.xml         ← Plantilla de estructura XML JATS
├── css/
│   └── style.css       ← Estilos de la interfaz
├── js/
│   └── app.js          ← Lógica del cliente (JavaScript vanilla)
├── uploads/            ← Directorio temporal de archivos subidos
└── README.md           ← Documentación técnica
    </pre>
    
    <!-- 3. Componentes Principales -->
    <h2>3. Componentes Principales</h2>
    
    <h3>Backend (Lado del Servidor)</h3>
    <div class="function-box">
        <h4>📄 DocxParser.php</h4>
        <p><strong>Responsabilidad:</strong> Analizar documentos Word y extraer contenido</p>
        <p><strong>Técnica:</strong> Lee archivos ZIP (estructura interna de .docx), procesa XML con DOM/XPath</p>
        <p><strong>Salida:</strong> Array de metadatos y estructura XML JATS</p>
    </div>
    
    <div class="function-box">
        <h4>🔗 convert.php</h4>
        <p><strong>Responsabilidad:</strong> Endpoint API JSON para recibir y procesar archivos</p>
        <p><strong>Métodos:</strong> POST (multipart/form-data)</p>
        <p><strong>Flujo:</strong> Validar archivo → Instanciar parser → Extraer metadatos → Generar XML → Retornar JSON</p>
    </div>
    
    <h3>Frontend (Lado del Cliente)</h3>
    <div class="function-box">
        <h4>🌐 index.php</h4>
        <p><strong>Responsabilidad:</strong> Interfaz de usuario HTML5 + CSS3</p>
        <p><strong>Características:</strong> Drag & drop, vista previa de XML, descarga de archivos</p>
        <p><strong>Paso a paso visual:</strong> 4 pasos mostrados por barra de progreso</p>
    </div>
    
    <div class="function-box">
        <h4>📱 app.js</h4>
        <p><strong>Responsabilidad:</strong> Orquestación de lógica del cliente (JavaScript vanilla)</p>
        <p><strong>Características:</strong> Manejo de eventos, fetch API, manipulación del DOM</p>
        <p><strong>No usa:</strong> jQuery, React, Vue, Angular - código puro</p>
    </div>
    
    <!-- 4. Funciones Clave del Sistema -->
    <h2>4. Funciones Clave del Sistema</h2>
    
    <h3>4.1 Extracción de Metadatos</h3>
    <p><strong>Función de DocxParser:</strong> <code>parseMetadata(array $lines)</code></p>
    <p>Analiza el contenido del documento y extrae automáticamente:</p>
    <ul>
        <li><strong>Identificadores:</strong> DOI, ISSN (versión impresa y electrónica), ID de revista</li>
        <li><strong>Metadatos de artículo:</strong> Títulos (ES/EN), idioma, versión SPS</li>
        <li><strong>Autores:</strong> Nombre completo, ORCID (si está disponible)</li>
        <li><strong>Afiliaciones:</strong> Institución, departamento, estado, país, correo electrónico</li>
        <li><strong>Resúmenes:</strong> Español e inglés (detección automática de secciones)</li>
        <li><strong>Palabras clave:</strong> Tags en ambos idiomas</li>
        <li><strong>Fechas importantes:</strong> Recibido, revisado, aceptado</li>
        <li><strong>Información editorial:</strong> Volumen, número, paginación, ubicación electrónica</li>
        <li><strong>Financiamiento:</strong> Agencias y montos (si aplica)</li>
        <li><strong>Conflictos de interés:</strong> Declaración de potenciales conflictos</li>
        <li><strong>Contribuciones autorales:</strong> Rol de cada autor en el trabajo</li>
    </ul>
    
    <h3>4.2 Procesamiento del Documento Word</h3>
    <p><strong>Función de DocxParser:</strong> <code>extractLines()</code></p>
    <p>Proceso:</p>
    <ol>
        <li>Valida que el archivo sea DOCX (archivo ZIP válido)</li>
        <li>Abre el ZIP y busca <code>word/document.xml</code></li>
        <li>Carga XML con DOMDocument y DOMXPath</li>
        <li>Registra namespace de Word: <code>xmlns:w</code></li>
        <li>Itera párrafos (<code>//w:p</code>) y extrae texto (<code>w:t</code>)</li>
        <li>Limpia líneas vacías y retorna array de líneas no vacías</li>
    </ol>
    
    <h3>4.3 Generación de XML JATS</h3>
    <p><strong>Función de DocxParser:</strong> <code>buildJatsXml(array $meta)</code></p>
    <p>Crea estructura XML válida combinando:</p>
    <ul>
        <li>Plantilla JATS de artículo (<code>&lt;article&gt;</code>)</li>
        <li>Metadatos extraídos del documento</li>
        <li>Elementos de estructura JATS: <code>&lt;article-meta&gt;</code>, <code>&lt;front&gt;</code>, <code>&lt;body&gt;</code></li>
        <li>Información de licencia (CC BY 4.0)</li>
        <li>Atributos de validación (versión XML, encoding)</li>
    </ul>
    
    <h3>4.4 Manejo de Solicitudes HTTP</h3>
    <p><strong>Endpoint:</strong> <code>convert.php</code></p>
    <p><strong>Validaciones:</strong></p>
    <ul>
        <li>Verifica que <code>$_FILES['file']</code> exista y sea válido</li>
        <li>Comprueba <code>$_FILES['file']['error'] === UPLOAD_ERR_OK</code></li>
        <li>Captura todo tipo de excepciones con try-catch</li>
    </ul>
    <p><strong>Respuesta:</strong> JSON con estructura:</p>
    <pre>{
  "success": true|false,
  "xml": "[XML generado aquí]",
  "metadata": {...},
  "filename": "nombre_archivo",
  "error": "mensaje de error (si aplica)"
}</pre>
    
    <h3>4.5 Validación de Metadatos</h3>
    <p>El parser detecta automáticamente:</p>
    <table>
        <tr>
            <th>Campo</th>
            <th>Tipo de Detección</th>
            <th>Patrón/Ejemplo</th>
        </tr>
        <tr>
            <td>DOI</td>
            <td>Regex patrón</td>
            <td>10.18294/sc.2026.5939</td>
        </tr>
        <tr>
            <td>Idioma</td>
            <td>Detección por palabras clave</td>
            <td>"es", "en", "pt"</td>
        </tr>
        <tr>
            <td>ORCID</td>
            <td>Patrón 0000-000X-XXXX-XXXX</td>
            <td>0000-0001-2345-6789</td>
        </tr>
        <tr>
            <td>Email</td>
            <td>Regex de correo estándar</td>
            <td>usuario@ejemplo.com</td>
        </tr>
        <tr>
            <td>Fechas</td>
            <td>Mapeo de meses (es/en/pt)</td>
            <td>15 de enero de 2026</td>
        </tr>
    </table>
    
    <!-- 5. Flujo de Procesamiento -->
    <h2>5. Flujo de Procesamiento</h2>
    
    <h3>Vista General del Flujo</h3>
    <pre>
┌─────────────────────────────────────────────────────────┐
│ 1. USUARIO SUBE ARCHIVO .DOCX                          │
│    └─ Drag & Drop en index.php                         │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 2. VALIDACIÓN EN CLIENTE (app.js)                      │
│    ├─ Verificar tipo MIME                             │
│    └─ Mostrar estado en barra de progreso             │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 3. ENVÍO A SERVIDOR (fetch POST → convert.php)        │
│    └─ multipart/form-data                             │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 4. PROCESAMIENTO EN SERVIDOR (DocxParser)             │
│    ├─ Extraer líneas del DOCX                         │
│    ├─ Analizar metadatos                              │
│    └─ Generar XML JATS                                │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 5. RESPUESTA JSON (convert.php)                        │
│    ├─ XML generado                                    │
│    ├─ Metadatos extraídos                             │
│    └─ Nombre de archivo                               │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 6. PROCESAMIENTO EN CLIENTE (app.js)                   │
│    ├─ Parsear respuesta JSON                          │
│    ├─ Actualizar vista previa de XML                  │
│    └─ Habilitar botón de descarga                     │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│ 7. DESCARGA Y ALMACENAMIENTO                           │
│    └─ Usuario descarga archivo XML                    │
└─────────────────────────────────────────────────────────┘
    </pre>
    
    <!-- 6. Especificaciones Técnicas -->
    <h2>6. Especificaciones Técnicas</h2>
    
    <h3>Stack Tecnológico</h3>
    <table>
        <tr>
            <th>Componente</th>
            <th>Tecnología</th>
            <th>Versión Mínima</th>
        </tr>
        <tr>
            <td>Servidor</td>
            <td>PHP</td>
            <td>8.1+</td>
        </tr>
        <tr>
            <td>Web Server</td>
            <td>Apache / Nginx / PHP Built-in</td>
            <td>-</td>
        </tr>
        <tr>
            <td>Procesamiento XML</td>
            <td>DOMDocument, DOMXPath (PHP nativo)</td>
            <td>Incluido en PHP</td>
        </tr>
        <tr>
            <td>Compresión ZIP</td>
            <td>ZipArchive (PHP nativo)</td>
            <td>Incluido en PHP</td>
        </tr>
        <tr>
            <td>Frontend</td>
            <td>HTML5, CSS3, JavaScript (Vanilla)</td>
            <td>ES6+</td>
        </tr>
    </table>
    
    <h3>Extensiones PHP Requeridas</h3>
    <div class="requirement-grid">
        <h4>Extensiones Habilitadas de Forma Predeterminada</h4>
        <ul>
            <li><code>ext-zip</code> - Para leer archivos DOCX (archivos ZIP)</li>
            <li><code>ext-xml</code> - Para procesar DOM y XML</li>
            <li><code>ext-libxml</code> - Para validación de XML</li>
        </ul>
    </div>
    
    <h3>Formatos y Estándares</h3>
    <ul>
        <li><strong>Entrada:</strong> Microsoft Word 2007+ (.docx)</li>
        <li><strong>Salida:</strong> XML JATS 1.3 con SciELO Publishing Schema (SPS) 1.9</li>
        <li><strong>Encoding:</strong> UTF-8</li>
        <li><strong>Licensias por defecto:</strong> Creative Commons BY 4.0</li>
    </ul>
    
    <!-- 7. Requisitos y Limitaciones -->
    <h2>7. Requisitos y Limitaciones</h2>
    
    <h3>Requisitos del Sistema</h3>
    <ul>
        <li>PHP 8.1 o superior</li>
        <li>Extensiones PHP: ZipArchive, SimpleXML, DOMDocument</li>
        <li>Servidor web (Apache, Nginx) o uso de PHP built-in server</li>
        <li>Navegador moderno con soporte ES6+ JavaScript</li>
        <li>Mínimo 100MB de espacio en disco para archivos temporales</li>
    </ul>
    
    <h3>Detección Automática ✓ (Completado)</h3>
    <p>El sistema extrae automáticamente estos elementos:</p>
    <div class="feature-list">
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>DOI</strong> y ISSN
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Idioma</strong> del artículo
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Títulos</strong> (ES + EN)
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Autores</strong> con ORCID
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Afiliaciones</strong> estructuradas
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Resúmenes</strong> multiidioma
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Palabras clave</strong> (ES + EN)
        </div>
        <div class="feature-item">
            <span class="badge auto">AUTO</span>
            <strong>Fechas</strong> de publicación
        </div>
    </div>
    
    <h3>Completación Manual ⚠ (No Automatizado)</h3>
    <p>Estos elementos deben completarse manualmente en el XML generado:</p>
    <div class="feature-list">
        <div class="feature-item">
            <span class="badge manual">MANUAL</span>
            <strong>Cuerpo</strong> del artículo
        </div>
        <div class="feature-item">
            <span class="badge manual">MANUAL</span>
            <strong>Referencias</strong> bibliográficas
        </div>
        <div class="feature-item">
            <span class="badge manual">MANUAL</span>
            <strong>Paginación</strong> (fpage, lpage)
        </div>
        <div class="feature-item">
            <span class="badge manual">MANUAL</span>
            <strong>Secciones</strong> del cuerpo
        </div>
    </div>
    
    <h3>Limitaciones Técnicas</h3>
    <ul>
        <li><strong>Tamaño máximo de archivo:</strong> Limitado por <code>php.ini</code> (upload_max_filesize, post_max_size)</li>
        <li><strong>Tiempo de procesamiento:</strong> Dependiente del tamaño del documento (~2-5 segundos por MB)</li>
        <li><strong>Archivos temporales:</strong> Se guardan en <code>/uploads</code> y se limpian tras procesamiento</li>
        <li><strong>Soporte de formatos:</strong> Solo soporta DOCX (Word 2007+), no soporta DOC (Word 97-2003)</li>
        <li><strong>Encoding:</strong> El documento DOCX debe estar en UTF-8 o compatible</li>
    </ul>
    
    <!-- 8. Validación y Cumplimiento -->
    <h2>8. Validación y Cumplimiento de Estándares</h2>
    
    <h3>Estándares Implementados</h3>
    <h4>JATS (Journal Article Tag Suite) 1.3</h4>
    <p>Estándar internacional de ISO 12083 para etiquetado de artículos científicos.</p>
    <p><strong>Elementos JATS generados:</strong></p>
    <ul>
        <li><code>&lt;article&gt;</code> - Raíz del documento</li>
        <li><code>&lt;front&gt;</code> - Metadatos del artículo</li>
        <li><code>&lt;article-meta&gt;</code> - Información del artículo</li>
        <li><code>&lt;title-group&gt;</code> - Títulos</li>
        <li><code>&lt;contrib-group&gt;</code> - Contribuidores/autores</li>
        <li><code>&lt;aff&gt;</code> - Afiliaciones</li>
        <li><code>&lt;abstract&gt;</code> - Resúmenes</li>
        <li><code>&lt;kwd-group&gt;</code> - Palabras clave</li>
    </ul>
    
    <h4>SciELO Publishing Schema (SPS) 1.9</h4>
    <p>Especificación adicional de SciELO que extiende JATS con requisitos específicos para la plataforma.</p>
    <p><strong>Conformidad SPS garantizada en:</strong></p>
    <ul>
        <li>Atributos XML-lang para multiidioma</li>
        <li>Estructura de metadatos de revistas</li>
        <li>Formato de DOI y ISSN</li>
        <li>Identificación de autores (ORCID, Lattes)</li>
        <li>Licencia CC BY 4.0 por defecto</li>
    </ul>
    
    <h3>Validación del Documento</h3>
    <p>El sistema incluye mecanismos de validación:</p>
    <ol>
        <li><strong>Validación de entrada:</strong> Verifica que el archivo sea DOCX válido</li>
        <li><strong>Validación XML:</strong> Asegura que el XML generado sea bien formado</li>
        <li><strong>Validación de estructura:</strong> Cumple con esquema DTD de JATS</li>
        <li><strong>Manejo de errores:</strong> Captura y reporta excepciones al cliente</li>
    </ol>
    
    <h3>Testing</h3>
    <p><strong>Archivos de prueba incluidos:</strong></p>
    <ul>
        <li><code>test.php</code> - Suite de pruebas unitarias</li>
        <li><code>test_manual.php</code> - Pruebas de integración manual</li>
        <li><code>debug.php</code> - Herramientas de depuración y diagnóstico</li>
    </ul>
    
    <h3>Documentación de Estructura</h3>
    <p><strong>Archivo de referencia:</strong> <code>pattern.xml</code></p>
    <p>Contiene la plantilla de estructura XML JATS utilizada como base para la generación de documentos.</p>
    
    <!-- Conclusión -->
    <div class="page-break"></div>
    <h2>Conclusión</h2>
    
    <p>SGEtags-xml es una herramienta especializada que automatiza la mayor parte del proceso de conversión de documentos académicos a XML JATS, facilitando la publicación en plataformas de acceso abierto. Su arquitectura simple, sin dependencias externas, la hace fácil de desplegar y mantener.</p>
    
    <h3>Ventajas Principales</h3>
    <ul>
        <li>✓ <strong>Automatización inteligente:</strong> Extraer automáticamente metadatos complejos</li>
        <li>✓ <strong>Independencia:</strong> Sin Composer, sin librerías de terceros</li>
        <li>✓ <strong>Estándares:</strong> Cumple JATS 1.3 y SciELO Publishing Schema 1.9</li>
        <li>✓ <strong>Usabilidad:</strong> Interfaz web intuitiva con Drag & Drop</li>
        <li>✓ <strong>Confiabilidad:</strong> Manejo robusto de errores y excepciones</li>
    </ul>
    
    <h3>Próximas Mejoras Potenciales</h3>
    <ul>
        <li>Soporte para importación de referencias BibTeX</li>
        <li>Generación automática de figuras y tablas desde Word</li>
        <li>Integración con APIs de SciELO para validación remota</li>
        <li>Interfaz de edición de XML en vivo (WYSIWYG para JATS)</li>
    </ul>
    
    <hr style="margin: 40px 0; border: none; border-top: 1px solid #ddd;">
    
    <div style="text-align: center; color: #999; font-size: 12px; margin-top: 40px;">
        <p><strong><?php echo $this->title; ?></strong> — Documentación de Proyecto</p>
        <p>Generado: <?php echo date('d \d\e F \d\e Y', strtotime('2026-05-05')); ?></p>
        <p style="margin-top: 20px; color: #ccc;">© 2024-2026. Todos los derechos reservados.</p>
    </div>
    
</body>
</html>
        <?php
        
        $html = ob_get_clean();
        
        // Send as PDF-like HTML
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="SGEtags-xml_Documentacion.html"');
        echo $html;
    }
}

// Generate and output
$pdf = new PDFDocumentation();
$pdf->generate();
?>
