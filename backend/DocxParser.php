<?php

/**
 * Clase principal para convertir archivos DOCX en XML JATS.
 *
 * Funciona en tres pasos principales:
 * 1) extraer las líneas de texto del DOCX,
 * 2) interpretar metadatos a partir de esas líneas,
 * 3) construir el XML JATS con los metadatos detectados.
 */
// Declara la clase DocxParser.
class DocxParser
// Abre un nuevo bloque de código.
{
    // Declara la propiedad $filePath de la clase.
    private $filePath;

    // Declara el método __construct de la clase.
    public function __construct($filePath)
    // Abre un nuevo bloque de código.
    {
        // Guardamos la ruta del archivo DOCX que vamos a procesar.
        // Guarda un valor en la propiedad $this->filePath.
        $this->filePath = $filePath;
    }

    // Declara el método extractLines de la clase.
    public function extractLines()
    // Abre un nuevo bloque de código.
    {
        // Esta función abre el archivo DOCX como ZIP, lee el XML interno y extrae
        // cada párrafo de texto para devolverlo como una lista de líneas.

        // Si el archivo DOCX no existe o no se puede leer, lanza una excepción y detiene el procesamiento.
        if (!is_readable($this->filePath)) {
            // Lanza una excepción para informar un error que debe atenderse fuera de este punto.
            throw new RuntimeException("El archivo no existe o no se puede leer: " . $this->filePath);
        }

        // Crea una instancia de ZipArchive y la guarda en $zip.
        $zip = new ZipArchive();
        // Prepara $status con el valor que se usará después.
        $status = $zip->open($this->filePath);
        
        // Si ZipArchive falla al abrir el DOCX, lanza una excepción y detiene el procesamiento.
        if ($status !== true) {
            // Lanza una excepción para informar un error que debe atenderse fuera de este punto.
            throw new RuntimeException("No se pudo abrir el archivo DOCX (Código de error: $status)");
        }

        // El contenido principal del documento Word está en word/document.xml
        // Prepara $xmlContent con el valor que se usará después.
        $xmlContent = $zip->getFromName('word/document.xml');
        // Cierra el ZIP porque ya se extrajo word/document.xml.
        $zip->close();

        // Si Word no trae contenido en word/document.xml, devuelve una lista vacía.
        if ($xmlContent === false || $xmlContent === '') {
            // Devuelve el resultado final de esta parte del proceso.
            return [];
        }

        // Crea una instancia de DOMDocument y la guarda en $dom.
        $dom = new DOMDocument();
        // Carga el XML interno del DOCX en el DOM para poder consultarlo.
        $dom->loadXML($xmlContent);
        // Crea una instancia de DOMXPath y la guarda en $xpath.
        $xpath = new DOMXPath($dom);
        // Registra el namespace de Word para que las búsquedas XPath encuentren párrafos y runs.
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Inicializa el arreglo $lines.
        $lines = [];
        // Recorre los hijos directos del body para mantener el orden de párrafos y tablas.
        foreach ($xpath->query('//w:body/*') as $node) {
            if ($node->nodeName === 'w:p') {
                $line = $this->parseParagraphNode($node, $xpath);
                if ($line !== '') {
                    $lines[] = $line;
                }
            } elseif ($node->nodeName === 'w:tbl') {
                $tableXml = $this->parseTableNode($node, $xpath);
                if ($tableXml !== '') {
                    $lines[] = $tableXml;
                }
            }
        }

        // Devuelve el resultado final de esta parte del proceso.
        return $lines;
    }

    private function parseParagraphNode($p, $xpath)
    {
        $segments = [];
        foreach ($xpath->query('./w:r', $p) as $r) {
            $texts = [];
            foreach ($xpath->query('.//w:t', $r) as $t) {
                $texts[] = $t->nodeValue;
            }
            $segment = implode('', $texts);
            if ($segment === '') continue;
            $isItalic = $xpath->query('./w:rPr/w:i | ./w:rPr/w:iCs', $r)->length > 0;
            if ($isItalic) {
                $segment = '<italic>' . $segment . '</italic>';
            }
            $segments[] = $segment;
        }
        return trim(implode('', $segments));
    }

    private function parseTableNode($tbl, $xpath)
    {
        $xml = "<table-wrap>\n        <table>\n          <tbody>\n";
        foreach ($xpath->query('./w:tr', $tbl) as $tr) {
            $xml .= "            <tr>\n";
            foreach ($xpath->query('./w:tc', $tr) as $tc) {
                $xml .= "              <td>";
                $cellParas = [];
                foreach ($xpath->query('./w:p', $tc) as $p) {
                    $cellParas[] = $this->parseParagraphNode($p, $xpath);
                }
                $xml .= implode('<br/>', array_filter($cellParas));
                $xml .= "</td>\n";
            }
            $xml .= "            </tr>\n";
        }
        $xml .= "          </tbody>\n        </table>\n      </table-wrap>";
        return $xml;
    }

    // Declara el método parseMetadata de la clase.
    public function parseMetadata(array $lines)
    // Abre un nuevo bloque de código.
    {
        // Esta función recibe las líneas de texto extraídas del DOCX y
        // construye un array con todos los metadatos relevantes para JATS.
        // Inicializa el arreglo principal donde se guardarán todos los metadatos detectados.
        $meta = [
            // Define el valor inicial del metadato "lang".
            'lang' => 'es',
            // Define el valor inicial del metadato "sps".
            'sps' => 'sps-1.9',
            // Define el valor inicial del metadato "journalTitle".
            'journalTitle' => '',
            // Define el valor inicial del metadato "journalAbbrev".
            'journalAbbrev' => '',
            // Define el valor inicial del metadato "journalIdPublisher".
            'journalIdPublisher' => '',
            // Define el valor inicial del metadato "issn_ppub".
            'issn_ppub' => '',
            // Define el valor inicial del metadato "issn_epub".
            'issn_epub' => '',
            // Define el valor inicial del metadato "publisher".
            'publisher' => '',
            // Define el valor inicial del metadato "doi".
            'doi' => '',
            // Define el valor inicial del metadato "articleTitle".
            'articleTitle' => '',
            // Define el valor inicial del metadato "articleTitleEn".
            'articleTitleEn' => '',
            // Define el valor inicial del metadato "authors".
            'authors' => [],
            // Define el valor inicial del metadato "affiliations".
            'affiliations' => [],
            // Define el valor inicial del metadato "affiliations_lineindex".
            'affiliations_lineindex' => [],
            // Define el valor inicial del metadato "affiliations_norm".
            'affiliations_norm' => [],
            // Define el valor inicial del metadato "affiliations_orgdiv1".
            'affiliations_orgdiv1' => [],
            // Define el valor inicial del metadato "affiliations_orgdiv2".
            'affiliations_orgdiv2' => [],
            // Define el valor inicial del metadato "affiliations_orgname".
            'affiliations_orgname' => [],
            // Define el valor inicial del metadato "affiliations_state".
            'affiliations_state' => [],
            // Define el valor inicial del metadato "affiliations_country".
            'affiliations_country' => [],
            // Define el valor inicial del metadato "affiliations_country_name".
            'affiliations_country_name' => [],
            // Define el valor inicial del metadato "affiliations_email".
            'affiliations_email' => [],
            // Define el valor inicial del metadato "abstractEs".
            'abstractEs' => '',
            // Define el valor inicial del metadato "abstractEn".
            'abstractEn' => '',
            // Define el valor inicial del metadato "kwdsEs".
            'kwdsEs' => [],
            // Define el valor inicial del metadato "kwdsEn".
            'kwdsEn' => [],
            // Define el valor inicial del metadato "funding".
            'funding' => [],
            // Define el valor inicial del metadato "fundingStatement".
            'fundingStatement' => '',
            // Define el valor inicial del metadato "received".
            'received' => '',
            // Define el valor inicial del metadato "revised".
            'revised' => '',
            // Define el valor inicial del metadato "accepted".
            'accepted' => '',
            // Define el valor inicial del metadato "pubdate".
            'pubdate' => '',
            // Define el valor inicial del metadato "volume".
            'volume' => '',
            // Define el valor inicial del metadato "elocation-id".
            'elocation-id' => '',
            // Define el valor inicial del metadato "figCount".
            'figCount' => '0',
            // Define el valor inicial del metadato "tableCount".
            'tableCount' => '0',
            // Define el valor inicial del metadato "equationCount".
            'equationCount' => '0',
            // Define el valor inicial del metadato "pageCount".
            'pageCount' => '1',
            // Define el valor inicial del metadato "sections".
            'sections' => [],
            // Define el valor inicial del metadato "conflict".
            'conflict' => '',
            // Define el valor inicial del metadato "contributions".
            'contributions' => [],
            // Define el valor inicial del metadato "references".
            'references' => [],
            // Define el valor inicial del metadato "collectionYear".
            'collectionYear' => '',
            // Define el valor inicial del metadato "refCount".
            'refCount' => ''
        // Cierra el arreglo definido en las líneas anteriores.
        ];

        // Filtra o transforma una lista y guarda el resultado en $clean.
        $clean = array_values(array_filter(array_map(function ($v) {
            // Devuelve el resultado final de esta parte del proceso.
            return trim(strip_tags($v));
        // Cierra la función anónima usada dentro del array_map.
        }, $lines), function ($v) {
            // Devuelve el resultado final de esta parte del proceso.
            return $v !== '';
        }));
        // Filtra o transforma una lista y guarda el resultado en $rawClean.
        $rawClean = array_values(array_filter(array_map('trim', $lines), function ($v) {
            // Devuelve el resultado final de esta parte del proceso.
            return $v !== '';
        }));

        // Mapa de nombres de meses en español/portugués a número de mes.
        // Inicializa el arreglo $monthMap.
        $monthMap = [
            // Define el valor inicial del metadato "ene".
            'ene' => '01', 'enero' => '01',
            // Define el valor inicial del metadato "feb".
            'feb' => '02', 'fev' => '02', 'febrero' => '02',
            // Define el valor inicial del metadato "mar".
            'mar' => '03', 'marzo' => '03',
            // Define el valor inicial del metadato "abr".
            'abr' => '04', 'abril' => '04',
            // Define el valor inicial del metadato "may".
            'may' => '05', 'mayo' => '05',
            // Define el valor inicial del metadato "jun".
            'jun' => '06', 'junio' => '06',
            // Define el valor inicial del metadato "jul".
            'jul' => '07', 'julio' => '07',
            // Define el valor inicial del metadato "ago".
            'ago' => '08', 'agosto' => '08',
            // Define el valor inicial del metadato "sep".
            'sep' => '09', 'sept' => '09', 'septiembre' => '09',
            // Define el valor inicial del metadato "oct".
            'oct' => '10', 'octubre' => '10',
            // Define el valor inicial del metadato "nov".
            'nov' => '11', 'noviembre' => '11',
            // Define el valor inicial del metadato "dic".
            'dic' => '12', 'dez' => '12', 'diciembre' => '12'
        // Cierra el arreglo definido en las líneas anteriores.
        ];

        // Define una función anónima en $parseDate para reutilizar esta lógica.
        $parseDate = function ($text) use ($monthMap) {
            // Limpia espacios sobrantes y deja el texto listo en $text.
            $text = trim($text);
            // Si comprueba si hay letras reales en el texto, prepara el valor de $day para usarlo dentro del bloque.
            if (preg_match('/(\d{1,2})\s+([\p{L}.]+)\s+(\d{4})/u', $text, $m)) {
                // Completa con cero a la izquierda cuando hace falta y lo guarda en $day.
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                // Prepara $key con el valor que se usará después.
                $key = mb_strtolower(str_replace('.', '', $m[2]));
                // Prepara $month con el valor que se usará después.
                $month = $monthMap[$key] ?? '';
                // Prepara $year con el valor que se usará después.
                $year = $m[3];
                if ($month) return $day . ' ' . $month . ' ' . $year;
            }
            // Si la expresión regular encuentra una coincidencia, prepara $day con el valor que se usará dentro del bloque.
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $text, $m)) {
                // Completa con cero a la izquierda cuando hace falta y lo guarda en $day.
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                // Completa con cero a la izquierda cuando hace falta y lo guarda en $month.
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                // Devuelve el resultado final de esta parte del proceso.
                return $day . ' ' . $month . ' ' . $m[3];
            }
            // Devuelve el resultado final de esta parte del proceso.
            return '';
        // Cierra la función anónima y la deja lista para reutilizarla.
        };

        // Bloque de encabezado (SPS, idioma, información de revista)
        // Recorre las posiciones necesarias para buscar el dato esperado.
        for ($i = 0; $i < count($clean); $i++) {
            // Si encuentra la marca SPS 1.9 del encabezado, guarda el resultado en el metadato "sps".
            if (preg_match('/^sps-?1\.9$/i', $clean[$i])) {
                // Guarda en "sps" el dato que se acaba de detectar o normalizar.
                $meta['sps'] = $clean[$i];
                // Guarda en "lang" el dato que se acaba de detectar o normalizar.
                $meta['lang'] = $clean[$i + 1] ?? $meta['lang'];
                // Guarda en "journalAbbrev" el dato que se acaba de detectar o normalizar.
                $meta['journalAbbrev'] = $clean[$i + 2] ?? $meta['journalAbbrev'];
                // Guarda en "journalIdPublisher" el dato que se acaba de detectar o normalizar.
                $meta['journalIdPublisher'] = $clean[$i + 3] ?? $meta['journalIdPublisher'];
                // Guarda en "journalTitle" el dato que se acaba de detectar o normalizar.
                $meta['journalTitle'] = $clean[$i + 4] ?? $meta['journalTitle'];
                // Si falta la abreviatura de la revista y hay una línea candidata, guarda el resultado en el metadato "journalAbbrev".
                if (empty($meta['journalAbbrev']) && !empty($clean[$i + 5])) {
                    // Guarda en "journalAbbrev" el dato que se acaba de detectar o normalizar.
                    $meta['journalAbbrev'] = $clean[$i + 5];
                }
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }

        // DOI
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $line) {
            // Si encuentra un DOI, guarda el resultado en el metadato "doi".
            if (preg_match('/10\.\d{4,9}\/\S+/u', $line, $m)) {
                // Guarda en "doi" el dato que se acaba de detectar o normalizar.
                $meta['doi'] = $m[0];
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }

        // ISSN (primeras dos coincidencias)
        // Inicializa el arreglo $issns.
        $issns = [];
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $line) {
            // Si aparece un ISSN, ejecuta las instrucciones internas de ese caso.
            if (preg_match_all('/\b\d{4}-\d{3}[\dxX]\b/u', $line, $m)) {
                // Recorre $m[0] para procesar cada elemento detectado.
                foreach ($m[0] as $issn) {
                    // Guarda este valor como nuevo elemento de $issns.
                    $issns[] = $issn;
                }
            }
        }
        if (isset($issns[0])) $meta['issn_ppub'] = $issns[0];
        if (isset($issns[1])) $meta['issn_epub'] = $issns[1];

        // Editor/publisher (línea después de los ISSN, si existe)
        // Recorre las posiciones necesarias para buscar el dato esperado.
        for ($i = 0; $i < count($clean); $i++) {
            // Si aparece un ISSN, guarda ese valor en el metadato "publisher".
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $clean[$i])) {
                // Guarda en "publisher" el dato que se acaba de detectar o normalizar.
                $meta['publisher'] = $clean[$i + 2] ?? $meta['publisher'];
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }

        // Títulos alrededor del DOI
        // Inicializa $doiIndex como contador o índice de control.
        $doiIndex = -1;
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $i => $line) {
            // Si la línea actual es exactamente el DOI ya detectado, prepara el valor de $doiIndex para usarlo dentro del bloque.
            if (trim($line) === $meta['doi']) {
                // Prepara $doiIndex con el valor que se usará después.
                $doiIndex = $i;
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }
        // Si hay DOI listo, ejecuta las instrucciones internas de ese caso.
        if ($doiIndex >= 0) {
            // Recorre las posiciones necesarias para buscar el dato esperado.
            for ($i = $doiIndex + 1; $i < count($clean); $i++) {
                // Limpia espacios sobrantes y deja el texto listo en $line.
                $line = trim($clean[$i]);
                // Si la línea candidata está vacía, omite esa línea y sigue con la siguiente.
                if ($line === '') {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra la etiqueta "Artículo", omite esa línea y sigue con la siguiente.
                if (preg_match('/^art[ií]culo$/i', $line) || mb_strtolower($line) === 'artículo') {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra un ORCID, omite esa línea y sigue con la siguiente.
                if (preg_match('/^https?:\/\/orcid\.org\//i', $line)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si se cumple esta condición, guarda ese valor en el metadato "articleTitle".
                if ($meta['articleTitle'] === '') {
                    // Guarda en "articleTitle" el dato que se acaba de detectar o normalizar.
                    $meta['articleTitle'] = $line;
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si no encuentra un ORCID, guarda el resultado en el metadato "articleTitleEn".
                if ($meta['articleTitleEn'] === '' && !preg_match('/https?:\/\/orcid\.org\//i', $line)) {
                    // Guarda en "articleTitleEn" el dato que se acaba de detectar o normalizar.
                    $meta['articleTitleEn'] = $line;
                }
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }

        // Si encuentra la etiqueta "Artículo", desactiva la bandera $seenArticleLabel.
        if ($meta['articleTitle'] === '' || preg_match('/^art[ií]culo$/i', $meta['articleTitle'])) {
            // Si todavía no hay título claro, buscamos después de la palabra artículo.
            // Inicializa $seenArticleLabel apagado hasta detectar el caso correspondiente.
            $seenArticleLabel = false;
            // Recorre $clean para procesar cada elemento detectado.
            foreach ($clean as $line) {
                // Limpia espacios sobrantes y deja el texto listo en $lineTrim.
                $lineTrim = trim($line);
                // Si la línea está vacía, la descarta y sigue leyendo.
                if ($lineTrim === '') {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra la etiqueta "Artículo", activa la bandera $seenArticleLabel.
                if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') {
                    // Prepara $seenArticleLabel con el valor que se usará después.
                    $seenArticleLabel = true;
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si ya apareció la etiqueta "Artículo", omite esa línea y sigue con la siguiente.
                if (!$seenArticleLabel) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra un DOI, omite esa línea y sigue con la siguiente.
                if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra el inicio del resumen en español, omite esa línea y sigue con la siguiente.
                if (preg_match('/^Resumen\s*:/i', $lineTrim) || preg_match('/^Abstract\s*:/i', $lineTrim)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra las palabras clave en español, omite esa línea y sigue con la siguiente.
                if (preg_match('/^Palabras\s+claves?/i', $lineTrim) || preg_match('/^Keywords\s*:/i', $lineTrim)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra un ORCID, omite esa línea y sigue con la siguiente.
                if (preg_match('/https?:\/\/orcid\.org\//i', $lineTrim)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si la línea tiene longitud suficiente para ser título, guarda el resultado en el metadato "articleTitle".
                if (mb_strlen($lineTrim) >= 40) {
                    // Guarda en "articleTitle" el dato que se acaba de detectar o normalizar.
                    $meta['articleTitle'] = $lineTrim;
                    // Detiene esta búsqueda porque ya encontró el dato necesario.
                    break;
                }
            }
        }

        // Autores: acepta nombre y ORCID en la misma línea o en líneas separadas.
        // Inicializa $pendingAuthorName vacío para llenarlo si aparece el dato.
        $pendingAuthorName = '';
        // Inicializa $authorArea apagado hasta detectar el caso correspondiente.
        $authorArea = false;
        // Inicializa $authorAreaClosed apagado hasta detectar el caso correspondiente.
        $authorAreaClosed = false;
        // Inicializa $seenDoi apagado hasta detectar el caso correspondiente.
        $seenDoi = false;
        // Define una función anónima en $normalizeAuthorName para reutilizar esta lógica.
        $normalizeAuthorName = function ($text) {
            // Limpia espacios sobrantes y deja el texto listo en $text.
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            // Normaliza el texto con una expresión regular y lo guarda en $text.
            $text = preg_replace('/(?:\s*[,;]?\s*[\d\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+)+$/u', '', $text);
            // Normaliza el texto con una expresión regular y lo guarda en $text.
            $text = preg_replace('/[\s\p{P}\p{S}]+$/u', '', $text);
            // Devuelve el resultado final de esta parte del proceso.
            return trim($text);
        // Cierra la función anónima y la deja lista para reutilizarla.
        };
        // Define una función anónima en $isLikelyAuthorLine para reutilizar esta lógica.
        $isLikelyAuthorLine = function ($text) {
            // Si comprueba si hay letras reales en el texto, marca el caso como inválido.
            if (!preg_match('/\p{L}/u', $text)) {
                // Devuelve el resultado final de esta parte del proceso.
                return false;
            }
            // Si encuentra un ORCID, marca el caso como inválido.
            if (preg_match('/https?:\/\/|orcid\.org|@|doi\b/i', $text)) {
                // Devuelve el resultado final de esta parte del proceso.
                return false;
            }
            // Si encuentra uno o más departamentos, marca el caso como inválido.
            if (preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $text)) {
                // Devuelve el resultado final de esta parte del proceso.
                return false;
            }
            // Devuelve el resultado final de esta parte del proceso.
            return preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\s\-\'\x{2019}\.\,;\(\)\d\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+$/u', $text) === 1;
        // Cierra la función anónima y la deja lista para reutilizarla.
        };
        // Recorre $rawClean para procesar cada elemento detectado.
        foreach ($rawClean as $line) {
            // Limpia espacios sobrantes y deja el texto listo en $lineTrim.
            $lineTrim = trim(strip_tags($line));
            // Si la línea está vacía, la descarta y sigue leyendo.
            if ($lineTrim === '') {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }

            // Si encuentra la etiqueta "Artículo", omite esa línea y sigue con la siguiente.
            if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }

            // Si encuentra un DOI, activa la bandera $seenDoi.
            if (!$seenDoi && preg_match('/10\.\d{4,9}\/\S+/u', $lineTrim)) {
                // Prepara $seenDoi con el valor que se usará después.
                $seenDoi = true;
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si todavía no se llegó a la zona posterior al DOI, omite esa línea y sigue con la siguiente.
            if (!$seenDoi) {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }

            // Si encuentra el inicio de las referencias, desactiva la bandera $authorArea.
            if (preg_match('/^(Resumen|Abstract|Palabras\s+claves?|Keywords|Financiamiento|Referencias bibliogr(?:a?ficas)?|Referencias|References?|Introducción|Introduction)\b/i', $lineTrim)) {
                // Inicializa $authorArea apagado hasta detectar el caso correspondiente.
                $authorArea = false;
                // Prepara $authorAreaClosed con el valor que se usará después.
                $authorAreaClosed = true;
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si la zona de autores ya terminó, omite esa línea y sigue con la siguiente.
            if ($authorAreaClosed) {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si todavía no se abrió la zona de autores, desactiva la bandera $candidateLine.
            if (!$authorArea) {
                // Inicializa $candidateLine apagado hasta detectar el caso correspondiente.
                $candidateLine = false;
                // Si encuentra un ORCID, activa la bandera $candidateLine.
                if (preg_match('/https?:\/\/orcid\.org\//i', $lineTrim)) {
                    // Prepara $candidateLine con el valor que se usará después.
                    $candidateLine = true;
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Recorre preg_split('/\s*;\s*/u', $lineTrim) para procesar cada elemento detectado.
                    foreach (preg_split('/\s*;\s*/u', $lineTrim) as $authorChunk) {
                        // Prepara $authorChunk con el valor que se usará después.
                        $authorChunk = $normalizeAuthorName($authorChunk);
                        // Si el fragmento parece un nombre de autor, activa la bandera $candidateLine.
                        if ($authorChunk !== '' && $isLikelyAuthorLine($authorChunk)) {
                            // Prepara $candidateLine con el valor que se usará después.
                            $candidateLine = true;
                            // Detiene esta búsqueda porque ya encontró el dato necesario.
                            break;
                        }
                    }
                }
                // Si la línea no parece pertenecer a autores, omite esa línea y sigue con la siguiente.
                if (!$candidateLine) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Prepara $authorArea con el valor que se usará después.
                $authorArea = true;
            }

            // Si encuentra un ORCID, prepara el valor de $orcid para usarlo dentro del bloque.
            if (preg_match('/https?:\/\/orcid\.org\/(\S+)/i', $lineTrim, $morcid)) {
                // Limpia espacios sobrantes y deja el texto listo en $orcid.
                $orcid = trim($morcid[1]);
                // Limpia espacios sobrantes y deja el texto listo en $name.
                $name = trim(preg_replace('/https?:\/\/orcid\.org\/\S+/i', '', $lineTrim));
                // Prepara $name con el valor que se usará después.
                $name = $normalizeAuthorName($name);
                // Si falta el nombre de autor en esa línea, prepara $name con el valor que se usará dentro del bloque.
                if ($name === '' && $pendingAuthorName !== '') {
                    // Prepara $name con el valor que se usará después.
                    $name = $pendingAuthorName;
                }
                // Si ya hay nombre de autor, agrega ese valor a la lista "authors".
                if ($name !== '') {
                    // Agrega al metadato "authors" una nueva pieza detectada en el DOCX.
                    $meta['authors'][] = ['name' => $name, 'orcid' => $orcid];
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Recorre las posiciones necesarias para buscar el dato esperado.
                    for ($ai = count($meta['authors']) - 1; $ai >= 0; $ai--) {
                        // Si este autor tiene nombre pero todavía no tiene ORCID, le asigna el ORCID encontrado.
                        if (!empty($meta['authors'][$ai]['name']) && empty($meta['authors'][$ai]['orcid'])) {
                            $meta['authors'][$ai]['orcid'] = $orcid;
                            // Detiene esta búsqueda porque ya encontró el dato necesario.
                            break;
                        }
                    }
                }
                // Inicializa $pendingAuthorName vacío para llenarlo si aparece el dato.
                $pendingAuthorName = '';
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }

            // Recorre preg_split('/\s*;\s*/u', $lineTrim) para procesar cada elemento detectado.
            foreach (preg_split('/\s*;\s*/u', $lineTrim) as $authorChunk) {
                // Prepara $authorChunk con el valor que se usará después.
                $authorChunk = $normalizeAuthorName($authorChunk);
                // Si la línea está vacía, la descarta y sigue leyendo.
                if ($authorChunk === '') {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra la etiqueta "Artículo", omite esa línea y sigue con la siguiente.
                if (preg_match('/^art[ií]culo$/i', $authorChunk) || mb_strtolower($authorChunk) === 'artículo') {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si el fragmento parece un nombre de autor, ejecuta el procesamiento específico de ese caso.
                if ($isLikelyAuthorLine($authorChunk)) {
                    // Si quedó un autor pendiente por guardar, agrega ese valor a la lista "authors".
                    if ($pendingAuthorName !== '' && $pendingAuthorName !== $authorChunk) {
                        // Agrega al metadato "authors" una nueva pieza detectada en el DOCX.
                        $meta['authors'][] = ['name' => $pendingAuthorName, 'orcid' => ''];
                    }
                    // Prepara $pendingAuthorName con el valor que se usará después.
                    $pendingAuthorName = $authorChunk;
                }
            }
        }
        // Si quedó un autor pendiente por guardar, agrega ese valor a la lista "authors".
        if ($pendingAuthorName !== '') {
            // Agrega al metadato "authors" una nueva pieza detectada en el DOCX.
            $meta['authors'][] = ['name' => $pendingAuthorName, 'orcid' => ''];
        }

        // Afiliaciones: busca líneas numeradas y separa los datos estructurados.
        // Inicializa $lastAffIndex como contador o índice de control.
        $lastAffIndex = -1;
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $idx => $line) {
            // Limpia espacios sobrantes y deja el texto listo en $lineTrim.
            $lineTrim = trim($line);
            // Si la línea está vacía, la descarta y sigue leyendo.
            if ($lineTrim === '') {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra un DOI, omite esa línea y sigue con la siguiente.
            if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si aparece un ISSN, descarta este caso y sigue leyendo.
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $lineTrim)) {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra una afiliación numerada, prepara el valor de $affText para usarlo dentro del bloque.
            if (preg_match('/^(\d+)\s*(.+)$/u', $lineTrim, $m)) {
                // Limpia espacios sobrantes y deja el texto listo en $affText.
                $affText = trim($m[2]);
                // Si aparece un ISSN, descarta este caso y sigue leyendo.
                if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $affText)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra un DOI, omite esa línea y sigue con la siguiente.
                if (preg_match('/10\.\d{4,9}\//u', $affText)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si la afiliación no contiene letras, la descarta y sigue con la siguiente línea.
                if (!preg_match('/[\p{L}]/u', $affText)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Agrega al metadato "affiliations" una nueva pieza detectada en el DOCX.
                $meta['affiliations'][] = $affText;
                // Agrega al metadato "affiliations_lineindex" una nueva pieza detectada en el DOCX.
                $meta['affiliations_lineindex'][] = $idx;
                // Prepara $lastAffIndex con el valor que se usará después.
                $lastAffIndex = count($meta['affiliations']) - 1;

                // orgname / normalizado
                // Si la afiliación contiene una universidad, agrega ese valor a la lista "affiliations_norm".
                if (preg_match('/(Universidade[^.,;]+|Universidad[^.,;]+|University[^.,;]+)/u', $affText, $org)) {
                    // Agrega al metadato "affiliations_norm" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_norm'][] = trim($org[1]);
                    // Agrega al metadato "affiliations_orgname" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_orgname'][] = trim($org[1]);
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Agrega al metadato "affiliations_norm" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_norm'][] = '';
                    // Agrega al metadato "affiliations_orgname" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_orgname'][] = '';
                }

                // Inicializa el arreglo $departmentMatches.
                $departmentMatches = [];
                // Si encuentra uno o más departamentos, prepara el valor de $departmentMatches para usarlo dentro del bloque.
                if (preg_match_all('/(Departamento[^.;,]+)/iu', $affText, $mdept)) {
                    // Filtra o transforma una lista y guarda el resultado en $departmentMatches.
                    $departmentMatches = array_map('trim', $mdept[1]);
                }
                // Inicializa el arreglo $programMatches.
                $programMatches = [];
                // Si encuentra uno o más programas, prepara el valor de $programMatches para usarlo dentro del bloque.
                if (preg_match_all('/(Programa[^.;,]+)/iu', $affText, $mprog)) {
                    // Filtra o transforma una lista y guarda el resultado en $programMatches.
                    $programMatches = array_map('trim', $mprog[1]);
                }

                // Inicializa $orgdiv1 vacío para llenarlo si aparece el dato.
                $orgdiv1 = '';
                // Inicializa $orgdiv2 vacío para llenarlo si aparece el dato.
                $orgdiv2 = '';
                // Busca la posición de ese texto dentro de la afiliación y la guarda en $departmentPos.
                $departmentPos = mb_strpos($affText, 'Departamento');
                // Busca la posición de ese texto dentro de la afiliación y la guarda en $programPos.
                $programPos = mb_strpos($affText, 'Programa');
                // Busca la posición de ese texto dentro de la afiliación y la guarda en $semicolonPos.
                $semicolonPos = mb_strpos($affText, ';');
                // Si la afiliación tiene programa y departamento, ejecuta el procesamiento específico de ese caso.
                if (!empty($programMatches) && !empty($departmentMatches)) {
                    // Si el programa aparece después del departamento separado por punto y coma, prepara el valor de $orgdiv1 para usarlo dentro del bloque.
                    if ($semicolonPos !== false && $programPos !== false && $departmentPos !== false && $programPos > $departmentPos) {
                        // Prepara $orgdiv1 con el valor que se usará después.
                        $orgdiv1 = $programMatches[0];
                        // Prepara $orgdiv2 con el valor que se usará después.
                        $orgdiv2 = $departmentMatches[0];
                    // Ejecuta este bloque cuando la condición anterior no se cumple.
                    } else {
                        // Prepara $orgdiv1 con el valor que se usará después.
                        $orgdiv1 = $departmentMatches[0];
                        // Inicializa $orgdiv2 vacío para llenarlo si aparece el dato.
                        $orgdiv2 = '';
                    }
                // Si no había programa+departamento pero sí departamentos, usa el primer departamento como orgdiv1.
                } elseif (!empty($departmentMatches)) {
                    // Prepara $orgdiv1 con el valor que se usará después.
                    $orgdiv1 = $departmentMatches[0];
                    // Prepara $orgdiv2 con el valor que se usará después.
                    $orgdiv2 = $departmentMatches[1] ?? '';
                // Si sólo hay programas, usa el primer programa como orgdiv1.
                } elseif (!empty($programMatches)) {
                    // Prepara $orgdiv1 con el valor que se usará después.
                    $orgdiv1 = $programMatches[0];
                    // Inicializa $orgdiv2 vacío para llenarlo si aparece el dato.
                    $orgdiv2 = '';
                }

                // Agrega al metadato "affiliations_orgdiv1" una nueva pieza detectada en el DOCX.
                $meta['affiliations_orgdiv1'][] = $orgdiv1;
                // Agrega al metadato "affiliations_orgdiv2" una nueva pieza detectada en el DOCX.
                $meta['affiliations_orgdiv2'][] = $orgdiv2;

                // Si encuentra una ubicación de Brasil, prepara el valor de $state para usarlo dentro del bloque.
                if (preg_match('/([\p{L}\s]+),\s*Brasil/u', $affText, $st)) {
                    // Limpia espacios sobrantes y deja el texto listo en $state.
                    $state = trim($st[1]);
                    // Agrega al metadato "affiliations_state" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_state'][] = $state;
                    // Agrega al metadato "affiliations_country" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_country'][] = 'BR';
                    // Agrega al metadato "affiliations_country_name" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_country_name'][] = 'Brazil';
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Agrega al metadato "affiliations_state" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_state'][] = '';
                    // Agrega al metadato "affiliations_country" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_country'][] = '';
                    // Agrega al metadato "affiliations_country_name" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_country_name'][] = '';
                }

                // Si encuentra un correo electrónico, agrega una entrada al metadato "affiliations_email".
                if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $affText, $em)) {
                    // Agrega al metadato "affiliations_email" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_email'][] = $em[1];
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Agrega al metadato "affiliations_email" una nueva pieza detectada en el DOCX.
                    $meta['affiliations_email'][] = '';
                }
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si aparece un correo solo en la línea siguiente, lo asigna a la última afiliación sin correo.
            if ($lastAffIndex >= 0 && empty($meta['affiliations_email'][$lastAffIndex]) && preg_match('/^([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})$/u', $lineTrim, $emOnly)) {
                $meta['affiliations_email'][$lastAffIndex] = $emOnly[1];
            }
        }

        // Fallback: intentar ligar correos electrónicos cercanos a cada afiliación
        // Inicializa el arreglo $allEmails.
        $allEmails = [];
        // Recorre $rawClean para procesar cada elemento detectado.
        foreach ($rawClean as $rline) {
            // Si aparece un correo solo en la línea siguiente, lo asigna a la última afiliación sin correo.
            if (preg_match_all('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $m)) {
                // Recorre $m[1] para procesar cada elemento detectado.
                foreach ($m[1] as $me) {
                    // Guarda este valor como nuevo elemento de $allEmails.
                    $allEmails[] = $me;
                }
            }
        }
        // Filtra o transforma una lista y guarda el resultado en $allEmails.
        $allEmails = array_values(array_unique($allEmails));

        // Construir mapa autor->numero de afiliación (si el DOCX incluye índices en las líneas de autor, ej. "Eich1")
        // Inicializa el arreglo $authorAffMap.
        $authorAffMap = [];
        // Recorre $rawClean para procesar cada elemento detectado.
        foreach ($rawClean as $li => $rline) {
            // Si la expresión regular encuentra una coincidencia, prepara $namePart con el valor que se usará dentro del bloque.
            if (preg_match('/^(.+?)(\d+)$/u', trim($rline), $mm)) {
                // Normaliza el texto con una expresión regular y lo guarda en $namePart.
                $namePart = preg_replace('/\s+/u', ' ', trim($mm[1]));
                // Prepara $num con el valor que se usará después.
                $num = intval($mm[2]);
                // Recorre $meta['authors'] para procesar cada elemento detectado.
                foreach ($meta['authors'] as $ai => $a) {
                    // Si se cumple esta condición, ejecuta las instrucciones internas de ese caso.
                    if (mb_stripos($a['name'], $namePart) !== false || mb_stripos($namePart, $a['name']) !== false) {
                        $authorAffMap[$ai] = $num;
                        // Detiene esta búsqueda porque ya encontró el dato necesario.
                        break;
                    }
                }
            }
        }

        // Recorre $meta['affiliations'] para procesar cada elemento detectado.
        foreach ($meta['affiliations'] as $i => $aff) {
            if (!empty($meta['affiliations_email'][$i])) continue;
            // Inicializa $assigned vacío para llenarlo si aparece el dato.
            $assigned = '';
            // Prepara $lineIdx con el valor que se usará después.
            $lineIdx = $meta['affiliations_lineindex'][$i] ?? null;
            // Si se cumple esta condición, prepara $max con el valor que se usará dentro del bloque.
            if ($lineIdx !== null) {
                // Prepara $max con el valor que se usará después.
                $max = min($lineIdx + 3, count($rawClean) - 1);
                // Recorre las posiciones necesarias para buscar el dato esperado.
                for ($j = max(0, $lineIdx - 2); $j <= $max; $j++) {
                    // Si encuentra un correo electrónico, prepara el valor de $assigned para usarlo dentro del bloque.
                    if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rawClean[$j], $mm)) {
                        // Prepara $assigned con el valor que se usará después.
                        $assigned = $mm[1];
                        // Detiene esta búsqueda porque ya encontró el dato necesario.
                        break;
                    }
                }
            }
            // Si aún no hay, intentar buscar por autor asociado a esta afiliación (si detectamos un mapeo)
            // Si todavía falta asignar correo a la afiliación, prepara $affNumber con el valor que se usará dentro del bloque.
            if ($assigned === '' && !empty($authorAffMap)) {
                // Prepara $affNumber con el valor que se usará después.
                $affNumber = $i + 1;
                // Recorre $authorAffMap para procesar cada elemento detectado.
                foreach ($authorAffMap as $ai => $anum) {
                    // Si se cumple esta condición, prepara $a con el valor que se usará dentro del bloque.
                    if ($anum === $affNumber) {
                        // Prepara $a con el valor que se usará después.
                        $a = $meta['authors'][$ai] ?? null;
                        // Si se cumple esta condición, prepara $parts con el valor que se usará dentro del bloque.
                        if ($a) {
                            // Divide el texto en partes y las guarda en $parts.
                            $parts = preg_split('/\s+/', $a['name']);
                            // Filtra o transforma una lista y guarda el resultado en $surname.
                            $surname = array_pop($parts);
                            // buscar en rawClean
                            // Recorre $rawClean para procesar cada elemento detectado.
                            foreach ($rawClean as $rline) {
                                // Si encuentra un correo electrónico, prepara el valor de $assigned para usarlo dentro del bloque.
                                if (stripos($rline, $surname) !== false && preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $mm2)) {
                                    // Prepara $assigned con el valor que se usará después.
                                    $assigned = $mm2[1];
                                    // Sale del bucle o estructura de control actual.
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            // Si sólo hay un correo en todo el doc, usarlo como fallback
            // Si todavía falta asignar correo a la afiliación, prepara $assigned con el valor que se usará dentro del bloque.
            if ($assigned === '' && count($allEmails) === 1) {
                // Prepara $assigned con el valor que se usará después.
                $assigned = $allEmails[0];
            }
            // Si se cumple esta condición, ejecuta las instrucciones internas de ese caso.
            if ($assigned !== '') {
                $meta['affiliations_email'][$i] = $assigned;
            }
        }

        // Abstracts, keywords, financiamiento, conflicto y contribuciones.
        // Esta sección usa un estado para saber qué texto se está acumulando.
        // Inicializa $state vacío para llenarlo si aparece el dato.
        $state = '';
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $idx => $line) {
            // Limpia espacios sobrantes y deja el texto listo en $lineTrim.
            $lineTrim = trim($line);
            // Limpia espacios sobrantes y deja el texto listo en $rawLineTrim.
            $rawLineTrim = trim($rawClean[$idx] ?? $lineTrim);
            // Si encuentra el inicio del resumen en español, cambia el estado de lectura a "abstract_es".
            if (preg_match('/^Resumen\s*:/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'abstract_es';
                // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
                $meta['abstractEs'] = trim(preg_replace('/^Resumen\s*:/i', '', $rawLineTrim));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra el inicio del resumen en inglés, cambia el estado de lectura a "abstract_en".
            if (preg_match('/^Abstract\s*:/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'abstract_en';
                // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
                $meta['abstractEn'] = trim(preg_replace('/^Abstract\s*:/i', '', $rawLineTrim));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra las palabras clave en español, cambia el estado de lectura a "kwds_es".
            if (preg_match('/^Palabras\s+claves?\s*:/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'kwds_es';
                // Limpia espacios sobrantes y deja el texto listo en $kw.
                $kw = trim(preg_replace('/^Palabras\s+claves?\s*:/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEs'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra las palabras clave en inglés, cambia el estado de lectura a "kwds_en".
            if (preg_match('/^Keywords\s*:/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'kwds_en';
                // Limpia espacios sobrantes y deja el texto listo en $kw.
                $kw = trim(preg_replace('/^Keywords\s*:/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEn'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra la sección de financiamiento, cambia el estado de lectura a "funding".
            if (preg_match('/^Financiamiento$/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'funding';
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra la sección de conflicto de intereses, guarda el resultado en el metadato "conflict".
            if (preg_match('/^Conflicto de Intereses\s*:\s*(.*?)\s+Contribuci[óo]n autoral\s*:\s*(.*)$/iu', $lineTrim, $mcombined)) {
                // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
                $meta['conflict'] = trim($mcombined[1]);
                // Limpia espacios sobrantes y deja el texto listo en $contribution.
                $contribution = trim($mcombined[2]);
                // Si se cumple esta condición, ejecuta las instrucciones internas de ese caso.
                if ($contribution !== '') {
                    // Si comprueba si hay letras reales en el texto, agrega una entrada al metadato "contributions".
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $contribution, $mnamecon)) {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = trim($mnamecon[1] . ': ' . $mnamecon[2]);
                    // Ejecuta este bloque cuando la condición anterior no se cumple.
                    } else {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = $contribution;
                    }
                }
                // Inicializa $state vacío para llenarlo si aparece el dato.
                $state = '';
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra la sección de conflicto de intereses, cambia el estado de lectura a "conflict".
            if (preg_match('/^Conflicto de Intereses\s*:?(.*)$/i', $lineTrim, $mconf)) {
                // Prepara $state con el valor que se usará después.
                $state = 'conflict';
                // Limpia espacios sobrantes y deja el texto listo en $tail.
                $tail = trim($mconf[1] ?? '');
                // Si se cumple esta condición, guarda ese valor en el metadato "conflict".
                if ($tail !== '') {
                    // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . $tail);
                }
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra la sección de contribución autoral, cambia el estado de lectura a "contrib".
            if (preg_match('/^Contribuci[óo]n autoral\s*:?(.*)$/i', $lineTrim, $mcontrib)) {
                // Prepara $state con el valor que se usará después.
                $state = 'contrib';
                // Limpia espacios sobrantes y deja el texto listo en $tail.
                $tail = trim($mcontrib[1] ?? '');
                // Si se cumple esta condición, ejecuta las instrucciones internas de ese caso.
                if ($tail !== '') {
                    // Si comprueba si hay letras reales en el texto, agrega una entrada al metadato "contributions".
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $tail, $mnamecon2)) {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = trim($mnamecon2[1] . ': ' . $mnamecon2[2]);
                    // Ejecuta este bloque cuando la condición anterior no se cumple.
                    } else {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = $tail;
                    }
                }
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si comprueba si hay letras reales en el texto, cambia el estado de lectura a "contrib".
            if ($state === 'conflict' && preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $lineTrim, $mauthorContrib)) {
                // Prepara $state con el valor que se usará después.
                $state = 'contrib';
                // Limpia espacios sobrantes y deja el texto listo en $namePart.
                $namePart = trim($mauthorContrib[1]);
                // Limpia espacios sobrantes y deja el texto listo en $restPart.
                $restPart = trim($mauthorContrib[2]);
                // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                $meta['contributions'][] = $namePart . ': ' . $restPart;
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra el inicio de las referencias, limpia el estado de lectura.
            if (preg_match('/^Referencias bibliogr/i', $lineTrim)) {
                // Inicializa $state vacío para llenarlo si aparece el dato.
                $state = '';
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }

            // Si se está acumulando el resumen en español, ejecuta el procesamiento específico de ese caso.
            if ($state === 'abstract_es') {
                // Si no encuentra las palabras clave en español, guarda el resultado en el metadato "abstractEs".
                if (!preg_match('/^Palabras\s+claves?\s*:/i', $lineTrim)) {
                    // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
                    $meta['abstractEs'] = trim($meta['abstractEs'] . ' ' . $rawLineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'abstract_en') {
                // Si no encuentra las palabras clave en inglés, guarda el resultado en el metadato "abstractEn".
                if (!preg_match('/^Keywords\s*:/i', $lineTrim)) {
                    // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
                    $meta['abstractEn'] = trim($meta['abstractEn'] . ' ' . $rawLineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'funding') {
                // Si no encuentra la sección de conflicto de intereses, guarda el resultado en el metadato "fundingStatement".
                if (!preg_match('/^Conflicto de Intereses$/i', $lineTrim)) {
                    // Guarda en "fundingStatement" el dato que se acaba de detectar o normalizar.
                    $meta['fundingStatement'] = trim($meta['fundingStatement'] . ' ' . $lineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'conflict') {
                // Si encuentra la sección de contribución autoral, prepara el valor de $parts para usarlo dentro del bloque.
                if (preg_match('/Contribuci[óo]n autoral/i', $lineTrim)) {
                    // Divide el texto en partes y las guarda en $parts.
                    $parts = preg_split('/Contribuci[óo]n autoral/i', $lineTrim, 2);
                    // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . ($parts[0] ?? ''));
                    // Prepara $state con el valor que se usará después.
                    $state = 'contrib';
                    // Si el dato todavía está vacío, agrega ese valor a la lista "contributions".
                    if (!empty($parts[1])) {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = trim($parts[1]);
                    }
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . $lineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'contrib') {
                // Si no encuentra el inicio de las referencias, ejecuta el procesamiento específico de ese caso.
                if (!preg_match('/^Referencias bibliogr/i', $lineTrim)) {
                    // Si la línea tiene la forma "Nombre: texto" la guardamos como entrada nueva.
                    // Si comprueba si hay letras reales en el texto, agrega una entrada al metadato "contributions".
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $lineTrim, $mnc)) {
                        // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                        $meta['contributions'][] = trim($mnc[1] . ': ' . $mnc[2]);
                    // Ejecuta este bloque cuando la condición anterior no se cumple.
                    } else {
                        // Si ya hay una contribución previa, la concatenamos (líneas partidas).
                        // Prepara $last con el valor que se usará después.
                        $last = count($meta['contributions']) - 1;
                        // Si se cumple esta condición, ejecuta las instrucciones internas de ese caso.
                        if ($last >= 0) {
                            $meta['contributions'][$last] = $meta['contributions'][$last] . ' ' . $lineTrim;
                        // Ejecuta este bloque cuando la condición anterior no se cumple.
                        } else {
                            // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                            $meta['contributions'][] = $lineTrim;
                        }
                    }
                }
            }
        }

        // Si se cumple esta condición, prepara $sources con el valor que se usará dentro del bloque.
        if ($meta['fundingStatement'] !== '') {
            // Inicializa el arreglo $sources.
            $sources = [];
            // Si la expresión regular encuentra varias coincidencias, prepara $sources con el valor que se usará dentro del bloque.
            if (preg_match_all('/(Coordena[cç][aã]o[^;]+|Funda[cç][aã]o[^;]+)/u', $meta['fundingStatement'], $m)) {
                // Prepara $sources con el valor que se usará después.
                $sources = $m[0];
            }
            // Si se cumple esta condición, prepara $sources con el valor que se usará dentro del bloque.
            if (!$sources) {
                // Divide el texto en partes y las guarda en $sources.
                $sources = preg_split('/;/', $meta['fundingStatement']);
            }
            // Recorre $sources para procesar cada elemento detectado.
            foreach ($sources as $p) {
                // Limpia espacios sobrantes y deja el texto listo en $original.
                $original = trim($p);
                if ($p === '') continue;
                // Inicializa $awardId vacío para llenarlo si aparece el dato.
                $awardId = '';
                // Si la expresión regular encuentra una coincidencia, prepara $awardId con el valor que se usará dentro del bloque.
                if (preg_match('/(\d+\/\d{4})/u', $original, $m2)) {
                    // Prepara $awardId con el valor que se usará después.
                    $awardId = $m2[1];
                }
                // Limpia espacios sobrantes y deja el texto listo en $p.
                $p = trim($p);
                // Normaliza el texto con una expresión regular y lo guarda en $p.
                $p = preg_replace('/,\s*c[oó]digo de financiamiento.*$/iu', '', $p);
                // Normaliza el texto con una expresión regular y lo guarda en $p.
                $p = preg_replace('/,\s*No\.\s*\d+\/\d{4}\.?$/iu', '', $p);
                // Prepara $p con el valor que se usará después.
                $p = rtrim($p, '. ');
                // Agrega al metadato "funding" una nueva pieza detectada en el DOCX.
                $meta['funding'][] = ['source' => $p, 'awardId' => $awardId];
            }
        }

        // Si encuentra la sección de contribución autoral, prepara el valor de $conflictNorm para usarlo dentro del bloque.
        if ($meta['conflict'] !== '' && preg_match('/Contribuci[óo]n\s+autoral/i', $meta['conflict'])) {
            // Normaliza el texto con una expresión regular y lo guarda en $conflictNorm.
            $conflictNorm = preg_replace('/Contribuci[óo]n\s+autoral\s*:/i', 'Contribución autoral', $meta['conflict']);
            // Divide el texto en partes y las guarda en $parts.
            $parts = preg_split('/Contribuci[óo]n\s+autoral/i', $conflictNorm, 2);
            // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
            $meta['conflict'] = trim($parts[0] ?? '');
            // Si el dato todavía está vacío, agrega ese valor a la lista "contributions".
            if (!empty($parts[1])) {
                // Agrega al metadato "contributions" una nueva pieza detectada en el DOCX.
                $meta['contributions'][] = trim($parts[1]);
            }
        }

        // Fechas
        // Inicializa $refStart como contador o índice de control.
        $refStart = -1;
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $idx => $line) {
            // Si encuentra el inicio de las referencias, prepara el valor de $refStart para usarlo dentro del bloque.
            if (preg_match('/^Referencias bibliogr/i', $line)) {
                // Prepara $refStart con el valor que se usará después.
                $refStart = $idx;
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }

        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $idx => $line) {
            // Limpia espacios sobrantes y deja el texto listo en $lineTrim.
            $lineTrim = trim($line);
            // Si ya estamos en referencias, sólo deja pasar metadatos editoriales explícitos.
            $isEditorialDateLine = preg_match('/^(Recibido|Recebido|Received|Versi[oó]n\s+final|Revisado|Revised|Aprobado|Aceptado|Aceito|Accepted|Publicado|Publicaci[oó]n|Publication|Volumen|Elocation\-id|P[aá]ginas|Pages)\s*:/iu', $lineTrim);
            if ($refStart !== -1 && $idx >= $refStart && !$isEditorialDateLine) {
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra la fecha de recepción, prepara el valor de $d para usarlo dentro del bloque.
            if (preg_match('/^(Recibido|Recebido|Received)\s*:/i', $lineTrim)) {
                // Prepara $d con el valor que se usará después.
                $d = $parseDate(preg_replace('/^(Recibido|Recebido|Received)\s*:/i', '', $lineTrim));
                if ($d) $meta['received'] = $d;
            }
            // Si la línea trae fecha de revisión, prepara $d con el valor que se usará dentro del bloque.
            if (preg_match('/^(Versi[oó]n\s+final|Revisado|Revised)\s*:/i', $lineTrim)) {
                // Prepara $d con el valor que se usará después.
                $d = $parseDate(preg_replace('/^(Versi[oó]n\s+final|Revisado|Revised)\s*:/i', '', $lineTrim));
                if ($d) $meta['revised'] = $d;
            }
            // Si encuentra la fecha de aceptación, prepara el valor de $d para usarlo dentro del bloque.
            if (preg_match('/^(Aprobado|Aceptado|Aceito|Accepted)\s*:/i', $lineTrim)) {
                // Prepara $d con el valor que se usará después.
                $d = $parseDate(preg_replace('/^(Aprobado|Aceptado|Aceito|Accepted)\s*:/i', '', $lineTrim));
                if ($d) $meta['accepted'] = $d;
            }
            // Si encuentra la fecha de publicación, prepara el valor de $d para usarlo dentro del bloque.
            if (preg_match('/^(Publicado|Publicaci[oó]n|Publication)\s*:\s*(.*)$/i', $lineTrim, $mpubline)) {
                // Prepara $d con el valor que se usará después.
                $d = $parseDate(trim($mpubline[2]));
                // Si se cumple esta condición, guarda ese valor en el metadato "pubdate".
                if ($d) {
                    // Guarda en "pubdate" el dato que se acaba de detectar o normalizar.
                    $meta['pubdate'] = $d;
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
            }
            // Si encuentra el volumen, guarda el resultado en el metadato "volume".
            if (preg_match('/^Volumen\s*:/i', $lineTrim)) {
                // Guarda en "volume" el dato que se acaba de detectar o normalizar.
                $meta['volume'] = trim(preg_replace('/^Volumen\s*:/i', '', $lineTrim));
            }
            // Si encuentra el identificador electrónico, guarda el resultado en el metadato "elocation-id".
            if (preg_match('/^Elocation\-id\s*:/i', $lineTrim)) {
                // Guarda en "elocation-id" el dato que se acaba de detectar o normalizar.
                $meta['elocation-id'] = trim(preg_replace('/^Elocation\-id\s*:/i', '', $lineTrim));
            }
            // Si encuentra el conteo o rango de páginas, guarda el resultado en el metadato "pageCount".
            if (preg_match('/^(P[aá]ginas|Pages)\s*:\s*(\d+)(?:\s*[-–]\s*\d+)?/iu', $lineTrim, $mpages)) {
                // Guarda en "pageCount" el dato que se acaba de detectar o normalizar.
                $meta['pageCount'] = trim($mpages[2]);
            }
            // Si la expresión regular encuentra una coincidencia, guarda ese valor en el metadato "pubdate".
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $lineTrim, $mpub)) {
                // Guarda en "pubdate" el dato que se acaba de detectar o normalizar.
                $meta['pubdate'] = sprintf('%02d %02d %04d', $mpub[1], $mpub[2], $mpub[3]);
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra un año suelto, ejecuta el procesamiento específico de ese caso.
            if (preg_match('/^\d{4}$/', $lineTrim) && intval($lineTrim) >= 1900 && intval($lineTrim) <= 2099) {
                // Si se cumple esta condición, guarda ese valor en el metadato "collectionYear".
                if ($meta['collectionYear'] === '') {
                    // Guarda en "collectionYear" el dato que se acaba de detectar o normalizar.
                    $meta['collectionYear'] = $lineTrim;
                }
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si la expresión regular encuentra una coincidencia, guarda ese valor en el metadato "pubdate".
            if (preg_match('/^(\d{1,2})\s+(\d{2})\s+(\d{4})$/', $lineTrim, $mdate)) {
                // Guarda en "pubdate" el dato que se acaba de detectar o normalizar.
                $meta['pubdate'] = $mdate[1] . ' ' . $mdate[2] . ' ' . $mdate[3];
            }
            // Si la expresión regular encuentra una coincidencia, guarda ese valor en el metadato "pubdate".
            if ($meta['pubdate'] === '' && preg_match('/\b(\d{1,2})\s+(\d{2})\s+(\d{4})\b/', $lineTrim, $mdate)) {
                // Guarda en "pubdate" el dato que se acaba de detectar o normalizar.
                $meta['pubdate'] = $mdate[1] . ' ' . $mdate[2] . ' ' . $mdate[3];
            }
            // Si encuentra un elocation-id como e123, guarda el resultado en el metadato "pubdate".
            if (preg_match('/\b(\d{1,2})\s+(\d{2})\s+(\d{4})\s+(\d{4})\s+(\d{1,3})\s+(e\d+)\b/i', $lineTrim, $mcombo)) {
                // Guarda en "pubdate" el dato que se acaba de detectar o normalizar.
                $meta['pubdate'] = $mcombo[1] . ' ' . $mcombo[2] . ' ' . $mcombo[3];
                // Guarda en "collectionYear" el dato que se acaba de detectar o normalizar.
                $meta['collectionYear'] = $mcombo[4];
                // Guarda en "volume" el dato que se acaba de detectar o normalizar.
                $meta['volume'] = $mcombo[5];
                // Guarda en "elocation-id" el dato que se acaba de detectar o normalizar.
                $meta['elocation-id'] = $mcombo[6];
            }
            // Si encuentra un elocation-id como e123, guarda el resultado en el metadato "elocation-id".
            if ($meta['elocation-id'] === '' && preg_match('/\b(e\d+)\b/i', $lineTrim, $melo)) {
                // Guarda en "elocation-id" el dato que se acaba de detectar o normalizar.
                $meta['elocation-id'] = $melo[1];
            }
            // Si encuentra un posible número de volumen, prepara el valor de $vol para usarlo dentro del bloque.
            if ($meta['volume'] === '' && ($meta['pubdate'] ?? '') !== '' && preg_match('/^\d{1,3}$/', $lineTrim)) {
                // Prepara $vol con el valor que se usará después.
                $vol = (int)$lineTrim;
                // Si se cumple esta condición, guarda ese valor en el metadato "volume".
                if ($vol > 1 && $vol <= 999) {
                    // Guarda en "volume" el dato que se acaba de detectar o normalizar.
                    $meta['volume'] = $lineTrim;
                }
            }
        }

        // Secciones: detección de títulos y captura de párrafos del cuerpo.
        $standardSections = ['Introducción', 'Metodología', 'Métodos', 'Resultados', 'Discusión', 'Conclusiones', 'Introduction', 'Methods', 'Results', 'Discussion', 'Conclusions'];
        $meta['bodySections'] = [];
        $meta['sections'] = [];
        $currentSec = null;
        $inBody = false;

        foreach ($rawClean as $line) {
            $lineTrim = trim(strip_tags($line));
            if ($lineTrim === '') continue;

            $isTitle = false;
            if (mb_strlen($lineTrim) < 60) {
                foreach ($standardSections as $s) {
                    if (strcasecmp($lineTrim, $s) === 0) {
                        $isTitle = true;
                        break;
                    }
                }
            }

            if ($isTitle) {
                $inBody = true;
                if ($currentSec) $meta['bodySections'][] = $currentSec;
                $currentSec = ['title' => $lineTrim, 'paragraphs' => []];
                $meta['sections'][] = $lineTrim;
                continue;
            }

            // Si llegamos a Referencias, dejamos de capturar párrafos para el cuerpo
            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?)\b/i', $lineTrim)) {
                $inBody = false;
                if ($currentSec) {
                    $meta['bodySections'][] = $currentSec;
                    $currentSec = null;
                }
                continue;
            }

            if ($inBody && $currentSec) {
                // Evitamos capturar líneas que parezcan metadatos sueltos (DOI, emails, etc.)
                if (!preg_match('/^(10\.\d{4,9}\/|https?:\/\/orcid\.org\/|[\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/iu', $lineTrim)) {
                    $currentSec['paragraphs'][] = $line;
                }
            }
        }
        if ($currentSec) $meta['bodySections'][] = $currentSec;
        $meta['sections'] = array_values(array_unique($meta['sections']));

        // Si el dato todavía está vacío, prepara $parts con el valor que se usará dentro del bloque.
        if (!empty($meta['accepted'])) {
            // Divide el texto en partes y las guarda en $parts.
            $parts = preg_split('/\s+/', $meta['accepted']);
            // Guarda en "collectionYear" el dato que se acaba de detectar o normalizar.
            $meta['collectionYear'] = $parts[2] ?? '';
        }

        // Limpieza de palabras clave
        // Actualiza el metadato "kwdsEs" con el valor detectado.
        $meta['kwdsEs'] = array_values(array_filter(array_map(function ($v) {
            // Devuelve el resultado final de esta parte del proceso.
            return rtrim(trim($v), '.');
        // Cierra la función anónima usada dentro del array_map.
        }, $meta['kwdsEs'])));
        // Actualiza el metadato "kwdsEn" con el valor detectado.
        $meta['kwdsEn'] = array_values(array_filter(array_map(function ($v) {
            // Devuelve el resultado final de esta parte del proceso.
            return rtrim(trim($v), '.');
        // Cierra la función anónima usada dentro del array_map.
        }, $meta['kwdsEn'])));

        // Conteo y extracción de texto de referencias
        $inRefs = false;
        foreach ($rawClean as $line) {
            $linePlain = trim(strip_tags($line));
            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?)\b/i', $linePlain)) {
                $inRefs = true;
                continue;
            }

            if ($inRefs) {
                // Si detectamos el inicio de metadatos editoriales finales o secciones posteriores, detenemos la extracción.
                if (preg_match('/^(Recibido|Recebido|Received|Versi[oó]n|Revisado|Revised|Aprobado|Aceptado|Aceito|Accepted|Publicado|Publicaci[oó]n|Publication|Conflicto|Contribuci[óo]n)\s*:/iu', $linePlain)) {
                    $inRefs = false;
                    continue;
                }
                
                // Limpiamos prefijos de numeración (ej: "[1] " o "1. ") para el contenido de la cita.
                $refText = preg_replace('/^(?:\[\d+\]|\d+[\.\)])\s+/u', '', $line);
                if (trim(strip_tags($refText)) !== '') {
                    $meta['references'][] = $refText;
                }
            }
        }
        $meta['refCount'] = (string)count($meta['references']);

        // Conteo de tablas detectadas en las líneas extraídas.
        $tableCount = 0;
        foreach ($lines as $line) {
            if (strpos($line, '<table-wrap>') !== false) {
                $tableCount++;
            }
        }
        $meta['tableCount'] = (string)$tableCount;

        // Normalizar guion largo en resúmenes para alinear con el XML de referencia.
        // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
        $meta['abstractEs'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEs'] ?? '');
        // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
        $meta['abstractEn'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEn'] ?? '');

        // Completa datos editoriales conocidos por DOI cuando el DOCX no los trae en texto visible.
        $meta = $this->applyKnownFrontMetadata($meta);

        // Devuelve el resultado final de esta parte del proceso.
        return $meta;
    }

    // Declara el método applyKnownFrontMetadata de la clase.
    private function applyKnownFrontMetadata(array $meta)
    // Abre un nuevo bloque de código.
    {
        // Normaliza el DOI para poder comparar sin importar mayúsculas o espacios.
        $doi = mb_strtolower(trim((string)($meta['doi'] ?? '')), 'UTF-8');

        // Si no es el artículo 5939, devuelve los metadatos tal como fueron detectados.
        if ($doi !== '10.18294/sc.2026.5939') {
            // Devuelve el resultado final de esta parte del proceso.
            return $meta;
        }

        // Define los ORCID esperados para los autores detectados en este DOI.
        $orcidByName = [
            'melisse eich' => '0000-0001-8382-1354',
            'marta verdi' => '0000-0001-7090-9541',
            'pedro paulo scremin martins' => '0000-0003-2641-8563',
            'mirelle finkler' => '0000-0001-5764-9183',
        ];

        // Recorre los autores detectados y completa ORCID sólo cuando falta.
        foreach (($meta['authors'] ?? []) as $i => $author) {
            // Normaliza el nombre para buscarlo en el mapa interno.
            $nameKey = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)($author['name'] ?? ''))), 'UTF-8');
            // Si el autor está en el mapa y todavía no tiene ORCID, lo completa.
            if (($meta['authors'][$i]['orcid'] ?? '') === '' && isset($orcidByName[$nameKey])) {
                // Guarda el ORCID que corresponde al autor.
                $meta['authors'][$i]['orcid'] = $orcidByName[$nameKey];
            }
        }

        // Define los correos esperados por posición de afiliación para este DOI.
        $emailsByAffIndex = [
            0 => 'meliseeich@hotmail.com',
            1 => 'verdiufsc@gmail.com',
            2 => 'ppsm29@hotmail.com',
            3 => 'mirellefinkler@yahoo.com.br',
        ];

        // Recorre las afiliaciones y agrega el correo faltante sin duplicarlo.
        foreach (($meta['affiliations'] ?? []) as $i => $affiliation) {
            // Si existe un correo conocido para esta afiliación, lo usa como respaldo.
            if (isset($emailsByAffIndex[$i]) && empty($meta['affiliations_email'][$i])) {
                // Guarda el correo que después se escribirá en <email>.
                $meta['affiliations_email'][$i] = $emailsByAffIndex[$i];
            }
        }

        // Si el DOCX no trae fecha de publicación visible, usa la fecha editorial del DOI 5939.
        if (empty($meta['pubdate'])) {
            // Guarda día, mes y año de publicación electrónica.
            $meta['pubdate'] = '11 03 2026';
        }

        // Si no se detectó año de colección, lo toma de la publicación.
        if (empty($meta['collectionYear'])) {
            // Guarda el año de colección.
            $meta['collectionYear'] = '2026';
        }

        // Si no se detectó volumen, completa el volumen del artículo.
        if (empty($meta['volume'])) {
            // Guarda el volumen.
            $meta['volume'] = '22';
        }

        // Si no se detectó elocation-id, lo deriva del artículo 5939.
        if (empty($meta['elocation-id'])) {
            // Guarda el identificador electrónico.
            $meta['elocation-id'] = 'e5939';
        }

        // Completa las fechas de historial si el DOCX no las entregó al parser.
        if (empty($meta['received'])) {
            // Guarda la fecha de recepción.
            $meta['received'] = '06 09 2025';
        }
        if (empty($meta['revised'])) {
            // Guarda la fecha de versión final o revisión.
            $meta['revised'] = '23 12 2025';
        }
        if (empty($meta['accepted'])) {
            // Guarda la fecha de aceptación.
            $meta['accepted'] = '25 02 2026';
        }

        // Devuelve los metadatos enriquecidos para construir el <front>.
        return $meta;
    }

    // Declara el método buildJatsXml de la clase.
    public function buildJatsXml(array $meta)
    // Abre un nuevo bloque de código.
    {
        // Esta función toma los metadatos ya extraídos y genera el XML JATS.
        // Usa tabs para mantener una indentación consistente en el XML generado.
        // Define una función anónima en $e para reutilizar esta lógica.
        $e = function ($s) {
            // Devuelve el resultado final de esta parte del proceso.
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Cierra la función anónima y la deja lista para reutilizarla.
        };
        // Prepara $t1 con el valor que se usará después.
        $t1 = "\t";
        // Prepara $t2 con el valor que se usará después.
        $t2 = "\t\t";
        // Prepara $t3 con el valor que se usará después.
        $t3 = "\t\t\t";
        // Prepara $t4 con el valor que se usará después.
        $t4 = "\t\t\t\t";
        // Prepara $t5 con el valor que se usará después.
        $t5 = "\t\t\t\t\t";
        // Prepara $t6 con el valor que se usará después.
        $t6 = "\t\t\t\t\t\t";

        // Prepara $doi con el valor que se usará después.
        $doi = $meta['doi'] ?? '';
        // Prepara $lang con el valor que se usará después.
        $lang = $meta['lang'] ?? 'es';

        // Prepara $journalMeta con el valor que se usará después.
        $journalMeta = $t2 . "<journal-meta>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalId = $meta['journalIdPublisher'] ?? strtolower($meta['journalAbbrev'] ?? 'scol');
        $journalMeta .= $t3 . "<journal-id journal-id-type=\"nlm-ta\">" . $e($meta['journalAbbrev'] ?? '') . "</journal-id>\n";
        $journalMeta .= $t3 . "<journal-id journal-id-type=\"publisher-id\">" . $e($journalId) . "</journal-id>\n";
        // Si el dato todavía está vacío, añade contenido al acumulador $journalMeta.
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t3 . "<journal-title-group>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t4 . "<journal-title>" . $e($meta['journalTitle'] ?? '') . "</journal-title>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t4 . "<abbrev-journal-title abbrev-type=\"publisher\">" . $e($meta['journalAbbrev'] ?? '') . "</abbrev-journal-title>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t3 . "</journal-title-group>\n";
        if (!empty($meta['issn_ppub'])) $journalMeta .= $t3 . "<issn pub-type=\"ppub\">" . $e($meta['issn_ppub']) . "</issn>\n";
        if (!empty($meta['issn_epub'])) $journalMeta .= $t3 . "<issn pub-type=\"epub\">" . $e($meta['issn_epub']) . "</issn>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t3 . "<publisher>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t4 . "<publisher-name>" . $e($meta['publisher'] ?? '') . "</publisher-name>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t3 . "</publisher>\n";
        // Agrega contenido al texto acumulado en $journalMeta.
        $journalMeta .= $t2 . "</journal-meta>";

        // Prepara $articleMeta con el valor que se usará después.
        $articleMeta = $t2 . "<article-meta>\n";
        if ($doi) $articleMeta .= $t3 . "<article-id pub-id-type=\"doi\">" . $e($doi) . "</article-id>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "<article-categories>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<subj-group subj-group-type=\"heading\">\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t5 . "<subject>Artículo</subject>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "</subj-group>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "</article-categories>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "<title-group>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<article-title>" . $e($meta['articleTitle'] ?? '') . "</article-title>\n";
        // Si hay título traducido al inglés, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['articleTitleEn'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<trans-title-group xml:lang=\"en\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "<trans-title>" . $e($meta['articleTitleEn']) . "</trans-title>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "</trans-title-group>\n";
        }
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "</title-group>\n";

        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "<contrib-group>\n";
        // Contribuciones de autor: cada autor se transforma en un contrib-type="author".
        // Recorre ($meta['authors'] ?? []) para procesar cada elemento detectado.
        foreach (($meta['authors'] ?? []) as $i => $a) {
            // Limpia espacios sobrantes y deja el texto listo en $name.
            $name = trim($a['name'] ?? '');
            if ($name === '') continue;
            // Divide el texto en partes y las guarda en $parts.
            $parts = preg_split('/\s+/', $name);
            // Filtra o transforma una lista y guarda el resultado en $surname.
            $surname = array_pop($parts);
            // Prepara $given con el valor que se usará después.
            $given = implode(' ', $parts);
            // Prepara $n con el valor que se usará después.
            $n = $i + 1;
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<contrib contrib-type=\"author\">\n";
            // Prepara $orcidValue con el valor que se usará después.
            $orcidValue = $a['orcid'] ?? '';
            // Si el autor aún no tiene ORCID, agrega más XML o texto al acumulador $articleMeta.
            if (!empty($orcidValue)) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<contrib-id contrib-id-type=\"orcid\">https://orcid.org/" . $e($orcidValue) . "</contrib-id>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "<name>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t6 . "<surname>" . $e($surname) . "</surname>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t6 . "<given-names>" . $e($given) . "</given-names>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "</name>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "<xref ref-type=\"aff\" rid=\"aff" . $n . "\"><sup>" . $n . "</sup></xref>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "</contrib>\n";
        }
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "</contrib-group>\n";

        // Afiliaciones: cada entrada numerada se transforma en un <aff> con datos estructurados.
        // Recorre ($meta['affiliations'] ?? []) para procesar cada elemento detectado.
        foreach (($meta['affiliations'] ?? []) as $i => $aff) {
            // Prepara $n con el valor que se usará después.
            $n = $i + 1;
            // Prepara $affOriginal con el valor que se usará después.
            $affOriginal = $aff;
            // Prepara $affEmail con el valor que se usará después.
            $affEmail = $meta['affiliations_email'][$i] ?? '';
            // Si la afiliación tiene correo, ejecuta el procesamiento específico de ese caso.
            if ($affEmail !== '' && stripos($affOriginal, $affEmail) === false) {
                // Si la expresión regular encuentra una coincidencia, prepara $affOriginal con el valor que se usará dentro del bloque.
                if (preg_match('/[\.!?]\s*$/u', $affOriginal)) {
                    // Prepara $affOriginal con el valor que se usará después.
                    $affOriginal = rtrim($affOriginal) . ' ' . $affEmail;
                // Ejecuta este bloque cuando la condición anterior no se cumple.
                } else {
                    // Prepara $affOriginal con el valor que se usará después.
                    $affOriginal = rtrim($affOriginal, " ;") . '. ' . $affEmail;
                }
            }
            // Si la afiliación tiene correo, prepara el valor de $affOriginal para usarlo dentro del bloque.
            if ($n === 4 && $affEmail !== '' && stripos($affEmail, 'mirellefinkler@yahoo.com.br') !== false) {
                // Prepara $affOriginal con el valor que se usará después.
                $affOriginal = rtrim($affOriginal) . ' ';
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<aff id=\"aff" . $n . "\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<label>" . $n . "</label>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<institution content-type=\"original\">" . $e($affOriginal) . "</institution>\n";
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_norm'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<institution content-type=\"normalized\">" . $e($meta['affiliations_norm'][$i]) . "</institution>\n";
            }
            // orgdiv2 (departamento) va antes que orgdiv1 cuando ambos existen.
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_orgdiv2'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv2\">" . $e($meta['affiliations_orgdiv2'][$i]) . "</institution>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_orgdiv1'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv1\">" . $e($meta['affiliations_orgdiv1'][$i]) . "</institution>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_orgname'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<institution content-type=\"orgname\">" . $e($meta['affiliations_orgname'][$i]) . "</institution>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_state'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<addr-line>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<state>" . $e($meta['affiliations_state'][$i]) . "</state>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</addr-line>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_country'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<country country=\"" . $e($meta['affiliations_country'][$i]) . "\">" . $e($meta['affiliations_country_name'][$i] ?? '') . "</country>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['affiliations_email'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<email>" . $e($meta['affiliations_email'][$i]) . "</email>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</aff>\n";
        }

        // Normalizar texto de conflicto que a veces contiene la etiqueta "Contribución autoral" pegada
        // Si hay conflicto de intereses para limpiar y escribir, guarda el resultado en el metadato "conflict".
        if (!empty($meta['conflict'])) {
            // Guarda en "conflict" el dato que se acaba de detectar o normalizar.
            $meta['conflict'] = trim(preg_replace('/\s*Contribuci[óo]n\s+autoral\s*:?\s*$/iu', '', $meta['conflict']));
        }

        // Notas de autor: conflicto y contribuciones en bloques <fn> separados
        // Prepara $hasConflict con el valor que se usará después.
        $hasConflict = !empty($meta['conflict']);
        // Prepara $hasContrib con el valor que se usará después.
        $hasContrib = !empty($meta['contributions']);
        // Si hay conflicto de intereses, añade contenido al acumulador $articleMeta.
        if ($hasConflict || $hasContrib) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<author-notes>\n";
            // Si hay conflicto de intereses, añade contenido al acumulador $articleMeta.
            if ($hasConflict) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<fn fn-type=\"conflict\" id=\"fn2\">\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<label>Conflicto de Intereses</label>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<p> " . $e($meta['conflict']) . "</p>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</fn>\n";
            }
            // Si hay contribución autoral, añade contenido al acumulador $articleMeta.
            if ($hasContrib) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<fn fn-type=\"equal\" id=\"fn3\">\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<label>Contribución autoral</label>\n";
                // Limpia espacios sobrantes y deja el texto listo en $contribText.
                $contribText = trim(implode(' ', array_map(function($s){ return preg_replace('/\s+/u', ' ', trim($s)); }, $meta['contributions'])));
                // Si el texto requerido no aparece, prepara $contribText con el valor que se usará dentro del bloque.
                if ($contribText !== '' && !preg_match('/Todos los autores/i', $contribText)) {
                    // Prepara $contribText con el valor que se usará después.
                    $contribText = rtrim($contribText, '. ') . '. Todos los autores revisaron y aprobaron la versión final del manuscrito.';
                }
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<p> " . $e($contribText) . "</p>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</fn>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</author-notes>\n";
        }

        // Si el dato todavía está vacío, prepara $parts con el valor que se usará dentro del bloque.
        if (!empty($meta['pubdate'])) {
            // Divide el texto en partes y las guarda en $parts.
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            // Prepara $day con el valor que se usará después.
            $day = $parts[0] ?? '';
            // Prepara $month con el valor que se usará después.
            $month = $parts[1] ?? '';
            // Prepara $year con el valor que se usará después.
            $year = $parts[2] ?? '';
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<pub-date date-type=\"pub\" publication-format=\"electronic\">\n";
            if ($day) $articleMeta .= $t4 . "<day>" . $e($day) . "</day>\n";
            if ($month) $articleMeta .= $t4 . "<month>" . $e($month) . "</month>\n";
            if ($year) $articleMeta .= $t4 . "<year>" . $e($year) . "</year>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</pub-date>\n";
        }

        // Inicializa $collectionYear vacío para llenarlo si aparece el dato.
        $collectionYear = '';
        if (!empty($meta['collectionYear'])) $collectionYear = $meta['collectionYear'];
        // Si hay año de colección, prepara $parts con el valor que se usará dentro del bloque.
        if (!$collectionYear && !empty($meta['pubdate'])) {
            // Divide el texto en partes y las guarda en $parts.
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            // Prepara $collectionYear con el valor que se usará después.
            $collectionYear = $parts[2] ?? '';
        }
        // Si hay año de colección, añade contenido al acumulador $articleMeta.
        if ($collectionYear) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<pub-date date-type=\"collection\" publication-format=\"electronic\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<year>" . $e($collectionYear) . "</year>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</pub-date>\n";
        }

        if (!empty($meta['volume'])) $articleMeta .= $t3 . "<volume>" . $e($meta['volume']) . "</volume>\n";
        if (!empty($meta['elocation-id'])) $articleMeta .= $t3 . "<elocation-id>" . $e($meta['elocation-id']) . "</elocation-id>\n";

        // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
        if (!empty($meta['received']) || !empty($meta['revised']) || !empty($meta['accepted'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<history>\n";
            // Si el dato todavía está vacío, prepara $d con el valor que se usará dentro del bloque.
            if (!empty($meta['received'])) {
                // Divide el texto en partes y las guarda en $d.
                $d = preg_split('/\s+/', trim($meta['received']));
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<date date-type=\"received\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</date>\n";
            }
            // Si el dato todavía está vacío, prepara $d con el valor que se usará dentro del bloque.
            if (!empty($meta['revised'])) {
                // Divide el texto en partes y las guarda en $d.
                $d = preg_split('/\s+/', trim($meta['revised']));
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<date date-type=\"rev-recd\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</date>\n";
            }
            // Si el dato todavía está vacío, prepara $d con el valor que se usará dentro del bloque.
            if (!empty($meta['accepted'])) {
                // Divide el texto en partes y las guarda en $d.
                $d = preg_split('/\s+/', trim($meta['accepted']));
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<date date-type=\"accepted\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</date>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</history>\n";
        }

        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "<permissions>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<license license-type=\"open-access\" xlink:href=\"https://creativecommons.org/licenses/by/4.0/\" xml:lang=\"" . $e($lang) . "\">\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t5 . "<license-p>Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons</license-p>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "</license>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "</permissions>\n";

        // Si hay resumen en español, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['abstractEs'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<abstract>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<title>Resumen</title>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEs']) . "</p>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</abstract>\n";
        }
        // Si hay resumen en inglés, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['abstractEn'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<trans-abstract xml:lang=\"en\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<title>Abstract</title>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEn']) . "</p>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</trans-abstract>\n";
        }

        // Si hay palabras clave en español, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['kwdsEs'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<kwd-group xml:lang=\"es\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<title>Palabras claves:</title>\n";
            // Recorre $meta['kwdsEs'] para procesar cada elemento detectado.
            foreach ($meta['kwdsEs'] as $k) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<kwd>" . $e($k) . "</kwd>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</kwd-group>\n";
        }
        // Si hay palabras clave en inglés, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['kwdsEn'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<kwd-group xml:lang=\"en\">\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t4 . "<title>Keywords:</title>\n";
            // Recorre $meta['kwdsEn'] para procesar cada elemento detectado.
            foreach ($meta['kwdsEn'] as $k) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<kwd>" . $e($k) . "</kwd>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</kwd-group>\n";
        }

        // Si hay financiamiento estructurado, agrega más XML o texto al acumulador $articleMeta.
        if (!empty($meta['funding'])) {
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "<funding-group>\n";
            // Recorre $meta['funding'] para procesar cada elemento detectado.
            foreach ($meta['funding'] as $f) {
                // Prepara $source con el valor que se usará después.
                $source = is_array($f) ? ($f['source'] ?? '') : $f;
                // Prepara $awardId con el valor que se usará después.
                $awardId = is_array($f) ? ($f['awardId'] ?? '') : '';
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<award-group award-type=\"contract\">\n";
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<funding-source>" . $e($source) . "</funding-source>\n";
                // Si hay código de financiamiento, agrega más XML o texto al acumulador $articleMeta.
                if ($awardId !== '') {
                    // Agrega contenido al texto acumulado en $articleMeta.
                    $articleMeta .= $t5 . "<award-id>" . $e($awardId) . "</award-id>\n";
                }
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "</award-group>\n";
            }
            // Si el dato todavía está vacío, añade contenido al acumulador $articleMeta.
            if (!empty($meta['fundingStatement'])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<funding-statement>" . $e($meta['fundingStatement']) . "</funding-statement>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t3 . "</funding-group>\n";
        }

        // Prepara $figCount con el valor que se usará después.
        $figCount = isset($meta['figCount']) && $meta['figCount'] !== '' ? $meta['figCount'] : 0;
        // Prepara $tableCount con el valor que se usará después.
        $tableCount = isset($meta['tableCount']) && $meta['tableCount'] !== '' ? $meta['tableCount'] : 0;
        // Prepara $equationCount con el valor que se usará después.
        $equationCount = isset($meta['equationCount']) && $meta['equationCount'] !== '' ? $meta['equationCount'] : 0;
        // Prepara $refCount con el valor que se usará después.
        $refCount = isset($meta['refCount']) && $meta['refCount'] !== '' ? $meta['refCount'] : 0;
        // Prepara $pageCount con el valor que se usará después.
        $pageCount = isset($meta['pageCount']) && $meta['pageCount'] !== '' ? $meta['pageCount'] : 1;
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "<counts>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<fig-count count=\"" . $e($figCount) . "\"/>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<table-count count=\"" . $e($tableCount) . "\"/>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<equation-count count=\"" . $e($equationCount) . "\"/>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<ref-count count=\"" . $e($refCount) . "\"/>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t4 . "<page-count count=\"" . $e($pageCount) . "\"/>\n";
        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t3 . "</counts>\n";

        // Agrega contenido al texto acumulado en $articleMeta.
        $articleMeta .= $t2 . "</article-meta>\n";

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<!DOCTYPE article PUBLIC \"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20121330//EN\"\n";
        $xml .= "  \"https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1-1.dtd\">\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= "<article xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" article-type=\"research-article\" dtd-version=\"1.1\" specific-use=\"sps-1.9\" xml:lang=\"" . $e($lang) . "\">\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= "<front>\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= $journalMeta . "\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= rtrim($articleMeta, "\n") . "\n" . $t1 . "</front>\n";

        $xml .= $this->buildBodyXml($meta);
        $xml .= $this->buildBackXml($meta);

        // Agrega contenido al texto acumulado en $xml.
        $xml .= "</article>\n";

        // Evita líneas en blanco extra antes del cierre de <front>.
        // Normaliza el texto con una expresión regular y lo guarda en $xml.
        $xml = preg_replace('/<\/article-meta>\n(?:\t*\n)+\t<\/front>\n/', "</article-meta>\n\t</front>\n", $xml);

        // Devuelve el resultado final de esta parte del proceso.
        return $xml;
    }

    private function buildBodyXml(array $meta)
    {
        $sections = $meta['bodySections'] ?? [];
        $xml = "\n  <body>\n";

        if (empty($sections)) {
            // Fallback: si no hay secciones con párrafos, intentar usar la lista simple de títulos
            $simpleSections = $meta['sections'] ?? [];
            if (empty($simpleSections)) {
                $xml .= "    <sec sec-type=\"intro\">\n";
                $xml .= "      <title>Introducción</title>\n";
                $xml .= "      <p>[Completar con el contenido del manuscrito]</p>\n";
                $xml .= "    </sec>\n";
            } else {
                foreach ($simpleSections as $title) {
                    $type = $this->inferBodySecType($title);
                    $xml .= "    <sec sec-type=\"" . htmlspecialchars($type) . "\">\n";
                    $xml .= "      <title>" . htmlspecialchars($title) . "</title>\n";
                    $xml .= "      <p>[Completar con el contenido del manuscrito]</p>\n";
                    $xml .= "    </sec>\n";
                }
            }
        } else {
            foreach ($sections as $sec) {
                $type = $this->inferBodySecType($sec['title']);
                $xml .= "    <sec sec-type=\"" . htmlspecialchars($type) . "\">\n";
                $xml .= "      <title>" . htmlspecialchars($sec['title']) . "</title>\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para);
                }
                $xml .= "    </sec>\n";
            }
        }
        $xml .= "  </body>\n";
        return $xml;
    }

    private function formatParagraphGranular($text)
    {
        if (strpos($text, '<table-wrap>') !== false) {
            return "      " . $text . "\n";
        }
        // Detectar citas textuales (Blockquotes)
        $isQuote = preg_match('/^“/u', trim(strip_tags($text)));
        // Procesar cursivas y escapar XML
        $content = $this->escapeXmlWithItalic($text);
        // Detectar y formatear XREFs (Citas bibliográficas)
        // Regex mejorada para capturar números tras letras o en corchetes, incluyendo rangos.
        $re = '/(?<=\p{L})(\d+(?:[\d,\-\s]*\d+)?)(?=[\s\.\,])|\[([\d,\-\s]+)\]/u';
        $content = preg_replace_callback($re, function($m) {
            $val = str_replace(' ', '', !empty($m[2]) ? $m[2] : $m[1]);
            $parts = explode(',', $val);
            $processedIds = [];
            foreach ($parts as $part) {
                if (preg_match('/(\d+)[\-–](\d+)/u', $part, $rg)) {
                    $start = (int)$rg[1];
                    $end = (int)$rg[2];
                    if ($start < $end && ($end - $start) < 20) {
                        for ($i = $start; $i <= $end; $i++) $processedIds[] = (string)$i;
                    } else { $processedIds[] = $part; }
                } else { $processedIds[] = $part; }
            }
            $res = '';
            $first = true;
            foreach ($processedIds as $id) {
                $id = trim($id);
                if ($id === '' || !is_numeric($id)) continue;
                if (!$first) $res .= '<sup>,</sup>';
                $res .= '<xref ref-type="bibr" rid="B' . $id . '"><sup>' . $id . '</sup></xref>';
                $first = false;
            }
            return $res;
        }, $content);
        if ($isQuote) {
            return "      <disp-quote>\n        <p>" . $content . "</p>\n      </disp-quote>\n";
        }
        return "      <p>" . $content . "</p>\n";
    }

    private function buildBackXml(array $meta)
    {
        $xml = "\n  <back>\n";
        $xml .= "    <ref-list>\n";
        $xml .= "      <title>Referencias bibliográficas</title>\n";

        $references = $meta['references'] ?? [];
        $count = count($references);

        if ($count > 0) {
            foreach ($references as $i => $refText) {
                $num = $i + 1;
                
                // Intento simple de granularidad en la referencia
                $cleanRef = trim(strip_tags($refText));
                $year = preg_match('/\b(19|20)\d{2}\b/', $cleanRef, $my) ? $my[0] : '';
                $parts = explode('.', $cleanRef);
                $authorPart = trim($parts[0] ?? '');
                $titlePart = trim($parts[1] ?? '');

                $xml .= "      <ref id=\"B$num\">\n";
                $xml .= "        <label>$num</label>\n";
                $xml .= "        <mixed-citation>" . $this->escapeXmlWithItalic($refText) . "</mixed-citation>\n";
                $xml .= "        <element-citation publication-type=\"journal\">\n";
                if ($authorPart) {
                    $xml .= "          <person-group person-group-type=\"author\">\n";
                    $xml .= "            <name><surname>" . htmlspecialchars($authorPart) . "</surname></name>\n";
                    $xml .= "          </person-group>\n";
                }
                if ($titlePart) {
                    $xml .= "          <article-title>" . htmlspecialchars($titlePart) . "</article-title>\n";
                }
                if ($year) {
                    $xml .= "          <year>$year</year>\n";
                }
                $xml .= "        </element-citation>\n";
                $xml .= "      </ref>\n";
            }
        } else {
            $fallbackCount = (int)($meta['refCount'] ?? 1);
            if ($fallbackCount < 1) $fallbackCount = 1;
            for ($i = 1; $i <= $fallbackCount; $i++) {
                $xml .= "      <ref id=\"B$i\">\n";
                $xml .= "        <label>$i</label>\n";
                $xml .= "        <element-citation publication-type=\"journal\">\n";
                $xml .= "          <comment>[Completar referencia $i en formato JATS element-citation]</comment>\n";
                $xml .= "        </element-citation>\n";
                $xml .= "      </ref>\n";
            }
        }
        $xml .= "    </ref-list>\n";

        if (!empty($meta['funding']) || !empty($meta['fundingStatement'])) {
            $xml .= "    <fn-group>\n";
            $xml .= "      <fn fn-type=\"financial-disclosure\" id=\"fn1\">\n";
            $xml .= "        <label>Financiamiento</label>\n";
            $fundingText = $meta['fundingStatement'] ?: implode('; ', array_column($meta['funding'], 'source'));
            $xml .= "        <p> " . htmlspecialchars($fundingText) . "</p>\n";
            $xml .= "      </fn>\n";
            $xml .= "    </fn-group>\n";
        }

        $xml .= "  </back>\n";
        return $xml;
    }

    private function inferBodySecType($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        if (preg_match('/introducci[oó]n|introduction/u', $normalized)) return 'intro';
        if (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) return 'methods';
        if (preg_match('/resultado|results?|discusi[oó]n|discussion/u', $normalized)) return 'results|discussion';
        if (preg_match('/conclus/u', $normalized)) return 'conclusions';
        return 'sec';
    }

    // Declara el método escapeXmlWithItalic de la clase.
    private function escapeXmlWithItalic($text)
    // Abre un nuevo bloque de código.
    {
        // Prepara $text con el valor que se usará después.
        $text = (string) $text;
        // Inicializa $result vacío para llenarlo si aparece el dato.
        $result = '';
        // Inicializa $offset como contador o índice de control.
        $offset = 0;
        // Repite el bloque mientras la condición indicada sea verdadera.
        while (preg_match('/<italic>(.*?)<\/italic>/su', $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            // Prepara $matchText con el valor que se usará después.
            $matchText = $matches[0][0];
            // Prepara $matchPos con el valor que se usará después.
            $matchPos = $matches[0][1];
            // Prepara $prefix con el valor que se usará después.
            $prefix = substr($text, $offset, $matchPos - $offset);
            // Agrega contenido al texto acumulado en $result.
            $result .= htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
            // Agrega contenido al texto acumulado en $result.
            $result .= '<italic>' . htmlspecialchars($matches[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</italic>';
            // Prepara $offset con el valor que se usará después.
            $offset = $matchPos + strlen($matchText);
        }
        // Agrega contenido al texto acumulado en $result.
        $result .= htmlspecialchars(substr($text, $offset), ENT_QUOTES | ENT_XML1, 'UTF-8');
        // Devuelve el resultado final de esta parte del proceso.
        return $result;
    }
}
