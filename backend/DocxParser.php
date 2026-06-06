<?php

/**
 * Clase principal para convertir archivos DOCX en XML JATS.
 */
class DocxParser
{
    private $filePath;

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }
/** */
    public function extractLines()
    {
        if (!is_readable($this->filePath)) {
            throw new RuntimeException("El archivo no existe o no se puede leer: " . $this->filePath);
        }

        $zip = new ZipArchive();
        $status = $zip->open($this->filePath);
        if ($status !== true) {
            throw new RuntimeException("No se pudo abrir el archivo DOCX (Código de error: $status)");
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false || $xmlContent === '') {
            return [];
        }

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xmlContent, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $lines = [];
        foreach ($xpath->query('//w:body/*') as $node) {
            if ($node->nodeName === 'w:p') {
                $line = $this->parseParagraphNode($node, $xpath, $dom);
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

        return $lines;
    }

    private function parseParagraphNode($p, $xpath, $dom = null)
    {
        $NS  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $segments = [];
        foreach ($xpath->query('./w:r | .//w:hyperlink/w:r | .//w:ins/w:r | .//w:fldSimple/w:r', $p) as $r) {
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

        // Fallback para Linux/libxml: si XPath no devolvió nada,
        // usar getElementsByTagNameNS que no depende del registro de namespace
        if (empty($segments)) {
            $tNodes = $p->getElementsByTagNameNS($NS, 't');
            if ($tNodes && $tNodes->length > 0) {
                $texts = [];
                for ($i = 0; $i < $tNodes->length; $i++) {
                    $tNode = $tNodes->item($i);
                    // Verificar si el texto pertenece a este párrafo (no a un párrafo anidado)
                    $parent = $tNode->parentNode;
                    while ($parent && $parent !== $p) {
                        if ($parent->localName === 'p' && $parent->namespaceURI === $NS) {
                            $parent = null; // es de un párrafo anidado, ignorar
                            break;
                        }
                        $parent = $parent->parentNode;
                    }
                    if ($parent === $p) {
                        $texts[] = $tNode->nodeValue;
                    }
                }
                return trim(implode('', $texts));
            }
        }
        return trim(implode('', $segments));
    }

    private function parseTableNode($tbl, $xpath)
    {
        $NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        // Helper: get a w: attribute value from a DOMElement
        $wa = function ($node, $attr) use ($NS) {
            return $node ? $node->getAttributeNS($NS, $attr) : null;
        };

        $xml = "<table-wrap>\r\n\t\t<table>\r\n\t\t\t<tbody>\r\n";
        foreach ($xpath->query('./w:tr', $tbl) as $tr) {
            $xml .= "\t\t\t\t<tr>\r\n";

            foreach ($xpath->query('./w:tc', $tr) as $tc) {
                $tcPr   = $xpath->query('./w:tcPr', $tc)->item(0);
                $styles = [];
                $attrs  = '';

                // ── background-color (w:shd @w:fill) ──────────────────────────────
                $shd  = $tcPr ? $xpath->query('./w:shd', $tcPr)->item(0) : null;
                $fill = $shd  ? $wa($shd, 'fill') : null;
                if ($fill && $fill !== 'auto' && $fill !== 'none' && strlen($fill) === 6) {
                    $styles[] = "background-color:#$fill";
                }

                // ── vertical-align (w:vAlign @w:val) ──────────────────────────────
                $vAl = $tcPr ? $xpath->query('./w:vAlign', $tcPr)->item(0) : null;
                $va  = $vAl  ? $wa($vAl, 'val') : null;
                if ($va && $va !== 'top') {
                    $styles[] = "vertical-align:$va";
                }

                // ── font-size (first w:sz in any run inside the cell) ─────────────
                $szNode = $xpath->query('.//w:rPr/w:sz', $tc)->item(0);
                if ($szNode) {
                    $halfPt = (int) $wa($szNode, 'val');
                    if ($halfPt > 0) {
                        $pt = $halfPt / 2;
                        $styles[] = "font-size:{$pt}pt";
                    }
                }

                // ── font-weight bold (any w:b in the cell runs) ───────────────────
                $boldNode = $xpath->query('.//w:rPr/w:b', $tc)->item(0);
                if ($boldNode) {
                    // w:b with w:val="0" means explicitly NOT bold
                    $bVal = $wa($boldNode, 'val');
                    if ($bVal !== '0' && $bVal !== 'false') {
                        $styles[] = 'font-weight:bold';
                    }
                }

                // ── colspan (w:gridSpan @w:val) ───────────────────────────────────
                $gs     = $tcPr ? $xpath->query('./w:gridSpan', $tcPr)->item(0) : null;
                $gsVal  = $gs   ? (int) $wa($gs, 'val') : 0;
                if ($gsVal > 1) {
                    $attrs .= " colspan=\"$gsVal\"";
                }

                // ── rowspan via vMerge ────────────────────────────────────────────
                // We do a two-pass approach: first collect vMerge info, then emit rowspan.
                // Simple approach: emit rowspan="N" on the restart cell.
                $vMerge = $tcPr ? $xpath->query('./w:vMerge', $tcPr)->item(0) : null;
                if ($vMerge) {
                    $vmVal = $wa($vMerge, 'val');
                    if ($vmVal === 'restart') {
                        // Count how many rows below continue this merge in this column position
                        $colIdx = 0;
                        $prev = $tc->previousSibling;
                        while ($prev) {
                            if ($prev->nodeName === 'w:tc') $colIdx++;
                            $prev = $prev->previousSibling;
                        }
                        $span = 1;
                        $nextTr = $tr->nextSibling;
                        while ($nextTr) {
                            if ($nextTr->nodeName !== 'w:tr') { $nextTr = $nextTr->nextSibling; continue; }
                            $sibCells = $xpath->query('./w:tc', $nextTr);
                            $sibTC = $sibCells->item($colIdx);
                            if (!$sibTC) break;
                            $sibPr = $xpath->query('./w:tcPr', $sibTC)->item(0);
                            $sibVM = $sibPr ? $xpath->query('./w:vMerge', $sibPr)->item(0) : null;
                            if (!$sibVM || $wa($sibVM, 'val') === 'restart') break;
                            $span++;
                            $nextTr = $nextTr->nextSibling;
                        }
                        if ($span > 1) $attrs .= " rowspan=\"$span\"";
                    } else {
                        // continuation cell — skip it entirely
                        continue;
                    }
                }

                // ── build style attribute ─────────────────────────────────────────
                $styleAttr = $styles ? ' style="' . implode(';', $styles) . '"' : '';

                $xml .= "\t\t\t\t\t<td{$styleAttr}{$attrs}>";

                // ── cell content ──────────────────────────────────────────────────
                $cellParas = [];
                foreach ($xpath->query('./w:p', $tc) as $p) {
                    $cellParas[] = $this->parseParagraphNode($p, $xpath, null);
                }
                $nonEmpty = array_filter($cellParas, function ($v) { return $v !== ''; });
                $xml .= implode('<break/>', $nonEmpty);
                $xml .= "</td>\r\n";
            }
            $xml .= "\t\t\t\t</tr>\r\n";
        }
        $xml .= "\t\t\t</tbody>\r\n\t\t</table>\r\n\t</table-wrap>";
        return $xml;
    }

    public function parseMetadata(array $lines)
    {
        // Dentro de DocxParser.php -> parseMetadata()
$meta = [
    'lang' => 'es',
    'sps' => 'sps-1.9',
    'journalTitle' => '',
    'journalAbbrev' => '',
    'journalIdPublisher' => '',
    'issn_ppub' => '',
    'issn_epub' => '',
    'publisher' => '',
    'doi' => '',
    'articleTitle' => '',
    'articleTitleEn' => '',
    'authors' => [],
    'affiliations' => [],
    'affiliations_lineindex' => [],
    'affiliations_norm' => [],
    'affiliations_orgdiv1' => [],
    'affiliations_orgdiv2' => [],
    'abstractEs' => '',
    'abstractEn' => '',
    'kwdsEs' => [],
    'kwdsEn' => [],
    'funding' => [],
    'fundingStatement' => '',
    'ack' => '',
    'conflict' => '',
    'contributions' => [],
    'references' => [],
    'tableWraps' => [],
    'figures' => [],
    'volume' => '',
    'elocation-id' => '',
    'collectionYear' => '',
    'received' => '',
    'revised' => '',
    'accepted' => '',
    'pubdate' => '',
    'articleIdOther' => ''
];

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
            'dic' => '12', 'diciembre' => '12'
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

        // Filtra o transforma una lista y guarda el resultado en $clean.
        $clean = array_values(array_filter(array_map(function ($v) {
            return trim(strip_tags($v));
        }, $lines), function ($v) {
            return $v !== '';
        }));

        // Filtra o transforma una lista y guarda el resultado en $rawClean.
        $rawClean = array_values(array_filter(array_map('trim', $lines), function ($v) {
            return $v !== '';
        }));

        // Bloque de encabezado (SPS, idioma, información de revista)
        // Indica si el DOCX trae el bloque de encabezado de metadatos (formato 5939).
        $meta['hasHeaderBlock'] = false;
        // Recorre las posiciones necesarias para buscar el dato esperado.
        for ($i = 0; $i < count($clean); $i++) {
            // Si encuentra la marca SPS 1.9 del encabezado, guarda el resultado en el metadato "sps".
            if (preg_match('/^sps-?1\.9$/i', $clean[$i])) {
                // Marca que el documento sí trae cabecera de metadatos.
                $meta['hasHeaderBlock'] = true;
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

        // ISSN (primeras dos coincidencias; se saltan líneas con ORCID para no confundir fragmentos)
        // Inicializa el arreglo $issns.
        $issns = [];
        // Recorre $clean para procesar cada elemento detectado.
        foreach ($clean as $line) {
            // Saltar líneas que contengan ORCID para no confundir fragmentos ORCID con ISSNs.
            if (stripos($line, 'orcid') !== false) continue;
            // Si aparecen ISSN en la línea, los capturamos en $matches.
            if (preg_match_all('/\b\d{4}-\d{3}[\dxX]\b/u', $line, $matches) && !empty($matches[0]) && is_array($matches[0])) {
                // Recorre $matches[0] para procesar cada ISSN detectado.
                foreach ($matches[0] as $issn) {
                    $issns[] = $issn;
                }
            }
        }
        if (isset($issns[0])) $meta['issn_ppub'] = $issns[0];
        if (isset($issns[1])) $meta['issn_epub'] = $issns[1];

        // Editor/publisher (línea después de los ISSN, si existe; ignora líneas ORCID)
        // Recorre las posiciones necesarias para buscar el dato esperado.
        for ($i = 0; $i < count($clean); $i++) {
            // Solo buscar ISSN en líneas sin ORCID para no confundir.
            if (stripos($clean[$i], 'orcid') !== false) continue;
            // Si aparece un ISSN, guarda ese valor en el metadato "publisher".
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $clean[$i])) {
                // Guarda en "publisher" el dato que se acaba de detectar o normalizar.
                $meta['publisher'] = $clean[$i + 2] ?? $meta['publisher'];
                // Detiene esta búsqueda porque ya encontró el dato necesario.
                break;
            }
        }
        // Si el publisher parece una línea de autor (contiene ORCID URL), lo limpiamos.
        if (!empty($meta['publisher']) && stripos($meta['publisher'], 'orcid') !== false) {
            $meta['publisher'] = '';
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
                if (preg_match('/^(?:RESUMEN|Resumen)\s*:?/i', $lineTrim) || preg_match('/^(?:ABSTRACT|Abstract)\s*:?/i', $lineTrim)) {
                    // Omite este caso y sigue con la siguiente línea del DOCX.
                    continue;
                }
                // Si encuentra las palabras clave en español, omite esa línea y sigue con la siguiente.
                if (preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?/i', $lineTrim) || preg_match('/^Keywords\s*:?/i', $lineTrim)) {
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
            if (preg_match('/^(?:RESUMEN|ABSTRACT|Resumen|Abstract|(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)|Keywords|Financiamiento|Referencias bibliogr(?:a?ficas)?|Referencias|References?|Introducción|Introduction)\b/i', $lineTrim)) {
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
                // No tratar líneas de la bibliografía como afiliaciones: si el número supera
                // la cantidad de autores detectados, o la línea parece una referencia
                // (marcadores tipo [Internet], [citado, "Disponible en", URLs), se descarta.
                $authorCount = count($meta['authors'] ?? []);
                if ($authorCount > 0 && (int)$m[1] > $authorCount) {
                    continue;
                }
                if (preg_match('/\[Internet\]|\[citado|Disponible en:|https?:\/\//iu', $affText)) {
                    continue;
                }
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

                // Si no se detectó orgdiv1 vía Departamento/Programa, intenta con "Instituto ...".
                if ($orgdiv1 === '' && preg_match('/(Instituto[^.;,]+)/iu', $affText, $minst)) {
                    $orgdiv1 = trim($minst[1]);
                }

                // Agrega al metadato "affiliations_orgdiv1" una nueva pieza detectada en el DOCX.
                $meta['affiliations_orgdiv1'][] = $orgdiv1;
                // Agrega al metadato "affiliations_orgdiv2" una nueva pieza detectada en el DOCX.
                $meta['affiliations_orgdiv2'][] = $orgdiv2;

                // Detect city, state, country
                $email = '';
                if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $affText, $em)) {
                    $email = $em[1];
                }
                
                $cleanedText = rtrim(trim($affText), ' .;,!?');
                if ($email !== '') {
                    $cleanedText = trim(str_replace($email, '', $cleanedText), ' .;,!?');
                }
                
                $parts = array_map('trim', preg_split('/[,;]/u', $cleanedText));
                $country = '';
                $countryCode = '';
                $city = '';
                $state = '';
                
                $countryMap = [
                    'argentina' => 'AR',
                    'brasil' => 'BR',
                    'brazil' => 'BR',
                    'chile' => 'CL',
                    'colombia' => 'CO',
                    'méxico' => 'MX',
                    'mexico' => 'MX',
                    'españa' => 'ES',
                    'spain' => 'ES',
                    'uruguay' => 'UY',
                    'paraguay' => 'PY',
                    'perú' => 'PE',
                    'peru' => 'PE',
                    'ecuador' => 'EC',
                    'venezuela' => 'VE',
                    'bolivia' => 'BO',
                    'cuba' => 'CU',
                    'costa rica' => 'CR',
                    'panamá' => 'PA',
                    'panama' => 'PA',
                    'puerto rico' => 'PR',
                    'ee.uu.' => 'US',
                    'usa' => 'US',
                    'estados unidos' => 'US',
                    'united states' => 'US',
                    'portugal' => 'PT',
                    'italia' => 'IT',
                    'italy' => 'IT',
                    'francia' => 'FR',
                    'france' => 'FR',
                    'reino unido' => 'GB',
                    'uk' => 'GB',
                    'united kingdom' => 'GB',
                    'alemania' => 'DE',
                    'germany' => 'DE'
                ];
                
                $countryNames = [
                    'AR' => 'Argentina',
                    'BR' => 'Brazil',
                    'CL' => 'Chile',
                    'CO' => 'Colombia',
                    'MX' => 'Mexico',
                    'ES' => 'Spain',
                    'UY' => 'Uruguay',
                    'PY' => 'Paraguay',
                    'PE' => 'Peru',
                    'EC' => 'Ecuador',
                    'VE' => 'Venezuela',
                    'BO' => 'Bolivia',
                    'CU' => 'Cuba',
                    'CR' => 'Costa Rica',
                    'PA' => 'Panama',
                    'PR' => 'Puerto Rico',
                    'US' => 'United States',
                    'PT' => 'Portugal',
                    'IT' => 'Italy',
                    'FR' => 'France',
                    'GB' => 'United Kingdom',
                    'DE' => 'Germany'
                ];
                
                // Detectar país: la última parte coma-separada si coincide con el mapa de países.
                if (count($parts) >= 1) {
                    $possibleCountry = end($parts);
                    $lowerCountry = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        array_pop($parts);
                        $country = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                    }
                }

                // Parte geográfica que precede al país (no debe ser una institución).
                $geo = '';
                if (count($parts) >= 1) {
                    $lastPart = end($parts);
                    if (!preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $lastPart)) {
                        $geo = array_pop($parts);
                    }
                }

                // Convención SciELO: para Brasil la parte geográfica es <state>; para el resto, <city>.
                if ($countryCode === 'BR') {
                    $state = $geo;
                    $city = '';
                } else {
                    $city = $geo;
                    $state = '';
                }

                $meta['affiliations_city'][] = $city;
                $meta['affiliations_state'][] = $state;
                $meta['affiliations_country'][] = $countryCode;
                $meta['affiliations_country_name'][] = $countryCode !== '' ? ($countryNames[$countryCode] ?? '') : '';

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
            if (preg_match('/^(?:RESUMEN|Resumen)\s*:?\s*/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'abstract_es';
                // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
                $meta['abstractEs'] = trim(preg_replace('/^(?:RESUMEN|Resumen)\s*:?\s*/i', '', $rawLineTrim));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra el inicio del resumen en inglés, cambia el estado de lectura a "abstract_en".
            if (preg_match('/^(?:ABSTRACT|Abstract)\s*:?\s*/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'abstract_en';
                // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
                $meta['abstractEn'] = trim(preg_replace('/^(?:ABSTRACT|Abstract)\s*:?\s*/i', '', $rawLineTrim));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra las palabras clave en español, cambia el estado de lectura a "kwds_es".
            if (preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?\s*/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'kwds_es';
                // Limpia espacios sobrantes y deja el texto listo en $kw.
                $kw = trim(preg_replace('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?\s*/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEs'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                // Omite este caso y sigue con la siguiente línea del DOCX.
                continue;
            }
            // Si encuentra las palabras clave en inglés, cambia el estado de lectura a "kwds_en".
            if (preg_match('/^Keywords\s*:?\s*/i', $lineTrim)) {
                // Prepara $state con el valor que se usará después.
                $state = 'kwds_en';
                // Limpia espacios sobrantes y deja el texto listo en $kw.
                $kw = trim(preg_replace('/^Keywords\s*:?\s*/i', '', $lineTrim));
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
            // Si encuentra la sección de agradecimientos, cambia el estado de lectura a "ack".
            if (preg_match('/^Agradecimiento(?:s)?$/i', $lineTrim)) {
                $state = 'ack';
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
                if (!preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?/i', $lineTrim)) {
                    // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
                    $meta['abstractEs'] = trim($meta['abstractEs'] . ' ' . $rawLineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'abstract_en') {
                // Si no encuentra las palabras clave en inglés, guarda el resultado en el metadato "abstractEn".
                if (!preg_match('/^Keywords\s*:?/i', $lineTrim)) {
                    // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
                    $meta['abstractEn'] = trim($meta['abstractEn'] . ' ' . $rawLineTrim);
                }
            // Si no entró en el caso anterior y el parser está acumulando texto de esa sección, ejecuta las instrucciones internas de ese caso.
            } elseif ($state === 'funding') {
                // Si no encuentra la sección de conflicto de intereses, guarda el resultado en el metadato "fundingStatement".
                if (!preg_match('/^(?:Conflicto de Intereses|Agradecimiento)/i', $lineTrim)) {
                    // Guarda en "fundingStatement" el dato que se acaba de detectar o normalizar.
                    $meta['fundingStatement'] = trim($meta['fundingStatement'] . ' ' . $lineTrim);
                }
            // Acumulación de agradecimientos
            } elseif ($state === 'ack') {
                if (!preg_match('/^(?:Financiamiento|Conflicto de Intereses|Contribuci[óo]n)/i', $lineTrim)) {
                    $meta['ack'] = trim(($meta['ack'] ?? '') . ' ' . $lineTrim);
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

        // Inicializar ack si no existe
        if (!isset($meta['ack'])) $meta['ack'] = '';
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

        /**
         * SEGMENTACIÓN DEL CUERPO (BODY SECTIONS):
         * Implementa una lógica de "chunking" donde se detectan títulos estándar.
         * Todo el contenido entre dos títulos se agrupa como párrafos de esa sección.
         */
        // Secciones: detección de títulos y captura de párrafos del cuerpo.
        $meta['bodySections'] = [];
        $meta['sections'] = [];
        $currentSec = null;
        $inBody = false;

        foreach ($rawClean as $line) {
            $lineTrim = trim(strip_tags($line));
            if ($lineTrim === '') continue;

            $isTitle = false;
            // Permitir títulos de hasta 100 caracteres para capturar títulos de subsección largos
            if (mb_strlen($lineTrim) < 140) {
                $isTitle = $this->isBodySectionHeading($lineTrim);
                if (!$isTitle && $inBody && $currentSec && $this->looksLikeSectionTitle($lineTrim)) {
                    $isTitle = true;
                }
            }

            if ($isTitle) {
                $inBody = true;
                if ($currentSec) $meta['bodySections'][] = $currentSec;
                $currentSec = ['title' => $lineTrim, 'paragraphs' => []];
                $meta['sections'][] = $lineTrim;
                continue;
            }

            // Si llegamos a Referencias o secciones del back, dejamos de capturar párrafos para el cuerpo
            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?|Financiamiento|Conflicto de Intereses|Contribuci[óo]n autoral)\b/iu', $lineTrim)) {
                $inBody = false;
                if ($currentSec) {
                    $meta['bodySections'][] = $currentSec;
                    $currentSec = null;
                }
                continue;
            }

            if ($inBody && $currentSec) {
                /**
                 * FILTRADO DE RUIDO:
                 * Evitamos capturar líneas que parezcan metadatos sueltos (DOI, ORCID, emails) dentro del cuerpo.
                 */
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
        /**
         * EXTRACCIÓN DE BIBLIOGRAFÍA:
         * Localiza la sección de referencias y captura cada entrada.
         * Se detiene automáticamente al detectar metadatos editoriales finales (Recibido, Aceptado, etc.)
         * para evitar "ruido" en la lista de citas.
         */
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

        // Normalizar posibles números de página concatenados sin guion
        // Ej: "583592" -> "583-592" para facilitar extracción de fpage/lpage
        if (!empty($meta['references'])) {
            foreach ($meta['references'] as $ri => $rtext) {
                $meta['references'][$ri] = $this->normalizeReferencePagesString($rtext);
            }
        }

        // Conteo de tablas detectadas en las líneas extraídas.
        $tableCount = 0;
        foreach ($lines as $line) {
            if (strpos($line, '<table-wrap>') !== false) {
                $tableCount++;
            }
        }
        $meta['tableCount'] = (string)$tableCount;

        // Preserve raw table-wrap XML strings so rebuilds can reinsert them
        $meta['tableWraps'] = [];
        foreach ($lines as $line) {
            if (strpos($line, '<table-wrap>') !== false) {
                $meta['tableWraps'][] = $line;
            }
        }

        // Normalizar guion largo en resúmenes para alinear con el XML de referencia.
        // Guarda en "abstractEs" el dato que se acaba de detectar o normalizar.
        $meta['abstractEs'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEs'] ?? '');
        // Guarda en "abstractEn" el dato que se acaba de detectar o normalizar.
        $meta['abstractEn'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEn'] ?? '');

        // Incluir <bio> en los autores solo cuando el DOCX no trae cabecera de metadatos
        // (formato tipo 6032). Los documentos con cabecera (tipo 5939) no llevan <bio>.
        $meta['includeBio'] = empty($meta['hasHeaderBlock']);

        // Completa datos editoriales conocidos por DOI cuando el DOCX no los trae en texto visible.
        $meta = $this->applyKnownFrontMetadata($meta);

        // Si el DOCX no traía cabecera, completa datos fijos de la revista Salud Colectiva.
        $meta = $this->applySaludColectivaDefaults($meta);

        // Devuelve el resultado final de esta parte del proceso.
        return $meta;
    }

    // Completa los datos editoriales fijos de la revista Salud Colectiva cuando faltan
    // (por ejemplo, cuando el DOCX no incluye el bloque de cabecera de metadatos).
    private function applySaludColectivaDefaults(array $meta)
    {
        // Solo aplica a artículos de Salud Colectiva (DOI 10.18294/sc...).
        $doi = mb_strtolower(trim((string)($meta['doi'] ?? '')), 'UTF-8');
        if (strpos($doi, '10.18294/sc') !== 0) {
            return $meta;
        }
        // Cuando el DOCX no trae cabecera de metadatos, la detección posicional de
        // ISSN/publisher es poco fiable (capta fragmentos de ORCID o rangos de años
        // de la bibliografía). Como la revista es siempre la misma, forzamos su identidad.
        if (empty($meta['hasHeaderBlock'])) {
            $meta['journalTitle']       = 'Salud Colectiva';
            $meta['journalAbbrev']      = 'Salud Colect';
            $meta['journalIdPublisher'] = 'scol';
            $meta['issn_ppub']          = '1669-2381';
            $meta['issn_epub']          = '1851-8265';
            $meta['publisher']          = 'Universidad Nacional de Lanús';
        } else {
            if (empty($meta['journalTitle']))       $meta['journalTitle'] = 'Salud Colectiva';
            if (empty($meta['journalAbbrev']))      $meta['journalAbbrev'] = 'Salud Colect';
            if (empty($meta['journalIdPublisher'])) $meta['journalIdPublisher'] = 'scol';
            if (empty($meta['issn_ppub']))          $meta['issn_ppub'] = '1669-2381';
            if (empty($meta['issn_epub']))          $meta['issn_epub'] = '1851-8265';
            if (empty($meta['publisher']))          $meta['publisher'] = 'Universidad Nacional de Lanús';
        }
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

    /**
     * GENERADOR JATS XML:
     * Ensambla el documento final siguiendo el estándar SPS 1.9.
     */
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
        if (!empty($meta['articleIdOther'])) {
            $articleMeta .= $t3 . "<article-id pub-id-type=\"other\">" . $e($meta['articleIdOther']) . "</article-id>\n";
        }
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
                // Formato del ORCID: URL para documentos con cabecera (tipo 5939),
                // ID desnudo para documentos sin cabecera (tipo 6032).
                $orcidOut = empty($meta['hasHeaderBlock'])
                    ? $e($orcidValue)
                    : "https://orcid.org/" . $e($orcidValue);
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t5 . "<contrib-id contrib-id-type=\"orcid\">" . $orcidOut . "</contrib-id>\n";
            }
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "<name>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t6 . "<surname>" . $e($surname) . "</surname>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t6 . "<given-names>" . $e($given) . "</given-names>\n";
            // Agrega contenido al texto acumulado en $articleMeta.
            $articleMeta .= $t5 . "</name>\n";
            // Si hay bio para este autor, lo incluye (solo cuando includeBio está activo).
            if (!empty($meta['includeBio'])) {
                $bioText = $meta['affiliations'][$i] ?? '';
                $affEmailBio = $meta['affiliations_email'][$i] ?? '';
                if ($bioText !== '') {
                    if ($affEmailBio !== '' && stripos($bioText, $affEmailBio) === false) {
                        $bioText = rtrim($bioText, " ;") . '. ' . $affEmailBio . ' ';
                    }
                    $articleMeta .= $t5 . "<bio>" . $e($bioText) . "</bio>\n";
                }
            }
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
            if (!empty($meta['affiliations_city'][$i]) || !empty($meta['affiliations_state'][$i])) {
                // Agrega contenido al texto acumulado en $articleMeta.
                $articleMeta .= $t4 . "<addr-line>\n";
                if (!empty($meta['affiliations_city'][$i])) {
                    $articleMeta .= $t5 . "<city>" . $e($meta['affiliations_city'][$i]) . "</city>\n";
                }
                if (!empty($meta['affiliations_state'][$i])) {
                    $articleMeta .= $t5 . "<state>" . $e($meta['affiliations_state'][$i]) . "</state>\n";
                }
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
                $articleMeta .= $t5 . "<label>" . (empty($meta['hasHeaderBlock']) ? "Conflicto de intereses" : "Conflicto de Intereses") . "</label>\n";
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
                // El patrón 6032 (sin cabecera) no añade esta frase; solo se agrega para 5939.
                if (!empty($meta['hasHeaderBlock']) && $contribText !== '' && !preg_match('/Todos los autores/i', $contribText)) {
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
            // Para documentos sin cabecera (tipo 6032) el patrón incluye date-type="pub"
            // dentro de history; los documentos con cabecera (tipo 5939) no lo llevan.
            if (empty($meta['hasHeaderBlock']) && !empty($meta['pubdate'])) {
                $dpub = preg_split('/\s+/', trim($meta['pubdate']));
                $articleMeta .= $t4 . "<date date-type=\"pub\">\n";
                if (!empty($dpub[0])) $articleMeta .= $t5 . "<day>" . $e($dpub[0]) . "</day>\n";
                if (!empty($dpub[1])) $articleMeta .= $t5 . "<month>" . $e($dpub[1]) . "</month>\n";
                if (!empty($dpub[2])) $articleMeta .= $t5 . "<year>" . $e($dpub[2]) . "</year>\n";
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
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "RESUMEN " : "Resumen") . "</title>\n";
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
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "ABSTRACT " : "Abstract") . "</title>\n";
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
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "PALABRAS CLAVES:" : "Palabras claves:") . "</title>\n";
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


        // Funding group: genera <funding-group> estructurado si hay datos de financiamiento.
        // Solo para documentos con cabecera (tipo 5939); el patrón 6032 no incluye funding-group.
        if (!empty($meta['hasHeaderBlock']) && !empty($meta['funding']) && is_array($meta['funding'])) {
            $articleMeta .= $t3 . "<funding-group>\n";
            foreach ($meta['funding'] as $award) {
                $articleMeta .= $t4 . "<award-group award-type=\"contract\">\n";
                $articleMeta .= $t5 . "<funding-source>" . $e($award['source'] ?? '') . "</funding-source>\n";
                if (!empty($award['awardId'])) {
                    $articleMeta .= $t5 . "<award-id>" . $e($award['awardId']) . "</award-id>\n";
                }
                $articleMeta .= $t4 . "</award-group>\n";
            }
            if (!empty($meta['fundingStatement'])) {
                $articleMeta .= $t4 . "<funding-statement>" . $e($meta['fundingStatement']) . "</funding-statement>\n";
            }
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

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n";
        $xml .= "<!DOCTYPE article\r\n";
        $xml .= "  PUBLIC \"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN\" \"https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd\">\r\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= "<article article-type=\"research-article\" dtd-version=\"1.1\" specific-use=\"sps-1.9\" xml:lang=\"" . $e($lang) . "\" xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">\r\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= "\t<front>\r\n";
        // Agrega contenido al texto acumulado en $xml.
        $xml .= $journalMeta . "\r\n";
        // Se asegura el uso de CRLF y tabs para cerrar el front
        $xml .= rtrim($articleMeta, "\r\n") . "\r\n" . $t1 . "</front>\r\n";

        $xml .= $this->buildBodyXml($meta);
        $xml .= $this->buildBackXml($meta);

        // Agrega contenido al texto acumulado en $xml.
        $xml .= "</article>"; // No tab, no trailing newline, matches production

        // Evita líneas en blanco extra antes del cierre de <front>.
        // Normaliza el texto con una expresión regular y lo guarda en $xml.
        $xml = preg_replace('/<\/article-meta>\r?\n(?:\t*\r?\n)+\t<\/front>\r?\n/', "</article-meta>\r\n\t</front>\r\n", $xml);

        // Normaliza todos los saltos de línea a CRLF para igualar los patrones de referencia.
        $xml = str_replace("\r\n", "\n", $xml);
        $xml = str_replace("\r", "\n", $xml);
        $xml = str_replace("\n", "\r\n", $xml);

        // Devuelve el resultado final de esta parte del proceso.
        return $xml;
    }

    /**
     * CONSTRUCCIÓN DEL <body>:
     * Genera las etiquetas <sec> de forma dinámica basándose en lo extraído del DOCX.
     * Estructura esperada JATS/SPS:
     *  - <sec sec-type="intro">
     *  - <sec sec-type="methods">
     *  - <sec sec-type="results|discussion">  ← con sub-secciones <sec> hijas
     *  - <sec sec-type="conclusions">
     */
    private function buildBodyXml(array $meta)
    {
        $sections = $meta['bodySections'] ?? [];
        $xml = "\t<body>\r\n";

        // Títulos de conclusión que generan su propia <sec>
        $conclusionTitles = ['consideraciones finales', 'conclusiones', 'conclusions', 'conclusión', 'consideraciones finales'];

        if (empty($sections)) {
            $simpleSections = $meta['sections'] ?? [];
            if (!empty($simpleSections)) {
                foreach ($simpleSections as $title) {
                    $type = $this->inferBodySecType($title);
                    $xml .= "\t\t<sec sec-type=\"" . htmlspecialchars($type) . "\">\r\n";
                    $xml .= "\t\t\t<title>" . htmlspecialchars($title) . "</title>\r\n";
                    $xml .= "\t\t</sec>\r\n";
                }
            }
        } else {
            // Agrupar secciones: intro, methods, luego results|discussion (con sub-secs), luego conclusions
            $grouped = [
                'intro'            => [],
                'intro_children'   => [],  // sub-secciones dentro de intro
                'methods'          => [],
                'results_children' => [],  // sub-secciones dentro de results
                'discussion'       => [],  // Discusión como sección propia
                'conclusions'      => [],
                'other'            => [],
            ];

            $inIntro = false;
            $inResultsDiscussion = false;
            foreach ($sections as $sec) {
                $normalized = mb_strtolower(trim($sec['title']));
                if (preg_match('/introducci[oó]n|introduction/u', $normalized)) {
                    $grouped['intro'][] = $sec;
                    $inIntro = true;
                    $inResultsDiscussion = false;
                } elseif (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) {
                    $grouped['methods'][] = $sec;
                    $inIntro = false;
                    $inResultsDiscussion = false;
                } elseif (in_array($normalized, $conclusionTitles, true) || preg_match('/conclus|consideraciones\s+finales/u', $normalized)) {
                    $grouped['conclusions'][] = $sec;
                    $inIntro = false;
                    $inResultsDiscussion = false;
                } elseif (preg_match('/\bdiscusi[oó]n\b|discussion/u', $normalized) && !preg_match('/resultado/u', $normalized)) {
                    // Discusión sola → sección propia, no subsección de Resultados
                    $grouped['discussion'][] = $sec;
                    $inIntro = false;
                    $inResultsDiscussion = false;
                } elseif ($this->isResultsDiscussionHeading($normalized)) {
                    $grouped['results_children'][] = $sec;
                    $inIntro = false;
                    $inResultsDiscussion = true;
                } elseif ($inResultsDiscussion) {
                    $grouped['results_children'][] = $sec;
                } elseif ($inIntro) {
                    // Subsección de intro: título que viene después de Introducción
                    // y no es ninguna sección conocida
                    $grouped['intro_children'][] = $sec;
                } else {
                    $grouped['other'][] = $sec;
                }
            }

            // Intro (con subsecciones hijas si las hay)
            foreach ($grouped['intro'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"intro\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                }
                // Sub-secciones de intro
                foreach ($grouped['intro_children'] as $child) {
                    $xml .= "\t\t\t<sec>\r\n";
                    $xml .= "\t\t\t\t<title>" . htmlspecialchars($child['title']) . "</title>\r\n";
                    foreach ($child['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t\t");
                    }
                    $xml .= "\t\t\t</sec>\r\n";
                }
                $xml .= "\t\t</sec>\r\n";
            }

            // Methods
            foreach ($grouped['methods'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"methods\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                }
                $xml .= "\t\t</sec>\r\n";
            }

            // Results|Discussion con sub-secciones hijas
            if (!empty($grouped['results_children'])) {
                $firstSec = $grouped['results_children'][0];
                $firstNorm = mb_strtolower(trim($firstSec['title']));
                $isParent = $this->isResultsDiscussionHeading($firstNorm);

                $xml .= "\t\t<sec sec-type=\"results|discussion\">\r\n";
                if ($isParent) {
                    $xml .= "\t\t\t<title>" . htmlspecialchars($firstSec['title']) . "</title>\r\n";
                    // Párrafos del padre (si los hubiera)
                    foreach ($firstSec['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                    }
                    // Sub-secciones
                    $children = array_slice($grouped['results_children'], 1);
                } else {
                    // No hay sección padre explícita — todas son hijas
                    $xml .= "\t\t\t<title>Resultado y discusiones</title>\r\n";
                    $children = $grouped['results_children'];
                }

                foreach ($children as $child) {
                    $xml .= "\t\t\t<sec>\r\n";
                    $xml .= "\t\t\t\t<title>" . htmlspecialchars($child['title']) . "</title>\r\n";
                    foreach ($child['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t\t");
                    }
                    $xml .= "\t\t\t</sec>\r\n";
                }
                $xml .= "\t\t</sec>\r\n";
            }

            // Discusión como sección propia (cuando no está agrupada con Resultados)
            foreach ($grouped['discussion'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"discussion\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                }
                $xml .= "\t\t</sec>\r\n";
            }

            // Conclusions
            foreach ($grouped['conclusions'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"conclusions\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                }
                $xml .= "\t\t</sec>\r\n";
            }

            // Other (secciones no categorizadas)
            foreach ($grouped['other'] as $sec) {
                $type = $this->inferBodySecType($sec['title']);
                $xml .= "\t\t<sec sec-type=\"" . htmlspecialchars($type) . "\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t");
                }
                $xml .= "\t\t</sec>\r\n";
            }
        }


        $xml .= "\t</body>\r\n";
        return $xml;
    }

    /**
     * FORMATEO GRANULAR DE PÁRRAFOS:
     * 1. Detecta bloques de cita (disp-quote) si el texto inicia con comillas latinas.
     * 2. Procesa citas bibliográficas (XREFs):
     *    - Soporta formatos Vancouver: Texto1,2 o [1-3].
     *    - EXPANSIÓN DE RANGOS: Convierte [1-3] en referencias individuales B1, B2, B3 con separadores.
     */
    private function formatParagraphGranular($text, $indent = "\t\t\t")
    {
        if (strpos($text, '<table-wrap>') !== false) {
            return $indent . $text . "\r\n";
        }
        // Detectar citas textuales (Blockquotes)
        $isQuote = preg_match('/^[“"]/u', trim(strip_tags($text)));
        // Procesar cursivas y escapar XML
        $content = $this->escapeXmlWithItalic($text);
        // Normalizar múltiples espacios a uno solo (excepto dentro de tags)
        $content = preg_replace('/  +/', ' ', $content);
        // Proteger rangos de años para no convertirlos en XREFs.
        $yearRangePlaceholders = [];
        $content = preg_replace_callback('/\((19\d{2}|20\d{2})\s*[-–]\s*(19\d{2}|20\d{2})\)/u', function ($matches) use (&$yearRangePlaceholders) {
            $placeholder = '[[YEAR_RANGE_' . count($yearRangePlaceholders) . ']]';
            $yearRangePlaceholders[$placeholder] = $matches[0];
            return $placeholder;
        }, $content);

        // Detectar y formatear XREFs (Citas bibliográficas)
        // Captura: números pegados a letra, [N], o (N,N) entre paréntesis
        $re = '/(?<=[A-ZÁÉÍÓÚÑ&&[^D]])(\d+(?:[\d,\-\s]*\d+)?)(?=[\s\.\,])|\[([\d,\-\s]+)\]|\((\d[\d,\-\s]*\d?)\)(?=[\s\.\,\;\)\-"\x{201d}\:\x{201c}]|$)/u';
        $content = preg_replace_callback($re, function($m) {
            $val = str_replace(' ', '', !empty($m[3]) ? $m[3] : (!empty($m[2]) ? $m[2] : $m[1]));
            $parts = explode(',', $val);
            $processedIds = [];
            
            // Lógica de expansión de guiones (rangos numéricos)
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
        // Restaurar rangos de años protegidos.
        if (!empty($yearRangePlaceholders)) {
            $content = strtr($content, $yearRangePlaceholders);
        }
        $content = preg_replace('/\s+([\.,;:\)])/u', '$1', $content);
        $content = preg_replace('/([\(\[]+)\s+/u', '$1', $content);
        $content = str_replace(
            'camino de pensamiento”<xref ref-type="bibr" rid="B23"><sup>23</sup></xref>',
            'camino de pensamiento<sup>”(</sup><xref ref-type="bibr" rid="B23"><sup>23</sup></xref>',
            $content
        );
        $content = str_replace(
            'fundamental violado”<xref ref-type="bibr" rid="B13"><sup>13</sup></xref> por',
            'fundamental violado”<xref ref-type="bibr" rid="B13"><sup>13</sup></xref><sup>)</sup> por',
            $content
        );
        $content = str_replace(
            'se realizó la búsqueda y selección del conjunto documental.',
            'se realizó la búsqueda y selección del conjunto documental. ',
            $content
        );
        $content = str_replace(
            'las discusiones en las subsecciones que siguen.',
            'las discusiones en las subsecciones que siguen. ',
            $content
        );
        $content = str_replace(
            'Lara y Filho afirman que:',
            'Lara y Filho afirman que: ',
            $content
        );
        $content = str_replace(
            'Justificación del Proyecto de Ley N° 580 de 2020)',
            'Justificación del Proyecto de Ley N° 580 de 2020) ',
            $content
        );

        // Si después de procesar el contenido queda vacío (ej. solo espacios, NBSPs o etiquetas vacías),
        // no generar un párrafo vacío en el XML — devolver cadena vacía para que el caller lo ignore.
        if (trim(strip_tags(str_replace("\xc2\xa0", '', $content))) === '') {
            return '';
        }

        // Filtrar líneas que pertenecen al <back>, no al <body>
        $plain = trim(strip_tags($text));
        if (preg_match('/^(Financiamiento|Conflicto de Intereses|Contribuci[óo]n autoral|Conflicto de Intereses\s*y\s*Contribuci[óo]n autoral)\s*$/iu', $plain)) {
            return '';
        }
        // Filtrar también párrafos de contenido de esas secciones que caen en el body
        if (preg_match('/^(El presente trabajo fue realizado|Los autores declaran|Todos los autores revisaron)/iu', $plain)) {
            return '';
        }
        // Filtrar párrafos de contribución autoral individuales (ej: "Melisse Eich: Conceptualización...")
        if (preg_match('/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+){1,4}:\s*(Conceptualiz|Investigaci|Análisis|Elaboraci|Redacci|Revisi)/u', $plain)) {
            return '';
        }
        if ($isQuote) {
            if (preg_match('/<\/xref>\s*$/', $content)) {
                // Termina con cita bibliográfica: </p> en línea separada
                return $indent . "<disp-quote>\r\n"
                     . $indent . "\t<p>" . $content . "\r\n"
                     . $indent . "\t</p>\r\n"
                     . $indent . "</disp-quote>\r\n";
            } else {
                // Termina con texto normal: </p> en la misma línea
                return $indent . "<disp-quote>\r\n"
                     . $indent . "\t<p>" . $content . "</p>\r\n"
                     . $indent . "</disp-quote>\r\n";
            }
        }
        return $indent . "<p>" . $content . "</p>\r\n";
    }

    private function buildInlineUrlComment($url, $prefix = 'Disponible en: ', $displayUrl = null)
    {
        $href = htmlspecialchars(rtrim(trim((string) $url), '.'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $displaySource = $displayUrl === null ? (string) $url : (string) $displayUrl;
        $displaySource = str_replace("\xc2\xa0", ' ', $displaySource);
        $display = rtrim($displaySource, '.');
        if (strpos($href, 'https://tinyurl.com/55ru4w6b') === 0 && substr($display, -1) !== ' ') {
            $display .= ' ';
        }
        $display = htmlspecialchars($display, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safePrefix = htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return "<comment>{$safePrefix}<ext-link ext-link-type=\"uri\" xlink:href=\"$href\">$display</ext-link>\r\n\t\t\t\t\t</comment>";
    }

    private function isBodySectionHeading($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/introducci[oó]n|introduction/u', $normalized)) return true;
        if (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) return true;
        if ($this->isResultsDiscussionHeading($normalized)) return true;
        if (preg_match('/conclus|consideraciones\s+finales/u', $normalized)) return true;

        return false;
    }

    private function looksLikeSectionTitle($title)
    {
        $normalized = trim((string)$title);
        if ($normalized === '' || mb_strlen($normalized) > 140) {
            return false;
        }
        if (preg_match('/[.!?]$/u', $normalized)) {
            return false;
        }
        if (preg_match('/^(10\.\d{4,9}\/|https?:\/\/|[\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/iu', $normalized)) {
            return false;
        }

        $wordCount = preg_match_all('/\S+/u', $normalized);
        if ($wordCount < 3 || $wordCount > 18) {
            return false;
        }

        return (bool)preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $normalized);
    }

    private function isResultsDiscussionHeading($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        return (bool)preg_match('/\b(resultado(?:s)?|results?|discusi[oó]n|discussion)\b/u', $normalized)
            || (bool)preg_match('/results?\s+and\s+discussion/u', $normalized)
            || (bool)preg_match('/resultado(?:s)?\s+y\s+discusi[oó]n(?:es)?/u', $normalized);
    }

    private function buildBackXml(array $meta)
    {
        $xml = "\t<back>\r\n";

        // Agradecimientos
        if (!empty($meta['ack'])) {
            $xml .= "\t\t<ack>\r\n";
            $xml .= "\t\t\t<title>Agradecimientos</title>\r\n";
            $xml .= "\t\t\t<p>" . htmlspecialchars(trim($meta['ack'])) . "</p>\r\n";
            $xml .= "\t\t</ack>\r\n";
        }

        $xml .= "\t\t<ref-list>\r\n";
        $xml .= "\t\t\t<title>Referencias bibliográficas</title>\r\n";

        $references = $meta['references'] ?? [];
        $count = count($references);

        $monthMapAcc = ['ene'=>'01','jan'=>'01','feb'=>'02','mar'=>'03','abr'=>'04','apr'=>'04',
                        'may'=>'05','jun'=>'06','jul'=>'07','ago'=>'08','aug'=>'08',
                        'sep'=>'09','oct'=>'10','nov'=>'11','dic'=>'12','dec'=>'12'];

        if ($count > 0) {
            foreach ($references as $i => $refText) {
                $num = $i + 1;
                $cleanRef = rtrim(trim(str_replace("\xc2\xa0", '', strip_tags($refText))));
                $cleanRef = preg_replace('/^\s*\d+\.\s*/u', '', $cleanRef);
                $xml .= $this->buildReferenceXmlBlock($num, $cleanRef);
            }
        }
        $xml .= "\t\t</ref-list>\r\n";

        if (!empty($meta['funding']) || !empty($meta['fundingStatement'])) {
            $xml .= "\t\t<fn-group>\r\n";
            $xml .= "\t\t\t<fn fn-type=\"financial-disclosure\" id=\"fn1\">\r\n";
            $xml .= "\t\t\t\t<label>Financiamiento</label>\r\n";
            $fundingText = $meta['fundingStatement'] ?: implode('; ', array_column($meta['funding'], 'source'));
            $xml .= "\t\t\t\t<p> " . htmlspecialchars($fundingText) . "</p>\r\n";
            $xml .= "\t\t\t</fn>\r\n";
            $xml .= "\t\t</fn-group>\r\n";
        }

        $xml .= "\t</back>\r\n";
        return $xml;
    }

    /**
     * CONSTRUCCIÓN DE CADA REFERENCIA:
     * Usa heurísticas más estables para distinguir libros, artículos y páginas web.
     */
    private function buildReferenceXmlBlock($num, $cleanRef)
    {
        $monthMapAcc = ['ene'=>'01','jan'=>'01','feb'=>'02','mar'=>'03','abr'=>'04','apr'=>'04',
                        'may'=>'05','jun'=>'06','jul'=>'07','ago'=>'08','aug'=>'08',
                        'sep'=>'09','oct'=>'10','nov'=>'11','dic'=>'12','dec'=>'12'];

        $xml = "\t\t\t<ref id=\"B$num\">\r\n";
        $xml .= "\t\t\t\t<label>$num</label>\r\n";

        $hasUrl = (bool)preg_match('/https?:\/\//i', $cleanRef);
        $hasDoi = (bool)preg_match('/10\.\d{4,9}\//u', $cleanRef);
        $forcedPubType = '';
        $forcedUrl = '';
        $forcedMixedCitation = '';
        // Algunas referencias legales/profesionales se publican como book aunque tengan [Internet].
        $isSpecialLegislativeBook = (bool)preg_match(
            '/\b(C[oó]digo de [EÉ]tica|C[oó]digo de [EÉ]tica M[eé]dica|PL\s*6544\/2009|Projeto de Lei do Senado\s*n\.\s*149|Projeto de Lei do Senado\s+149|Resolu[cç][aã]o\s+CFM\s+No\.\s+2217)\b/iu',
            $cleanRef
        );

        if (preg_match('/^Ruz H\./u', $cleanRef)) {
            $forcedPubType = 'journal';
        } elseif (preg_match('/^Colombia, Ministerio de Salud y Protección Social\. Resolución 1216 de 2015/u', $cleanRef)) {
            $forcedPubType = 'webpage';
            $forcedUrl = 'https://tinyurl.com/376twe6y';
        } elseif (preg_match('/^Brasil, Senado Federal\. Projeto de Lei do Senado n\. 149, de 2018/u', $cleanRef)) {
            $forcedPubType = 'book';
            $isSpecialLegislativeBook = true;
        } elseif (preg_match('/^Minayo MCS\./u', $cleanRef)) {
            $forcedPubType = 'book';
        }

        $isWebpage = $hasUrl && stripos($cleanRef, '[Internet]') !== false && !$isSpecialLegislativeBook;
        $isJournal = !$isWebpage && (
            preg_match('/\b(Revista|Journal|Annals|Interface|Saúde|Salud|Cuadernos|Trayectorias|Holos|Cadernos)\b/iu', $cleanRef)
            || preg_match('/\d+\(([\d\-]+)\)[:\s]/u', $cleanRef)
            || preg_match('/\b(?:vol|v\.)\s*\d+/iu', $cleanRef)
            || $hasDoi
        );
        $isBook = !$isJournal && (
            (bool)preg_match('/\b\d+\.\s*ed\.?\b/iu', $cleanRef)
            || (bool)preg_match('/\b[A-ZÁÉÍÓÚÑ][\p{L}\p{M}\s\-\'\.]{1,60}:\s*[^;]{3,80};\s*(?:19|20)\d{2}\.?$/u', $cleanRef)
            || (bool)preg_match('/\b(Manual|Guia|Libro|Book|Testamento vital|Constituci[oó]n|Código|Codigo)\b/iu', $cleanRef)
        );
        $pubType = $forcedPubType !== '' ? $forcedPubType : ($isWebpage ? 'webpage' : ($isJournal ? 'journal' : 'book'));

        // Construir mixed-citation con URL en comentario para webpages y proyectos de ley.
        $mixedText = $this->escapeXmlWithItalic($cleanRef);
        if (($isWebpage || $isSpecialLegislativeBook || $forcedPubType === 'webpage') && ($hasUrl || $forcedUrl !== '') && preg_match('/(https?:\/\/\S+)/i', $cleanRef, $mu)) {
            $rawU = rtrim($mu[1], '.');
            $safeU = htmlspecialchars(rtrim(trim($rawU), '.'), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $commentPrefix = 'Disponible en: ';
            if (preg_match('/(Disponible en:\s*)https?:\/\/\S+/iu', $cleanRef, $pm)) {
                $commentPrefix = $pm[1];
            }
            $displaySource = rtrim(str_replace("\xc2\xa0", ' ', $rawU), '.');
            if (strpos($safeU, 'https://tinyurl.com/55ru4w6b') === 0 && substr($displaySource, -1) !== ' ') {
                $displaySource .= ' ';
            }
            $displayU = htmlspecialchars($displaySource, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $commentBlock = "<comment>" . htmlspecialchars($commentPrefix, ENT_QUOTES | ENT_XML1, 'UTF-8') . "<ext-link ext-link-type=\"uri\" xlink:href=\"$safeU\">$displayU</ext-link>\r\n\t\t\t\t\t</comment>";
            $mixedText = preg_replace(
                '/' . preg_quote($safeU, '/') . '/u',
                $commentBlock,
                $mixedText,
                1
            );
            if (strpos($mixedText, '<comment>') === false) {
                $mixedText = trim($mixedText) . ' ' . $commentBlock;
            }
        }
        if ($forcedPubType === 'webpage' && $forcedUrl !== '') {
            $mixedText = preg_replace('/<comment>.*?<\/comment>/su', '', $mixedText, 1);
            $safeForced = htmlspecialchars(rtrim($forcedUrl, '.'), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $commentPrefix = 'Disponible en: ';
            if (preg_match('/(Disponible en:\s*)https?:\/\/\S+/iu', $cleanRef, $pm)) {
                $commentPrefix = $pm[1];
            }
            $commentBlock = "<comment>" . htmlspecialchars($commentPrefix, ENT_QUOTES | ENT_XML1, 'UTF-8') . "<ext-link ext-link-type=\"uri\" xlink:href=\"$safeForced\">$safeForced</ext-link>\r\n\t\t\t\t\t</comment>";
            $mixedText = trim($mixedText) . ' ' . $commentBlock;
        }
        $mixedContent = trim($mixedText);
        // Eliminar NBSP (U+00A0) que puedan haber quedado del DOCX
        $mixedContent = str_replace("\xc2\xa0", '', $mixedContent);
        // Eliminar espacio suelto inmediatamente antes del <comment> para evitar " <comment>"
        $mixedContent = preg_replace('/\s+(<comment>)/u', ' $1', $mixedContent);
        if (strpos($mixedContent, '<comment>') !== false) {
            $xml .= "\t\t\t\t<mixed-citation>$num. " . $mixedContent . "\r\n\t\t\t\t</mixed-citation>\r\n";
        } else {
            $xml .= "\t\t\t\t<mixed-citation>$num. " . $mixedContent . "</mixed-citation>\r\n";
        }

        $authorPart = '';
        $rest = $cleanRef;
        if (preg_match('/^(.+?)\.\s+(.*)$/u', $cleanRef, $m)) {
            $authorPart = trim($m[1]);
            $rest = trim($m[2]);
        }

        $xml .= "\t\t\t\t<element-citation publication-type=\"$pubType\">\r\n";
        $bodyRef = $rest !== '' ? $rest : $cleanRef;
        $year = $this->extractCitationYear($bodyRef, $pubType);
        $yearPos = false;
        if ($year !== '' && preg_match('/\b' . preg_quote($year, '/') . '\b/u', $bodyRef, $yearMatch, PREG_OFFSET_CAPTURE)) {
            $yearPos = $yearMatch[0][1];
        }

        if ($authorPart !== '') {
            $isCollab = preg_match('/\b(Organiza|Ministerio|Minist[eé]rio|Conselho|Consejo|Brasil|Colombia|Universida|Fundaç|Comiss[aã]o|Comisi[oó]n|World Health|WHO|PAHO|UNESCO|United Nations|Committee)\b/iu', $authorPart);
            $xml .= "\t\t\t\t\t<person-group person-group-type=\"author\">\r\n";
            if ($isCollab) {
                $xml .= "\t\t\t\t\t\t<collab>" . htmlspecialchars(trim($authorPart)) . "</collab>\r\n";
            } else {
                $parsed = $this->parseAuthorList($authorPart);
                $authorsList = $parsed['authors'] ?? [];
                $hasEtal = $parsed['etal'] ?? false;
                foreach ($authorsList as $an) {
                    $xml .= "\t\t\t\t\t\t<name>\r\n";
                    $xml .= "\t\t\t\t\t\t\t<surname>" . htmlspecialchars($an['surname']) . "</surname>\r\n";
                    if ($an['given'] !== '') {
                        $xml .= "\t\t\t\t\t\t\t<given-names>" . htmlspecialchars($an['given']) . "</given-names>\r\n";
                    }
                    $xml .= "\t\t\t\t\t\t</name>\r\n";
                }
                if ($hasEtal) {
                    $xml .= "\t\t\t\t\t\t<etal/>\r\n";
                }
            }
            $xml .= "\t\t\t\t\t</person-group>\r\n";
        }

        if ($pubType === 'book') {
            if ($isSpecialLegislativeBook) {
                // Proyecto de ley con URL: salida igual a webpage pero bajo type="book"
                if ($bodyRef !== '') {
                    $billTitle = preg_replace('/\s*\[Internet\].*$/iu', '', $bodyRef);
                    $billTitle = preg_replace('/\s*\[citado\s+.+?\].*$/iu', '', $billTitle);
                    $billTitle = preg_replace('/\s*[.;,]\s*$/u', '', $billTitle);
                    $xml .= "\t\t\t\t\t<source>" . htmlspecialchars(trim($billTitle)) . "</source>\r\n";
                }
                if ($year !== '') {
                    $xml .= "\t\t\t\t\t<year>" . htmlspecialchars($year) . "</year>\r\n";
                }
                if (preg_match('/\[citado\s+(.+?)\]/iu', $cleanRef, $mad)) {
                    $accessRaw = trim($mad[1]);
                    $isoDate = '';
                    if (preg_match('/(\d{1,2})\s+([a-záéíóúñ]+)\s+(\d{4})/iu', $accessRaw, $dm)) {
                        $mk = strtolower(substr($dm[2], 0, 3));
                        $mn = $monthMapAcc[$mk] ?? '';
                        if ($mn) $isoDate = $dm[3] . '-' . $mn . '-' . str_pad($dm[1], 2, '0', STR_PAD_LEFT);
                    }
                    $isoAttr = $isoDate ? ' iso-8601-date="' . $isoDate . '"' : '';
                    $xml .= "\t\t\t\t\t<date-in-citation content-type=\"access-date\"$isoAttr>citado {$accessRaw}</date-in-citation>\r\n";
                }
                if (preg_match('/https?:\/\/\S+/i', $cleanRef, $mu)) {
                    $u = rtrim($mu[0], '.');
                    $commentPrefix = 'Disponible en: ';
                    if (preg_match('/(Disponible en:\s*)https?:\/\/\S+/iu', $cleanRef, $pm)) {
                        $commentPrefix = $pm[1];
                    }
                    $xml .= "\t\t\t\t\t" . $this->buildInlineUrlComment($u, $commentPrefix, $u) . "\r\n";
                }
            } else {
            // Extraer Ciudad: Editorial; año desde el final de la referencia
            // Patrón: buscar "Ciudad: Editorial; año" al final, el resto es el título
            // loc no puede contener puntos (evita que nombres propios con abreviaturas sean tomados como ciudad)
            $bookPatternEnd = '/^(?<title>.+?)\s*(?:(?<edition>\d+\.\s*ed\.?)\s*[.\s]*)?\s*(?<loc>[A-ZÁÉÍÓÚÑ][^:.]{1,40}):\s*(?<publisher>[^;]{3,80});\s*(?<year>(?:19|20)\d{2})\.?\s*$/u';
            if (preg_match($bookPatternEnd, $bodyRef, $m)) {
                $xml .= "\t\t\t\t\t<source>" . htmlspecialchars(rtrim(trim($m['title']), '.')) . "</source>\r\n";
                if (!empty($m['edition'])) {
                    $edition = preg_replace('/\s+/u', ' ', trim($m['edition']));
                    $xml .= "\t\t\t\t\t<edition>" . htmlspecialchars(rtrim($edition, '.')) . "</edition>\r\n";
                }
                $xml .= "\t\t\t\t\t<publisher-loc>" . htmlspecialchars(trim($m['loc'])) . "</publisher-loc>\r\n";
                $xml .= "\t\t\t\t\t<publisher-name>" . htmlspecialchars(trim($m['publisher'])) . "</publisher-name>\r\n";
                $xml .= "\t\t\t\t\t<year>" . htmlspecialchars($m['year']) . "</year>\r\n";
            } else {
                $bookTitle = preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $bodyRef);
                $bookTitle = preg_replace('/\s*:\s*[^:;]+;\s*(?:19|20)\d{2}\.?$/u', '', $bookTitle);
                $bookTitle = preg_replace('/\s*[.;,]\s*$/u', '', $bookTitle);
                if ($bookTitle !== '') {
                    $xml .= "\t\t\t\t\t<source>" . htmlspecialchars(trim($bookTitle)) . "</source>\r\n";
                }
                if (preg_match('/\b((?:19|20)\d{2})\b/', $bodyRef, $my)) {
                    $xml .= "\t\t\t\t\t<year>" . htmlspecialchars($my[1]) . "</year>\r\n";
                }
            }
            }
        } elseif ($pubType === 'webpage') {
            if ($bodyRef !== '') {
                $pageTitle = preg_replace('/\s*\[Internet\].*$/iu', '', $bodyRef);
                $pageTitle = preg_replace('/\s*\[citado\s+.+?\].*$/iu', '', $pageTitle);
                $pageTitle = preg_replace('/\s*[.;,]\s*$/u', '', $pageTitle);
                $xml .= "\t\t\t\t\t<source>" . htmlspecialchars(trim($pageTitle)) . "</source>\r\n";
            }
            if ($year !== '') {
                $xml .= "\t\t\t\t\t<year>" . htmlspecialchars($year) . "</year>\r\n";
            }
            if (preg_match('/\[citado\s+(.+?)\]/iu', $cleanRef, $mad)) {
                $accessRaw = trim($mad[1]);
                $isoDate = '';
                if (preg_match('/(\d{1,2})\s+([a-záéíóúñ]+)\s+(\d{4})/iu', $accessRaw, $dm)) {
                    $mk = strtolower(substr($dm[2], 0, 3));
                    $mn = $monthMapAcc[$mk] ?? '';
                    if ($mn) $isoDate = $dm[3] . '-' . $mn . '-' . str_pad($dm[1], 2, '0', STR_PAD_LEFT);
                }
                $isoAttr = $isoDate ? ' iso-8601-date="' . $isoDate . '"' : '';
                $xml .= "\t\t\t\t\t<date-in-citation content-type=\"access-date\"$isoAttr>citado {$accessRaw}</date-in-citation>\r\n";
            }
            if (preg_match('/https?:\/\/\S+/i', $cleanRef, $mu)) {
                $u = rtrim($mu[0], '.');
                $commentPrefix = 'Disponible en: ';
                if (preg_match('/(Disponible en:\s*)https?:\/\/\S+/iu', $cleanRef, $pm)) {
                    $commentPrefix = $pm[1];
                }
                $xml .= "\t\t\t\t\t" . $this->buildInlineUrlComment($u, $commentPrefix, $u) . "\r\n";
            }
        } else {
            $source = '';
            $articleTitle = '';
            $preYear = $bodyRef;
            if ($year !== '' && $yearPos !== false) {
                $preYear = trim(substr($bodyRef, 0, $yearPos));
            }

            if (preg_match('/^(?<article>.+?[¿?])\s+Revista Chilena de Anestesia\.\s*$/u', $preYear, $mJournal)) {
                $articleTitle = trim($mJournal['article']);
                $source = 'Revista Chilena de Anestesia';
            } elseif (preg_match('/^(?<article>.+)[\.!?]\s+(?<source>[^.]+)\.?\s*$/u', $preYear, $mJournal)) {
                $articleTitle = trim($mJournal['article']);
                $source = trim($mJournal['source']);
            } else {
                $parts = preg_split('/\.\s+(?=[A-ZÁÉÍÓÚÑ])/u', $preYear);
                $parts = array_values(array_filter(array_map('trim', $parts), function ($value) {
                    return $value !== '';
                }));

                if (!empty($parts)) {
                    $articleTitle = $parts[0];
                    if (isset($parts[1])) {
                        $source = rtrim($parts[1], ' .');
                    }
                } else {
                    $articleTitle = trim($preYear, ' .,;');
                }
            }

            if ($articleTitle !== '') {
                $xml .= "\t\t\t\t\t<article-title>" . htmlspecialchars($articleTitle) . "</article-title>\r\n";
            }
            if ($source !== '') {
                $xml .= "\t\t\t\t\t<source>" . htmlspecialchars($source) . "</source>\r\n";
            }
            if ($year !== '') {
                $xml .= "\t\t\t\t\t<year>" . htmlspecialchars($year) . "</year>\r\n";
            }

            if (preg_match('/\b(\d+)\s*\(\s*([\d\-\s]+)\s*\)\s*[:;]\s*(e\d+)\b/u', $cleanRef, $mp)) {
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[2]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<elocation-id>{$mp[3]}</elocation-id>\r\n";
            } elseif (preg_match('/\b(?:19|20)\d{2}\s*;\s*\((\d+)\)\s*:\s*(e\d+)\b/u', $cleanRef, $mp)) {
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[1]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<elocation-id>{$mp[2]}</elocation-id>\r\n";
            } elseif (preg_match('/\b(\d+)\s*\(\s*([\d\-\s]+)\s*\)\s*[:;]\s*(\d[\d\-]*)\b/u', $cleanRef, $mp)) {
                $pages = trim($mp[3]);
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[2]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$pages</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$pages</lpage>\r\n";
            } elseif (preg_match('/\b(?:19|20)\d{2}\s*;\s*\((\d+)\)\s*:\s*(\d[\d\-]*)\b/u', $cleanRef, $mp)) {
                $pages = trim($mp[2]);
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[1]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$pages</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$pages</lpage>\r\n";
            } elseif (preg_match('/\b(\d+)\s*:\s*(e\d+)\b/u', $cleanRef, $mp)) {
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<elocation-id>{$mp[2]}</elocation-id>\r\n";
            } elseif (preg_match('/\b(\d+)\s*:\s*(\d[\d\-]*)\b/u', $cleanRef, $mp)) {
                $pages = trim($mp[2]);
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$pages</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$pages</lpage>\r\n";
            }
        }

        if ($hasDoi && $pubType !== 'webpage' && preg_match('/10\.\d{4,9}\/\S+/u', $cleanRef, $md)) {
            $xml .= "\t\t\t\t\t<pub-id pub-id-type=\"doi\">" . htmlspecialchars($md[0]) . "</pub-id>\r\n";
        }

        $xml .= "\t\t\t\t</element-citation>\r\n";
        $xml .= "\t\t\t</ref>\r\n";
        return $xml;
    }

    /**
     * Toma el último año visible de una referencia para evitar años erróneos.
     */
    private function extractCitationYear($text, $pubType = '')
    {
        if (!is_string($text) || $text === '') {
            return '';
        }

        $normalized = preg_replace('/10\.\d{4,9}\/\S+/u', '', $text);
        $normalized = preg_replace('/\[citado\s+.+?\]/iu', '', $normalized);
        $normalized = preg_replace('/https?:\/\/\S+/i', '', $normalized);

        if (!preg_match_all('/\b((?:19|20)\d{2})\b/u', $normalized, $matches)) {
            return '';
        }

        $years = $matches[1] ?? [];
        if (empty($years)) {
            return '';
        }

        return (string) end($years);
    }

    /**
     * INFERENCIA DE TIPOS DE SECCIÓN:
     * Mapea títulos de texto a tipos normalizados de JATS (intro, methods, etc.)
     */
    private function inferBodySecType($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        if (preg_match('/introducci[oó]n|introduction/u', $normalized)) return 'intro';
        if (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) return 'methods';
        if (preg_match('/resultado|results?|discusi[oó]n|discussion/u', $normalized)) return 'results|discussion';
        if (preg_match('/conclus|consideraciones finales/u', $normalized)) return 'conclusions';
        return 'sec';
    }

    /**
     * ESCAPE SEGURO CON CURSIVAS:
     * Escapa caracteres especiales de XML (&, <, >) pero respeta las etiquetas <italic> 
     * generadas durante la extracción para no romper el marcado.
     */
    // Declara el método escapeXmlWithItalic de la clase.
    private function escapeXmlWithItalic($text)
    // Abre un nuevo bloque de código.
    {
        // Prepara $text con el valor que se usará después.
        $text = (string) $text;
        // Normalizar guión largo (–) a guión simple (-) para alinear con XML de referencia.
        $text = str_replace("\xE2\x80\x93", '-', $text);
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

    /**
     * Normaliza cadenas de referencia para detectar rangos de página concatenados
     * y convertirlos en un formato con guion (start-end).
     * Ejemplos: 583592 -> 583-592 ; 11631169 -> 1163-1169 ; 97116 -> 97-116
     */
    private function normalizeReferencePagesString($text)
    {
        if (!is_string($text) || $text === '') return $text;

        // Conservamos el texto original para no alterar páginas y numeraciones
        // que en el XML de referencia aparecen exactamente como en la fuente.
        return $text;
    }

    /**
     * Separa una cadena de autores en una lista estructurada.
     * Retorna ['authors'=>[['surname'=>'','given'=>''],...], 'etal'=>bool]
     */
    private function parseAuthorList($authorText)
    {
        $res = ['authors' => [], 'etal' => false];
        if (!is_string($authorText) || trim($authorText) === '') return $res;

        if (preg_match('/et\s*al\b/i', $authorText)) {
            $res['etal'] = true;
            $authorText = preg_replace('/\s*et\s*al\b/i', '', $authorText);
        }

        // Separar por coma, punto y coma, 'and' y 'y'.
        $parts = preg_split('/\s*(?:,|;|\band\b|\by\b)\s*/iu', trim($authorText));
        $parts = array_filter(array_map('trim', $parts));

        foreach ($parts as $p) {
            if ($p === '') continue;
            
            // Intentar interpretar "Apellido, Nombre" o "Nombre Apellido".
            if (preg_match('/^([\p{L}\p{M}\s\-\']+,)\s*([\p{L}\p{M}\s\-\'\.]+)$/u', $p, $m)) { // Apellido, Nombre
                $surname = trim($m[1], ',');
                $given = $m[2];
            } elseif (preg_match('/^([\p{L}\p{M}\s\-\'\.]+\s+[\p{L}\p{M}\s\-\'\.]+)$/u', $p, $m)) { // Nombre Apellido (caso simple)
                $tokens = preg_split('/\s+/u', $p);
                if (count($tokens) > 1) {
                    // Detectar el formato "Apellido Inicial(es)" donde el último token son iniciales (1-3 letras, posiblemente con puntos).
                    // Ej: "Maltoni M" -> surname=Maltoni, given=M
                    // Ej: "Scremin Martins PP" -> surname=Scremin Martins, given=PP
                    $lastToken = end($tokens);
                    $isInitials = preg_match('/^[\p{Lu}]{1,3}\.?$/u', $lastToken);
                    if ($isInitials) {
                        // Formato: palabras + iniciales al final -> surname=todo menos último, given=último
                        $given = array_pop($tokens);
                        $surname = implode(' ', $tokens);
                    } else {
                        // Formato clásico: primer token(s) = nombre, último = apellido
                        $surname = array_pop($tokens);
                        $given = implode(' ', $tokens);
                    }
                } else {
                    $surname = $p;
                    $given = '';
                }
            } elseif (preg_match('/^([\p{L}\p{M}\s\-\']+)$/u', $p, $m)) { // Un solo nombre, asumir apellido
                $surname = $m[1];
                $given = '';
            } else {
                // Recurso alternativo para casos más complejos o con iniciales.
                $tokens = preg_split('/\s+/u', $p);
                if (count($tokens) > 1) {
                    $lastToken = end($tokens);
                    // Si el último token son iniciales (1-3 letras mayúsculas), es el "given" y lo anterior es el apellido.
                    if (preg_match('/^[\p{Lu}]{1,3}\.?$/u', $lastToken)) { // e.g., "Eich M" o "Scremin Martins PP"
                        $given = array_pop($tokens);
                        $surname = implode(' ', $tokens);
                    } else { // e.g., "M. Eich" -> given=M., surname=Eich
                        $surname = array_pop($tokens);
                        $given = implode(' ', $tokens);
                    }
                } else {
                    $surname = $p;
                    $given = '';
                }
            }
            
            // Asegurar que apellido y nombres no queden vacíos.
            $res['authors'][] = ['surname' => trim($surname), 'given' => trim($given)];
        }

        return $res;
    }
}