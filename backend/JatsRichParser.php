<?php
/**
 * Parser especializado para JATS/XML ricos en figuras y tablas.
 * Extrae metadatos útiles para la UI y preserva los nodos <fig> y <table-wrap>
 * manteniendo intactos los estilos CSS, atributos inline y nodos internos.
 */
class JatsRichParser
{
    /**
     * Mapea y extrae los metadatos de un string XML JATS a un array asociativo.
     * Recorre el árbol DOM del documento y carga cada campo de metadato reconocido.
     *
     * @param string $xmlContent  Contenido XML completo del artículo JATS.
     * @return array              Array asociativo con todos los metadatos extraídos.
     */
    public function parseXmlToMeta(string $xmlContent): array
    {
        // Inicializa el array de metadatos con valores vacíos por defecto.
        // Cada clave corresponde a un campo del estándar SPS / JATS.
        $meta = [
            'lang'                => 'es',    // Idioma principal del artículo
            'sps'                 => 'sps-1.9', // Versión del esquema SciELO Publishing Schema
            'journalTitle'        => '',      // Título completo de la revista
            'journalAbbrev'       => '',      // Título abreviado de la revista
            'journalIdPublisher'  => '',      // Identificador de la revista asignado por el publisher
            'issn_ppub'           => '',      // ISSN de la versión impresa
            'issn_epub'           => '',      // ISSN de la versión electrónica
            'publisher'           => '',      // Nombre del editor
            'doi'                 => '',      // DOI del artículo
            'articleIdOther'      => '',      // Identificador alternativo del artículo (pub-id-type="other")
            'articleTitle'        => '',      // Título principal del artículo (español)
            'articleTitleEn'      => '',      // Título traducido al inglés
            'authors'             => [],      // Lista de autores con nombre, ORCID y afiliaciones
            'affiliations'        => [],      // Textos completos de cada afiliación
            'affiliations_email'  => [],      // Emails extraídos de cada afiliación
            'abstractEs'          => '',      // Resumen en español
            'abstractEn'          => '',      // Abstract en inglés
            'kwdsEs'              => [],      // Palabras clave en español
            'kwdsEn'              => [],      // Keywords en inglés
            'funding'             => [],      // Fuentes de financiamiento
            'fundingStatement'    => '',      // Declaración de financiamiento en texto libre
            'conflict'            => '',      // Declaración de conflicto de intereses
            'contributions'       => [],      // Contribuciones autorales (CRediT)
            'references'          => [],      // Lista de referencias bibliográficas
            'tableWraps'          => [],      // XML crudo de cada <table-wrap>
            'figures'             => [],      // Datos estructurados de cada <fig>
            'figCount'            => '0',     // Cantidad de figuras
            'tableCount'          => '0',     // Cantidad de tablas
            'refCount'            => '0',     // Cantidad de referencias
            'pageCount'           => '1',     // Cantidad de páginas (valor por defecto: 1)
            'source'              => 'xml',   // Origen del documento ('xml' cuando viene de JATS)
            'volume'              => '',      // Volumen de la publicación
            'elocation-id'        => '',      // Identificador electrónico de página (e.g. e6032)
            'collectionYear'      => '',      // Año de la colección
            'received'            => '',      // Fecha de recepción del manuscrito
            'revised'             => '',      // Fecha de revisión del manuscrito
            'accepted'            => '',      // Fecha de aceptación del manuscrito
            'pubdate'             => ''       // Fecha de publicación electrónica
        ];

        // Activa el manejo interno de errores para que libxml no los imprima en pantalla.
        libxml_use_internal_errors(true);

        // Instancia el DOM y carga el XML. Si falla, devuelve el array con valores vacíos.
        $dom = new DOMDocument();
        if (@$dom->loadXML($xmlContent) === false) return $meta;

        // Crea el objeto XPath para realizar consultas sobre el árbol DOM.
        $xpath = new DOMXPath($dom);

        // -----------------------------------------------------------------------
        // METADATOS DE LA REVISTA (<journal-meta>)
        // Se usan consultas local-name() para ignorar prefijos de namespace.
        // -----------------------------------------------------------------------

        // Título completo de la revista.
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-title-group"]/*[local-name()="journal-title"]')->item(0);
        if ($node) $meta['journalTitle'] = trim($node->textContent);

        // Título abreviado de la revista.
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-title-group"]/*[local-name()="abbrev-journal-title"]')->item(0);
        if ($node) $meta['journalAbbrev'] = trim($node->textContent);

        // Identificador de la revista según el publisher.
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-id" and @journal-id-type="publisher-id"]')->item(0);
        if ($node) $meta['journalIdPublisher'] = trim($node->textContent);

        // ISSN de la versión impresa (ppub).
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="issn" and @pub-type="ppub"]')->item(0);
        if ($node) $meta['issn_ppub'] = trim($node->textContent);

        // ISSN de la versión electrónica (epub).
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="issn" and @pub-type="epub"]')->item(0);
        if ($node) $meta['issn_epub'] = trim($node->textContent);

        // -----------------------------------------------------------------------
        // IDENTIFICADORES Y TÍTULOS DEL ARTÍCULO (<article-meta>)
        // -----------------------------------------------------------------------

        // DOI del artículo.
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="doi"]')->item(0);
        if ($node) $meta['doi'] = trim($node->textContent);

        // Identificador alternativo del artículo (pub-id-type="other"), e.g. número editorial.
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="other"]')->item(0);
        if ($node) $meta['articleIdOther'] = trim($node->textContent);

        // Título principal del artículo.
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="article-title"]')->item(0);
        if ($node) $meta['articleTitle'] = trim($node->textContent);

        // Título traducido al inglés (dentro de <trans-title-group>).
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="trans-title-group"]/*[local-name()="trans-title"]')->item(0);
        if ($node) $meta['articleTitleEn'] = trim($node->textContent);

        // -----------------------------------------------------------------------
        // DATOS DE PUBLICACIÓN
        // -----------------------------------------------------------------------

        // Número de volumen de la publicación.
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="volume"]')->item(0);
        if ($node) $meta['volume'] = trim($node->textContent);

        // Identificador electrónico de página (e.g. "e6032").
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="elocation-id"]')->item(0);
        if ($node) $meta['elocation-id'] = trim($node->textContent);

        // -----------------------------------------------------------------------
        // FECHAS DEL HISTORIAL EDITORIAL
        // Función auxiliar que extrae una fecha (día/mes/año) de un nodo <date>
        // identificado por su atributo date-type dentro de <history>.
        // Retorna un string "DD MM YYYY" o null si no existe el nodo.
        // -----------------------------------------------------------------------
        $helperParseDate = function($xpath, $type) {
            // Busca el nodo <date date-type="$type"> dentro de <history>.
            $dNode = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="history"]/*[local-name()="date" and @date-type="' . $type . '"]')->item(0);
            if ($dNode) {
                // Extrae los subnodos <day>, <month> y <year>.
                $day   = $xpath->query('.//*[local-name()="day"]',   $dNode)->item(0);
                $month = $xpath->query('.//*[local-name()="month"]', $dNode)->item(0);
                $year  = $xpath->query('.//*[local-name()="year"]',  $dNode)->item(0);
                if ($year) {
                    // Rellena con cero a la izquierda si el día o mes tienen un solo dígito.
                    $dayStr   = $day   ? str_pad(trim($day->textContent),   2, '0', STR_PAD_LEFT) : '01';
                    $monthStr = $month ? str_pad(trim($month->textContent), 2, '0', STR_PAD_LEFT) : '01';
                    $yearStr  = trim($year->textContent);
                    return "$dayStr $monthStr $yearStr";
                }
            }
            return null; // El nodo no existe en el documento.
        };

        // Fecha de recepción del manuscrito.
        $received = $helperParseDate($xpath, 'received');
        if ($received) $meta['received'] = $received;

        // Fecha de revisión: acepta tanto "revised" como "rev-recd" (SPS admite ambos).
        $revised = $helperParseDate($xpath, 'revised') ?: $helperParseDate($xpath, 'rev-recd');
        if ($revised) $meta['revised'] = $revised;

        // Fecha de aceptación del manuscrito.
        $accepted = $helperParseDate($xpath, 'accepted');
        if ($accepted) $meta['accepted'] = $accepted;

        // -----------------------------------------------------------------------
        // FECHA DE PUBLICACIÓN ELECTRÓNICA (<pub-date date-type="pub">)
        // -----------------------------------------------------------------------
        $pubNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="pub"]')->item(0);
        if ($pubNode) {
            // Extrae los componentes de la fecha del nodo encontrado.
            $day   = $xpath->query('.//*[local-name()="day"]',   $pubNode)->item(0);
            $month = $xpath->query('.//*[local-name()="month"]', $pubNode)->item(0);
            $year  = $xpath->query('.//*[local-name()="year"]',  $pubNode)->item(0);
            if ($year) {
                $dayStr   = $day   ? str_pad(trim($day->textContent),   2, '0', STR_PAD_LEFT) : '01';
                $monthStr = $month ? str_pad(trim($month->textContent), 2, '0', STR_PAD_LEFT) : '01';
                $yearStr  = trim($year->textContent);
                $meta['pubdate'] = "$dayStr $monthStr $yearStr";
            }
        }

        // -----------------------------------------------------------------------
        // AÑO DE COLECCIÓN (<pub-date date-type="collection">)
        // Representa el año de la edición/colección donde se publica el artículo.
        // -----------------------------------------------------------------------
        $collNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="collection"]')->item(0);
        if ($collNode) {
            $year = $xpath->query('.//*[local-name()="year"]', $collNode)->item(0);
            if ($year) $meta['collectionYear'] = trim($year->textContent);
        }

        // -----------------------------------------------------------------------
        // AFILIACIONES (<aff>)
        // Construye el mapa $affIdToIndex para relacionar el id XML ("aff1", "aff2"...)
        // con el índice numérico del array $meta['affiliations'].
        // -----------------------------------------------------------------------
        $affIdToIndex = []; // Mapa: id del nodo aff → índice en el array de afiliaciones.

        $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
        foreach ($affs as $a) {
            if (!($a instanceof DOMElement)) continue;

            // Prefiere el nodo <institution content-type="original"> para obtener el texto completo.
            // Si no existe ese nodo, usa el textContent completo del <aff>.
            $origNode = $xpath->query('.//*[local-name()="institution" and @content-type="original"]', $a)->item(0);
            $inst = $origNode ? trim($origNode->textContent) : trim($a->textContent);

            // Asigna un índice numérico 1-based al nuevo registro de afiliación.
            $affIndex = count($meta['affiliations']) + 1;
            $meta['affiliations'][] = $inst;

            // Extrae el email embebido en la afiliación, si existe.
            $emailNode = $xpath->query('.//*[local-name()="email"]', $a)->item(0);
            $meta['affiliations_email'][] = $emailNode ? trim($emailNode->textContent) : '';

            // Registra la correspondencia id → índice para usar luego en los autores.
            $aid = $a->getAttribute('id');
            if ($aid) $affIdToIndex[$aid] = $affIndex;
        }

        // -----------------------------------------------------------------------
        // AUTORES (<contrib contrib-type="author">)
        // Por cada contribuyente de tipo "author" extrae: nombre, ORCID y afiliaciones.
        // -----------------------------------------------------------------------
        $contribs = $xpath->query('//*[local-name()="contrib" and @contrib-type="author"]');
        foreach ($contribs as $c) {
            if (!($c instanceof DOMElement)) continue;

            // Extrae el nombre compuesto de <given-names> y <surname>.
            $given   = $xpath->query('.//*[local-name()="name"]/*[local-name()="given-names"]', $c)->item(0);
            $surname = $xpath->query('.//*[local-name()="name"]/*[local-name()="surname"]',     $c)->item(0);
            $name = trim((($given ? $given->textContent . ' ' : '') . ($surname ? $surname->textContent : '')));

            // Si no hay <name>, intenta tomar el texto de <collab> (autor institucional).
            if ($name === '') {
                $collab = $xpath->query('.//collab', $c)->item(0);
                $name = $collab ? trim($collab->textContent) : '';
            }

            // Extrae el ORCID del nodo <contrib-id contrib-id-type="orcid">.
            $orcidN = $xpath->query('.//*[local-name()="contrib-id" and @contrib-id-type="orcid"]', $c)->item(0);
            $orcid  = $orcidN ? trim($orcidN->textContent) : '';

            // Recopila los rids de las afiliaciones referenciadas por este autor.
            $affRefs = [];
            $xrefs = $xpath->query('.//*[local-name()="xref" and @ref-type="aff"]', $c);
            if ($xrefs && $xrefs->length) {
                foreach ($xrefs as $xr) {
                    if (!($xr instanceof DOMElement)) continue;
                    // Intenta obtener el rid o ref del atributo; si no, lo infiere del texto numérico.
                    $rid = $xr->getAttribute('rid') ?: $xr->getAttribute('ref') ?: '';
                    if ($rid === '') {
                        $txt = trim($xr->textContent);
                        if (preg_match('/(\d+)/', $txt, $m)) $rid = $m[1];
                    }
                    if ($rid !== '') $affRefs[] = $rid;
                }
            }

            // Convierte los rids string ("aff1") a índices numéricos usando el mapa creado antes.
            $affNumeric = [];
            foreach ($affRefs as $r) {
                $key = ltrim((string)$r, '#'); // Elimina el '#' si el rid lo incluye.
                if (isset($affIdToIndex[$key])) $affNumeric[] = $affIdToIndex[$key];
                else $affNumeric[] = $r; // Si no está en el mapa, guarda el valor original.
            }

            // Agrega el autor con sus datos al array de autores.
            $meta['authors'][] = ['name' => $name, 'orcid' => $orcid, 'aff' => $affNumeric];
        }

        // -----------------------------------------------------------------------
        // RESÚMENES
        // Se lee solo el contenido del primer <p>, excluyendo el <title> (ej: "RESUMEN ").
        // -----------------------------------------------------------------------

        // Resumen en español (primer <abstract> sin atributo de idioma).
        $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
        if ($abs) {
            $pAbs = $xpath->query('.//*[local-name()="p"]', $abs)->item(0);
            $meta['abstractEs'] = $pAbs ? trim($pAbs->textContent) : trim($abs->textContent);
        }

        // Abstract en inglés (<trans-abstract>).
        $absEn = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="trans-abstract"]')->item(0);
        if ($absEn) {
            $pAbsEn = $xpath->query('.//*[local-name()="p"]', $absEn)->item(0);
            $meta['abstractEn'] = $pAbsEn ? trim($pAbsEn->textContent) : trim($absEn->textContent);
        }

        // -----------------------------------------------------------------------
        // PALABRAS CLAVE (<kwd-group>)
        // Separa las keywords según el atributo xml:lang del grupo.
        // -----------------------------------------------------------------------
        $kwdGroups = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group"]');
        foreach ($kwdGroups as $kg) {
            if (!($kg instanceof DOMElement)) continue;
            // Lee el idioma del grupo; si no está, asume español.
            $klang = $kg->getAttribute('xml:lang')
                  ?: $kg->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')
                  ?: 'es';
            $kwds = $xpath->query('.//*[local-name()="kwd"]', $kg);
            foreach ($kwds as $k) {
                // Clasifica cada keyword según el idioma del grupo.
                if ($klang === 'en') {
                    $meta['kwdsEn'][] = trim($k->textContent);
                } else {
                    $meta['kwdsEs'][] = trim($k->textContent);
                }
            }
        }

        // -----------------------------------------------------------------------
        // FIGURAS (<fig>)
        // Extrae id, label, caption, href del graphic y XML crudo de cada figura.
        // -----------------------------------------------------------------------
        $figs = $xpath->query('//*[local-name()="fig"]');
        foreach ($figs as $fg) {
            if (!($fg instanceof DOMElement)) continue;

            $fid     = $fg->getAttribute('id') ?: '';
            $label   = $xpath->query('.//label',   $fg)->item(0);
            $caption = $xpath->query('.//caption', $fg)->item(0);

            // Extrae el href del atributo xlink:href del elemento <graphic>.
            // Intenta primero el atributo sin namespace, luego con namespace xlink.
            $graphic = $xpath->query('.//*[local-name()="graphic"]', $fg)->item(0);
            $href = '';
            if ($graphic instanceof DOMElement) {
                $href = $graphic->getAttribute('xlink:href') ?: $graphic->getAttribute('href');
                if ($href === '') {
                    $href = $graphic->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: '';
                }
            }

            // Guarda la figura con todos sus datos, incluyendo el XML crudo serializado.
            $meta['figures'][] = [
                'id'      => $fid,
                'label'   => $label   ? trim($label->textContent)   : '',
                'caption' => $caption ? trim($caption->textContent) : '',
                'href'    => $href,
                'xml'     => $dom->saveXML($fg) // XML serializado del nodo completo
            ];
        }
        // Actualiza el contador de figuras con el total real encontrado.
        if (!empty($meta['figures'])) $meta['figCount'] = (string)count($meta['figures']);

        // -----------------------------------------------------------------------
        // TABLAS (<table-wrap>)
        // Extrae XML crudo y estructura interna (thead/tbody) con atributos de celda.
        // -----------------------------------------------------------------------
        $tables = $xpath->query('//*[local-name()="table-wrap"]');
        foreach ($tables as $t) {
            if (!($t instanceof DOMElement)) continue;

            // Serializa el XML crudo completo del <table-wrap> para uso posterior.
            $raw = $dom->saveXML($t);

            // Estructura inicial del registro de tabla.
            $structured = [
                'id'      => $t->getAttribute('id') ?: '',
                'label'   => '',
                'caption' => '',
                'thead'   => [],  // Filas de encabezado con datos estructurados de celda
                'tbody'   => [],  // Filas del cuerpo con datos estructurados de celda
                'xml'     => $raw // XML crudo del table-wrap completo
            ];

            // Extrae el label de la tabla (e.g. "Tabla 1").
            $lab = $xpath->query('.//*[local-name()="label"]', $t)->item(0);
            if ($lab) $structured['label'] = trim($lab->textContent);

            // Extrae el caption/title de la tabla.
            $cap = $xpath->query('.//*[local-name()="caption"]', $t)->item(0);
            if ($cap) {
                $tt = $xpath->query('.//*[local-name()="title"]', $cap)->item(0);
                $structured['caption'] = $tt ? trim($tt->textContent) : trim($cap->textContent);
            }

            // Procesa el nodo <table> interno para extraer filas y celdas.
            $tableNode = $xpath->query('.//*[local-name()="table"]', $t)->item(0);
            if ($tableNode instanceof DOMElement) {

                // --- Procesa filas del <thead> ---
                $thead = $xpath->query('.//*[local-name()="thead"]/*[local-name()="tr"]', $tableNode);
                foreach ($thead as $tr) {
                    $row = [];
                    foreach ($tr->childNodes as $cell) {
                        if (!($cell instanceof DOMElement)) continue;
                        // Extrae todos los atributos de la celda (colspan, rowspan, style, etc.).
                        $tag     = $cell->localName;
                        $text    = trim($cell->textContent);
                        $colspan = $cell->getAttribute('colspan') ?: '1';
                        $rowspan = $cell->getAttribute('rowspan') ?: '1';
                        $value   = $this->convertCellValue($text); // Normaliza valores numéricos
                        $attrs   = [];
                        if ($cell->hasAttributes()) {
                            foreach ($cell->attributes as $a) {
                                $attrs[$a->name] = $a->value;
                            }
                        }
                        $cellXml = $dom->saveXML($cell);
                        $row[] = [
                            'tag'     => $tag,
                            'text'    => $text,
                            'value'   => $value,
                            'colspan' => (int)$colspan,
                            'rowspan' => (int)$rowspan,
                            'attrs'   => $attrs,
                            'style'   => $cell->getAttribute('style'),
                            'xml'     => $cellXml
                        ];
                    }
                    $structured['thead'][] = $row;
                }

                // --- Procesa filas del <tbody> ---
                $tbody = $xpath->query('.//*[local-name()="tbody"]/*[local-name()="tr"]', $tableNode);

                if ($tbody->length === 0) {
                    // Fallback: si no hay <tbody> explícito, busca todos los <tr> que no
                    // sean hijos directos de <thead> para tratarlos como filas del cuerpo.
                    $tbody = $xpath->query('.//*[local-name()="tr"]', $tableNode);
                    $filtered = [];
                    foreach ($tbody as $tr) {
                        $parent = $tr->parentNode;
                        if ($parent && $parent->localName === 'thead') continue; // Omite los del thead
                        $filtered[] = $tr;
                    }
                    // Procesa las filas del cuerpo filtradas.
                    foreach ($filtered as $tr) {
                        $row = [];
                        foreach ($tr->childNodes as $cell) {
                            if (!($cell instanceof DOMElement)) continue;
                            $tag     = $cell->localName;
                            $text    = trim($cell->textContent);
                            $colspan = $cell->getAttribute('colspan') ?: '1';
                            $rowspan = $cell->getAttribute('rowspan') ?: '1';
                            $value   = $this->convertCellValue($text);
                            $attrs   = [];
                            if ($cell->hasAttributes()) {
                                foreach ($cell->attributes as $a) {
                                    $attrs[$a->name] = $a->value;
                                }
                            }
                            $cellXml = $dom->saveXML($cell);
                            $row[] = [
                                'tag'     => $tag,
                                'text'    => $text,
                                'value'   => $value,
                                'colspan' => (int)$colspan,
                                'rowspan' => (int)$rowspan,
                                'attrs'   => $attrs,
                                'style'   => $cell->getAttribute('style'),
                                'xml'     => $cellXml
                            ];
                        }
                        $structured['tbody'][] = $row;
                    }
                } else {
                    // Caso normal: <tbody> existe, procesa sus filas directamente.
                    foreach ($tbody as $tr) {
                        $row = [];
                        foreach ($tr->childNodes as $cell) {
                            if (!($cell instanceof DOMElement)) continue;
                            $tag     = $cell->localName;
                            $text    = trim($cell->textContent);
                            $colspan = $cell->getAttribute('colspan') ?: '1';
                            $rowspan = $cell->getAttribute('rowspan') ?: '1';
                            $value   = $this->convertCellValue($text);
                            $attrs   = [];
                            if ($cell->hasAttributes()) {
                                foreach ($cell->attributes as $a) {
                                    $attrs[$a->name] = $a->value;
                                }
                            }
                            $cellXml = $dom->saveXML($cell);
                            $row[] = [
                                'tag'     => $tag,
                                'text'    => $text,
                                'value'   => $value,
                                'colspan' => (int)$colspan,
                                'rowspan' => (int)$rowspan,
                                'attrs'   => $attrs,
                                'style'   => $cell->getAttribute('style'),
                                'xml'     => $cellXml
                            ];
                        }
                        $structured['tbody'][] = $row;
                    }
                }
            }

            // Guarda el XML crudo y la estructura de la tabla en los arrays de metadatos.
            $meta['tableWraps'][]            = $raw;
            $meta['tableWraps_structured'][] = $structured;
        }
        // Actualiza el contador de tablas con el total real encontrado.
        if (!empty($meta['tableWraps'])) $meta['tableCount'] = (string)count($meta['tableWraps']);

        // -----------------------------------------------------------------------
        // REFERENCIAS BIBLIOGRÁFICAS (<ref-list>)
        // Extrae el texto de cada referencia desde <mixed-citation> o el <ref> completo.
        // -----------------------------------------------------------------------
        $refs = $xpath->query(
            '//*[local-name()="ref-list"]//*[local-name()="ref"] | ' .
            '//*[local-name()="back"]//*[local-name()="ref-list"]//*[local-name()="ref"]'
        );
        foreach ($refs as $r) {
            if (!($r instanceof DOMElement)) continue;
            // Prefiere el contenido de <mixed-citation>; si no existe, usa el textContent del <ref>.
            $mc   = $xpath->query('.//mixed-citation', $r)->item(0);
            $text = $mc ? trim($mc->textContent) : trim($r->textContent);
            $meta['references'][] = ['id' => $r->getAttribute('id') ?: '', 'text' => $text];
        }
        // Actualiza el contador de referencias con el total real encontrado.
        if (!empty($meta['references'])) $meta['refCount'] = (string)count($meta['references']);

        // Guarda el XML original completo en los metadatos para uso posterior en mergeMetaIntoOriginalXml.
        $meta['originalXml'] = $xmlContent;

        return $meta;
    }

    /**
     * Normaliza el valor de una celda de tabla a su tipo PHP más apropiado.
     * Convierte cadenas numéricas a int o float; devuelve null para vacíos o guiones.
     *
     * @param string $text  Texto crudo de la celda.
     * @return int|float|string|null  Valor normalizado.
     */
    private function convertCellValue(string $text)
    {
        $t = trim($text);

        // Celda vacía o con solo un guion se trata como null (sin dato).
        if ($t === '' || $t === '-') return null;

        // Detecta si el valor es un porcentaje y elimina el símbolo para procesarlo.
        $isPercent = false;
        if (strpos($t, '%') !== false) {
            $isPercent = true;
            $t = str_replace('%', '', $t);
            $t = trim($t);
        }

        // Si el string contiene solo dígitos, puntos, comas, espacios y guiones,
        // intenta convertirlo a número.
        if (preg_match('/^[0-9\.\,\s\-]+$/', $t)) {

            // Caso 1: formato europeo con puntos como separadores de miles y coma decimal
            // Ejemplo: "1.234,56" → 1234.56
            if (preg_match('/\d+[\.\d]*,\d+$/', $t)) {
                $num = str_replace('.', '', $t);   // Quita separadores de miles
                $num = str_replace(',', '.', $num); // Convierte coma decimal a punto
                $val = (float)$num;

            // Caso 2: coma como separador decimal sin puntos (e.g. "3,14")
            } elseif (strpos($t, ',') !== false && strpos($t, '.') === false) {
                $num = str_replace(',', '.', $t);
                $val = (float)$num;

            // Caso 3: formato anglosajón o entero (e.g. "1.234" como mil doscientos treinta y cuatro)
            } else {
                $num = str_replace('.', '', $t);   // Quita separadores de miles
                $num = str_replace(' ', '', $num); // Quita espacios internos
                if ($num === '') return null;
                if (strpos($num, ',') !== false) $num = str_replace(',', '.', $num);
                // Determina si es flotante o entero según la presencia de punto decimal.
                if (strpos($num, '.') !== false) $val = (float)$num;
                else $val = (int)$num;
            }
            return $val;
        }

        // Si no es numérico, devuelve el texto original sin modificar.
        return $text;
    }

    /**
     * Fusiona los metadatos editados (array $meta) dentro del XML JATS original.
     * Estrategia quirúrgica: actualiza solo el <front> vía DOM y restaura el
     * <body> y <back> exactos del original para preservar estilos CSS, atributos
     * inline y toda la estructura de tablas y figuras intacta.
     *
     * @param string $originalXml  XML JATS original completo.
     * @param array  $meta         Array de metadatos con los valores actualizados por el editor.
     * @return string              XML JATS con el <front> actualizado y body/back preservados.
     */
    public function mergeMetaIntoOriginalXml(string $originalXml, array $meta): string
    {
        // -----------------------------------------------------------------------
        // PASO 1: AISLAMIENTO DE BODY Y BACK
        // Extrae el contenido interno de <body> y <back> como strings crudos
        // ANTES de que DOMDocument los procese, para restaurarlos al final.
        // Esto evita que DOM mute los estilos CSS o reformatee el XML de tablas.
        // -----------------------------------------------------------------------
        $bodyContent = '';
        $backContent = '';

        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $originalXml, $matches)) {
            $bodyContent = $matches[1];
        }
        if (preg_match('/<back\b[^>]*>(.*)<\/back>/is', $originalXml, $matches)) {
            $backContent = $matches[1];
        }

        // Carga el XML en DOM para poder modificar el <front> con precisión.
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;  // Preserva espacios para no alterar la indentación.
        $dom->formatOutput       = false; // No reformatea: mantiene el XML lo más fiel posible.
        if (@$dom->loadXML($originalXml) === false) return $originalXml;
        $xpath = new DOMXPath($dom);

        // -----------------------------------------------------------------------
        // HELPERS INTERNOS
        // Funciones auxiliares reutilizadas en múltiples puntos del método.
        // -----------------------------------------------------------------------

        // Helper: reemplaza el contenido textual de un nodo eliminando todos sus hijos
        // y agregando un nuevo nodo de texto. Si el texto está vacío, deja el nodo vacío.
        $setText = function($node, $text) {
            while ($node->firstChild) $node->removeChild($node->firstChild);
            if (trim((string)$text) !== '') $node->appendChild($node->ownerDocument->createTextNode($text));
        };

        // Helper: actualiza los subnodos <day>, <month> y <year> de un nodo de fecha
        // a partir de un string en formato "DD MM YYYY".
        $updateDateNode = function($dateNode, $dateValue) use ($dom, $xpath, $setText) {
            if (!$dateNode || empty($dateValue)) return;
            $parts = preg_split('/\s+/', trim($dateValue));
            if (count($parts) >= 3) {
                $dayVal   = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $monthVal = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                $yearVal  = $parts[2];

                $day   = $xpath->query('.//*[local-name()="day"]',   $dateNode)->item(0);
                if ($day)   $setText($day,   $dayVal);

                $month = $xpath->query('.//*[local-name()="month"]', $dateNode)->item(0);
                if ($month) $setText($month, $monthVal);

                $year  = $xpath->query('.//*[local-name()="year"]',  $dateNode)->item(0);
                if ($year)  $setText($year,  $yearVal);
            }
        };

        // Helper: crea un nodo de fecha completo con subnodos <day>, <month> y <year>
        // a partir de un string "DD MM YYYY" y atributos opcionales (e.g. date-type).
        // Devuelve null si el string no tiene al menos tres partes.
        $makeDateNode = function(string $tagName, string $dateValue, array $attrs = []) use ($dom) {
            $parts = preg_split('/\s+/', trim($dateValue));
            if (count($parts) < 3) return null;
            $node = $dom->createElement($tagName);
            foreach ($attrs as $k => $v) $node->setAttribute($k, $v);
            $day   = $dom->createElement('day',   str_pad($parts[0], 2, '0', STR_PAD_LEFT));
            $month = $dom->createElement('month', str_pad($parts[1], 2, '0', STR_PAD_LEFT));
            $year  = $dom->createElement('year',  $parts[2]);
            $node->appendChild($day);
            $node->appendChild($month);
            $node->appendChild($year);
            return $node;
        };

        // Helper: inserta $newNode como hijo de $parent justo antes del primer elemento
        // cuyo localName coincida con alguno de los tags de $beforeTags (lista de anclas).
        // Si no se encuentra ninguna ancla, el nodo se agrega al final del padre.
        $insertBefore = function($parent, $newNode, array $beforeTags) use ($dom) {
            foreach ($parent->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, $beforeTags, true)) {
                    $parent->insertBefore($newNode, $child);
                    return;
                }
            }
            $parent->appendChild($newNode); // Fallback: al final si no hay ancla.
        };

        // Referencia al nodo <article-meta> para usarla en todos los inserts.
        $am = $xpath->query('//*[local-name()="article-meta"]')->item(0);

        // -----------------------------------------------------------------------
        // ACTUALIZACIÓN DEL FRONT-MATTER
        // -----------------------------------------------------------------------

        // --- Título del artículo ---
        if (!empty($meta['articleTitle'])) {
            $t = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="article-title"]')->item(0);
            if ($t) $setText($t, $meta['articleTitle']);
        }

        // --- DOI ---
        // Actualiza el nodo existente o lo crea si no existe.
        if (!empty($meta['doi'])) {
            $n = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="doi"]')->item(0);
            if ($n) {
                $setText($n, $meta['doi']);
            } elseif ($am) {
                // Crea el nodo <article-id pub-id-type="doi"> y lo agrega a article-meta.
                $nid = $dom->createElement('article-id', $meta['doi']);
                $nid->setAttribute('pub-id-type', 'doi');
                $am->appendChild($nid);
            }
        }

        // --- article-id pub-id-type="other" (identificador editorial) ---
        // Actualiza el nodo existente o lo inserta en la posición correcta según el orden SPS.
        if (!empty($meta['articleIdOther'])) {
            $n = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="other"]')->item(0);
            if ($n) {
                $setText($n, $meta['articleIdOther']);
            } elseif ($am) {
                $nid = $dom->createElement('article-id', $meta['articleIdOther']);
                $nid->setAttribute('pub-id-type', 'other');
                // Posiciona el nodo inmediatamente después del article-id doi si existe.
                $doiNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="doi"]')->item(0);
                if ($doiNode && $doiNode->nextSibling) {
                    $am->insertBefore($nid, $doiNode->nextSibling);
                } else {
                    // Fallback: inserta antes del primer elemento de la lista de anclas SPS.
                    $insertBefore($am, $nid, ['article-categories', 'title-group', 'contrib-group', 'aff', 'author-notes', 'pub-date', 'volume', 'elocation-id', 'history', 'permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
                }
            }
        }

        // --- Volumen ---
        if (!empty($meta['volume'])) {
            $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="volume"]')->item(0);
            if ($node) {
                $setText($node, $meta['volume']);
            } elseif ($am) {
                $newNode = $dom->createElement('volume', $meta['volume']);
                $insertBefore($am, $newNode, ['elocation-id', 'issue', 'history', 'permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
            }
        }

        // --- elocation-id ---
        if (!empty($meta['elocation-id'])) {
            $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="elocation-id"]')->item(0);
            if ($node) {
                $setText($node, $meta['elocation-id']);
            } elseif ($am) {
                $newNode = $dom->createElement('elocation-id', $meta['elocation-id']);
                $insertBefore($am, $newNode, ['history', 'permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
            }
        }

        // --- Fecha de publicación electrónica (<pub-date date-type="pub">) ---
        if (!empty($meta['pubdate'])) {
            $dateNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="pub"]')->item(0);
            if ($dateNode) {
                $updateDateNode($dateNode, $meta['pubdate']); // Actualiza los subnodos existentes.
            } elseif ($am) {
                // Crea el nodo <pub-date> completo con atributos SPS obligatorios.
                $newPd = $makeDateNode('pub-date', $meta['pubdate'], ['date-type' => 'pub', 'publication-format' => 'electronic']);
                if ($newPd) $insertBefore($am, $newPd, ['pub-date', 'volume', 'elocation-id', 'history', 'permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
            }
        }

        // --- Año de colección (<pub-date date-type="collection">) ---
        if (!empty($meta['collectionYear'])) {
            $collNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="collection"]')->item(0);
            if ($collNode) {
                // Solo actualiza el <year>, ya que la colección no lleva día ni mes.
                $year = $xpath->query('.//*[local-name()="year"]', $collNode)->item(0);
                if ($year) $setText($year, $meta['collectionYear']);
            } elseif ($am) {
                // Crea el nodo <pub-date date-type="collection"> con solo el año.
                $newColl = $dom->createElement('pub-date');
                $newColl->setAttribute('date-type',           'collection');
                $newColl->setAttribute('publication-format',  'electronic');
                $newColl->appendChild($dom->createElement('year', $meta['collectionYear']));
                // Lo inserta justo después de pub-date pub si existe, para mantener el orden SPS.
                $pubDateNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="pub"]')->item(0);
                if ($pubDateNode && $pubDateNode->nextSibling) {
                    $am->insertBefore($newColl, $pubDateNode->nextSibling);
                } else {
                    $insertBefore($am, $newColl, ['volume', 'elocation-id', 'history', 'permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
                }
            }
        }

        // -----------------------------------------------------------------------
        // HISTORIAL EDITORIAL (<history>)
        // Actualiza o crea los nodos <date> para received, revised y accepted.
        // Si no existe <history> y hay al menos una fecha, crea el nodo padre.
        // -----------------------------------------------------------------------
        $histNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="history"]')->item(0);

        // Crea el nodo <history> si no existe y hay al menos una fecha disponible.
        if (!$histNode && $am && (!empty($meta['received']) || !empty($meta['revised']) || !empty($meta['accepted']))) {
            $histNode = $dom->createElement('history');
            $insertBefore($am, $histNode, ['permissions', 'abstract', 'trans-abstract', 'kwd-group', 'counts']);
        }

        if ($histNode) {
            // Fecha de recepción: actualiza el nodo existente o lo crea.
            if (!empty($meta['received'])) {
                $dn = $xpath->query('.//*[local-name()="date" and @date-type="received"]', $histNode)->item(0);
                if ($dn) { $updateDateNode($dn, $meta['received']); }
                else {
                    $nd = $makeDateNode('date', $meta['received'], ['date-type' => 'received']);
                    if ($nd) $histNode->appendChild($nd);
                }
            }

            // Fecha de revisión: acepta "revised" o "rev-recd"; si crea, usa "rev-recd".
            if (!empty($meta['revised'])) {
                $dn = $xpath->query('.//*[local-name()="date" and (@date-type="revised" or @date-type="rev-recd")]', $histNode)->item(0);
                if ($dn) { $updateDateNode($dn, $meta['revised']); }
                else {
                    $nd = $makeDateNode('date', $meta['revised'], ['date-type' => 'rev-recd']);
                    if ($nd) $histNode->appendChild($nd);
                }
            }

            // Fecha de aceptación.
            if (!empty($meta['accepted'])) {
                $dn = $xpath->query('.//*[local-name()="date" and @date-type="accepted"]', $histNode)->item(0);
                if ($dn) { $updateDateNode($dn, $meta['accepted']); }
                else {
                    $nd = $makeDateNode('date', $meta['accepted'], ['date-type' => 'accepted']);
                    if ($nd) $histNode->appendChild($nd);
                }
            }

            // Fecha de publicación dentro del historial (estilo documentos tipo 6032 sin cabecera).
            if (!empty($meta['pubdate'])) {
                $dn = $xpath->query('.//*[local-name()="date" and @date-type="pub"]', $histNode)->item(0);
                if ($dn) { $updateDateNode($dn, $meta['pubdate']); }
                else {
                    $nd = $makeDateNode('date', $meta['pubdate'], ['date-type' => 'pub']);
                    if ($nd) $histNode->appendChild($nd);
                }
            }
        }

        // -----------------------------------------------------------------------
        // RESUMEN EN ESPAÑOL (<abstract>)
        // Solo actualiza el contenido del <p>, preservando el <title> ("RESUMEN ").
        // Si no existe el nodo, crea toda la estructura.
        // -----------------------------------------------------------------------
        $absText = trim((string)($meta['abstractEs'] ?? ''));
        if ($absText !== '') {
            // Busca el abstract sin atributo de idioma (español) o el primero disponible.
            $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract" and not(@xml:lang) and not(@*[local-name()="lang"])]')->item(0);
            if (!$abs) $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
            if ($abs) {
                $pNode = $xpath->query('.//*[local-name()="p"]', $abs)->item(0);
                $currentText = $pNode ? trim($pNode->textContent) : trim($abs->textContent);
                // Solo actualiza si el contenido realmente cambió, para no tocar el nodo innecesariamente.
                if ($currentText !== $absText) {
                    if ($pNode) $setText($pNode, $absText); else $setText($abs, $absText);
                }
            } elseif ($am) {
                // Crea el nodo <abstract> completo con <title> y <p>.
                $newAbs = $dom->createElement('abstract');
                $titleEl = $dom->createElement('title', 'RESUMEN ');
                $pEl     = $dom->createElement('p', $absText);
                $newAbs->appendChild($titleEl);
                $newAbs->appendChild($pEl);
                $insertBefore($am, $newAbs, ['trans-abstract', 'kwd-group', 'counts']);
            }
        }

        // -----------------------------------------------------------------------
        // ABSTRACT EN INGLÉS (<trans-abstract xml:lang="en">)
        // Misma lógica que el español: actualiza el <p> o crea la estructura.
        // -----------------------------------------------------------------------
        $absEnText = trim((string)($meta['abstractEn'] ?? ''));
        if ($absEnText !== '') {
            $absEn = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="trans-abstract"]')->item(0);
            if ($absEn) {
                $pNode = $xpath->query('.//*[local-name()="p"]', $absEn)->item(0);
                $currentText = $pNode ? trim($pNode->textContent) : trim($absEn->textContent);
                if ($currentText !== $absEnText) {
                    if ($pNode) $setText($pNode, $absEnText); else $setText($absEn, $absEnText);
                }
            } elseif ($am) {
                $newAbsEn = $dom->createElement('trans-abstract');
                $newAbsEn->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');
                $titleEl = $dom->createElement('title', 'ABSTRACT ');
                $pEl     = $dom->createElement('p', $absEnText);
                $newAbsEn->appendChild($titleEl);
                $newAbsEn->appendChild($pEl);
                $insertBefore($am, $newAbsEn, ['kwd-group', 'counts']);
            }
        }

        // -----------------------------------------------------------------------
        // PALABRAS CLAVE EN ESPAÑOL (<kwd-group xml:lang="es">)
        // Compara las keywords existentes con las nuevas; solo reescribe si cambiaron.
        // -----------------------------------------------------------------------
        if (!empty($meta['kwdsEs']) && is_array($meta['kwdsEs'])) {
            $kg = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group" and (@xml:lang="es" or not(@xml:lang))]')->item(0);
            if ($kg) {
                $existing    = $xpath->query('.//*[local-name()="kwd"]', $kg);
                $existingVals = [];
                foreach ($existing as $e) $existingVals[] = trim($e->textContent);
                $newVals = array_map('strval', array_values($meta['kwdsEs']));
                // Solo reescribe los nodos <kwd> si el contenido cambió.
                if ($existingVals !== $newVals) {
                    foreach ($existing as $e) $e->parentNode->removeChild($e);
                    foreach ($meta['kwdsEs'] as $kw) {
                        $el = $dom->createElement('kwd', $kw);
                        $kg->appendChild($el);
                    }
                }
            } elseif ($am) {
                // Crea el grupo de palabras clave en español si no existe.
                $newKg = $dom->createElement('kwd-group');
                $newKg->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'es');
                $titleEl = $dom->createElement('title', 'PALABRAS CLAVES:');
                $newKg->appendChild($titleEl);
                foreach ($meta['kwdsEs'] as $kw) {
                    $el = $dom->createElement('kwd', $kw);
                    $newKg->appendChild($el);
                }
                $insertBefore($am, $newKg, ['counts']);
            }
        }

        // -----------------------------------------------------------------------
        // KEYWORDS EN INGLÉS (<kwd-group xml:lang="en">)
        // Misma lógica que el español.
        // -----------------------------------------------------------------------
        if (!empty($meta['kwdsEn']) && is_array($meta['kwdsEn'])) {
            $kg = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group" and @xml:lang="en"]')->item(0);
            if ($kg) {
                $existing    = $xpath->query('.//*[local-name()="kwd"]', $kg);
                $existingVals = [];
                foreach ($existing as $e) $existingVals[] = trim($e->textContent);
                $newVals = array_map('strval', array_values($meta['kwdsEn']));
                if ($existingVals !== $newVals) {
                    foreach ($existing as $e) $e->parentNode->removeChild($e);
                    foreach ($meta['kwdsEn'] as $kw) {
                        $el = $dom->createElement('kwd', $kw);
                        $kg->appendChild($el);
                    }
                }
            } elseif ($am) {
                $newKg = $dom->createElement('kwd-group');
                $newKg->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');
                $titleEl = $dom->createElement('title', 'Keywords:');
                $newKg->appendChild($titleEl);
                foreach ($meta['kwdsEn'] as $kw) {
                    $el = $dom->createElement('kwd', $kw);
                    $newKg->appendChild($el);
                }
                $insertBefore($am, $newKg, ['counts']);
            }
        }

        // -----------------------------------------------------------------------
        // AFILIACIONES (<aff>)
        // Reconstruye el nodo solo si el texto cambió respecto al original.
        // Si no cambió, salta el nodo para preservar su estructura XML exacta.
        // -----------------------------------------------------------------------
        if (!empty($meta['affiliations']) && is_array($meta['affiliations'])) {
            $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
            $i = 0;
            foreach ($affs as $a) {
                if (!isset($meta['affiliations'][$i])) { $i++; continue; }

                // Compara el texto actual del nodo con el valor en $meta.
                // Si son iguales, no se toca el nodo (preserva su formato y subnodos).
                $origInstNode = $xpath->query('.//*[local-name()="institution" and @content-type="original"]', $a)->item(0);
                $origInstText = $origInstNode ? trim($origInstNode->textContent) : trim($a->textContent);
                if ($origInstText === trim((string)$meta['affiliations'][$i])) { $i++; continue; }

                // Obtiene el label (número de afiliación) antes de vaciar el nodo.
                $labelNode = $xpath->query('.//*[local-name()="label"]', $a)->item(0);
                $labelText = $labelNode ? trim($labelNode->textContent) : (string)($i + 1);

                // Vacía completamente el nodo <aff> para reconstruirlo desde cero.
                while ($a->firstChild) $a->removeChild($a->firstChild);

                // Restaura el nodo <label> con el número de afiliación.
                if ($labelText !== '') {
                    $lbl = $dom->createElement('label', $labelText);
                    $a->appendChild($lbl);
                }

                $affText = $meta['affiliations'][$i];

                // Extrae el email embebido en el texto de afiliación si existe.
                $email = '';
                if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $affText, $emMatches)) {
                    $email = $emMatches[1];
                }

                // Agrega el nodo <institution content-type="original"> con el texto completo.
                $origNode = $dom->createElement('institution', $affText);
                $origNode->setAttribute('content-type', 'original');
                $a->appendChild($origNode);

                // Intenta extraer el nombre de la institución principal (Universidad, etc.)
                // para crear el nodo <institution content-type="normalized">.
                $orgname = '';
                if (preg_match('/(Universidade[^.,;]+|Universidad[^.,;]+|University[^.,;]+)/u', $affText, $orgMatches)) {
                    $orgname = trim($orgMatches[1]);
                }
                if ($orgname !== '') {
                    $normNode = $dom->createElement('institution', $orgname);
                    $normNode->setAttribute('content-type', 'normalized');
                    $a->appendChild($normNode);
                }

                // Intenta extraer divisiones organizacionales para orgdiv1 y orgdiv2
                // buscando Departamento, Programa, Instituto y Facultad en el texto.
                $departmentMatches = [];
                if (preg_match_all('/(Departamento[^.;,]+)/iu', $affText, $mdept)) {
                    $departmentMatches = array_map('trim', $mdept[1]);
                }
                $programMatches = [];
                if (preg_match_all('/(Programa[^.;,]+)/iu', $affText, $mprog)) {
                    $programMatches = array_map('trim', $mprog[1]);
                }
                $instituteMatches = [];
                if (preg_match_all('/(Instituto[^.;,]+)/iu', $affText, $minst)) {
                    $instituteMatches = array_map('trim', $minst[1]);
                }
                $facMatches = [];
                if (preg_match_all('/(Facultad[^.;,]+|Faculdade[^.;,]+|Faculty[^.;,]+)/iu', $affText, $mfac)) {
                    $facMatches = array_map('trim', $mfac[1]);
                }

                // Construye la lista de divisiones en orden de jerarquía descendente.
                $orgdivs = [];
                if (!empty($facMatches))      $orgdivs[] = $facMatches[0];
                if (!empty($instituteMatches)) $orgdivs[] = $instituteMatches[0];
                if (!empty($departmentMatches)) $orgdivs[] = $departmentMatches[0];
                if (!empty($programMatches))   $orgdivs[] = $programMatches[0];
                $orgdivs = array_values(array_unique($orgdivs));

                // Agrega los nodos orgdiv1 y orgdiv2 si existen.
                if (isset($orgdivs[0])) {
                    $od1 = $dom->createElement('institution', $orgdivs[0]);
                    $od1->setAttribute('content-type', 'orgdiv1');
                    $a->appendChild($od1);
                }
                if (isset($orgdivs[1])) {
                    $od2 = $dom->createElement('institution', $orgdivs[1]);
                    $od2->setAttribute('content-type', 'orgdiv2');
                    $a->appendChild($od2);
                }

                // Nodo <institution content-type="orgname"> con el nombre de la universidad.
                if ($orgname !== '') {
                    $nameNode = $dom->createElement('institution', $orgname);
                    $nameNode->setAttribute('content-type', 'orgname');
                    $a->appendChild($nameNode);
                }

                // Limpia el texto para extraer ciudad y país: quita email y puntuación final.
                $cleanedText = rtrim(trim($affText), ' .;,!?');
                if ($email !== '') {
                    $cleanedText = trim(str_replace($email, '', $cleanedText), ' .;,!?');
                }

                // Divide en partes por coma o punto y coma para detectar ciudad y país.
                $parts = array_map('trim', preg_split('/[,;]/u', $cleanedText));
                $country     = '';
                $countryCode = '';
                $city        = '';

                // Mapa de nombres de país (en español, inglés y portugués) a códigos ISO 3166-1 alpha-2.
                $countryMap = [
                    'argentina'      => 'AR',
                    'brasil'         => 'BR',
                    'brazil'         => 'BR',
                    'chile'          => 'CL',
                    'colombia'       => 'CO',
                    'méxico'         => 'MX',
                    'mexico'         => 'MX',
                    'españa'         => 'ES',
                    'spain'          => 'ES',
                    'uruguay'        => 'UY',
                    'paraguay'       => 'PY',
                    'perú'           => 'PE',
                    'peru'           => 'PE',
                    'ecuador'        => 'EC',
                    'venezuela'      => 'VE',
                    'bolivia'        => 'BO',
                    'cuba'           => 'CU',
                    'costa rica'     => 'CR',
                    'panamá'         => 'PA',
                    'panama'         => 'PA',
                    'puerto rico'    => 'PR',
                    'ee.uu.'         => 'US',
                    'usa'            => 'US',
                    'estados unidos' => 'US',
                    'united states'  => 'US',
                    'portugal'       => 'PT',
                    'italia'         => 'IT',
                    'italy'          => 'IT',
                    'francia'        => 'FR',
                    'france'         => 'FR',
                    'reino unido'    => 'GB',
                    'uk'             => 'GB',
                    'united kingdom' => 'GB',
                    'alemania'       => 'DE',
                    'germany'        => 'DE'
                ];

                // Intenta detectar país y ciudad tomando las últimas dos partes del texto.
                if (count($parts) >= 2) {
                    $possibleCountry = array_pop($parts); // Última parte → posible país
                    $possibleCity    = array_pop($parts); // Penúltima → posible ciudad
                    $lowerCountry    = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        $country     = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                        $city        = $possibleCity;
                    } else {
                        // No reconoció el país; restaura las partes para el siguiente intento.
                        $parts[] = $possibleCity;
                        $parts[] = $possibleCountry;
                    }
                }

                // Segunda pasada: intenta con solo la última parte restante.
                if ($country === '' && count($parts) >= 1) {
                    $possibleCountry = array_pop($parts);
                    $lowerCountry    = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        $country     = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                    } else {
                        $parts[] = $possibleCountry;
                    }
                }

                // Intenta detectar la ciudad a partir de la última parte restante,
                // descartando partes que parezcan nombres de institución o unidad académica.
                if ($city === '' && count($parts) >= 1) {
                    $lastPart = array_pop($parts);
                    if (!preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $lastPart)) {
                        $city = $lastPart;
                    } else {
                        $parts[] = $lastPart; // No es ciudad, devuelve al array.
                    }
                }

                // Agrega el nodo <addr-line><city> si se detectó la ciudad.
                if ($city !== '') {
                    $addrNode = $dom->createElement('addr-line');
                    $cityNode = $dom->createElement('city', $city);
                    $addrNode->appendChild($cityNode);
                    $a->appendChild($addrNode);
                }

                // Agrega el nodo <country country="XX"> si se detectó el país.
                if ($country !== '') {
                    $countryNode = $dom->createElement('country', $country);
                    if ($countryCode !== '') {
                        $countryNode->setAttribute('country', $countryCode);
                    }
                    $a->appendChild($countryNode);
                }

                // Agrega el nodo <email> si se extrajo un correo de la afiliación.
                if ($email !== '') {
                    $emailNode = $dom->createElement('email', $email);
                    $a->appendChild($emailNode);
                }

                $i++;
            }
        }

        // -----------------------------------------------------------------------
        // AUTORES (<contrib contrib-type="author">)
        // Actualiza nombre, ORCID y nombre desglosado por autor.
        // -----------------------------------------------------------------------
        if (!empty($meta['authors']) && is_array($meta['authors'])) {
            $contribs = $xpath->query('//*[local-name()="contrib" and @contrib-type="author"]');
            $i = 0;
            foreach ($contribs as $c) {
                if (!isset($meta['authors'][$i])) { $i++; continue; }
                $aMeta = $meta['authors'][$i];

                // Actualiza los subnodos <given-names> y <surname> dentro de <name>.
                $nameNode = $xpath->query('.//*[local-name()="name"]', $c)->item(0);
                if ($nameNode && !empty($aMeta['name'])) {
                    // Separa el nombre: todos los tokens salvo el último son "given-names".
                    $parts   = preg_split('/\s+/', trim($aMeta['name']));
                    $surname = array_pop($parts);  // Último token → apellido
                    $given   = implode(' ', $parts); // Resto → nombres

                    // Actualiza o crea el nodo <given-names>.
                    $gn = $xpath->query('.//*[local-name()="given-names"]', $nameNode)->item(0);
                    if (!$gn) { $gn = $dom->createElement('given-names'); $nameNode->appendChild($gn); }
                    $setText($gn, $given);

                    // Actualiza o crea el nodo <surname>.
                    $sn = $xpath->query('.//*[local-name()="surname"]', $nameNode)->item(0);
                    if (!$sn) { $sn = $dom->createElement('surname'); $nameNode->appendChild($sn); }
                    $setText($sn, $surname);
                }

                // Actualiza el ORCID solo si cambió (comparando sin el prefijo de URL).
                if (!empty($aMeta['orcid'])) {
                    $cid = $xpath->query('.//*[local-name()="contrib-id" and @contrib-id-type="orcid"]', $c)->item(0);
                    // Función para extraer el ORCID bare (sin prefijo https://orcid.org/).
                    $orcidVal = trim($aMeta['orcid']);
                    $bare = function($s) { return strtolower(preg_replace('#^https?://orcid\.org/#i', '', trim((string)$s))); };
                    if ($cid) {
                        // Solo actualiza si el valor cambió (evita mutación innecesaria del XML).
                        if ($bare($cid->textContent) !== $bare($orcidVal)) $setText($cid, $orcidVal);
                    } else {
                        // Crea el nodo <contrib-id contrib-id-type="orcid"> si no existe.
                        $nid = $dom->createElement('contrib-id', $orcidVal);
                        $nid->setAttribute('contrib-id-type', 'orcid');
                        $c->appendChild($nid);
                    }
                }
                $i++;
            }
        }

        // -----------------------------------------------------------------------
        // SERIALIZACIÓN FINAL DEL XML MODIFICADO
        // -----------------------------------------------------------------------

        // Serializa el DOM a string. En este punto el <front> está actualizado
        // pero el <body> y <back> pueden haber sido mutados por DOM.
        $xml = $dom->saveXML();

        // -----------------------------------------------------------------------
        // PASO 2: RESTAURACIÓN DEL ENCABEZADO ORIGINAL
        // DOMDocument reformatea el DOCTYPE y reordena los namespaces de <article>.
        // Sustituimos todo lo que precede al cierre de la etiqueta <article> con
        // la cabecera original exacta para preservar el PI XML y la declaración DTD.
        // -----------------------------------------------------------------------
        if (preg_match('/<article\b[^>]*>/s', $originalXml, $om, PREG_OFFSET_CAPTURE)) {
            $origHeader = substr($originalXml, 0, $om[0][1] + strlen($om[0][0]));
            $xml = preg_replace('/^.*?<article\b[^>]*>/s', addcslashes($origHeader, '$\\'), $xml, 1);
        }

        // -----------------------------------------------------------------------
        // PASO 3: RESTAURACIÓN DE BODY Y BACK
        // Pisa los bloques <body> y <back> que DOM pudo haber alterado con los
        // strings crudos originales extraídos en el paso 1.
        // -----------------------------------------------------------------------
        if (!empty($bodyContent)) {
            $xml = preg_replace(
                '/(<body\b[^>]*>)(.*)(<\/body>)/is',
                '$1' . addcslashes($bodyContent, '$') . '$3',
                $xml
            );
        }
        if (!empty($backContent)) {
            $xml = preg_replace(
                '/(<back\b[^>]*>)(.*)(<\/back>)/is',
                '$1' . addcslashes($backContent, '$') . '$3',
                $xml
            );
        }

        // -----------------------------------------------------------------------
        // PASO 4: NORMALIZACIÓN FINAL
        // -----------------------------------------------------------------------

        // Reemplaza cualquier <br/> o <br /> que pueda haber quedado en los metadatos
        // por <break/>, que es el elemento correcto en JATS/XML.
        $xml = preg_replace('/<br\s*\/?>/i', '<break/>', $xml);

        // Expande tags autocerrados que no deben serlo en HTML/JATS
        // (e.g. <td/> → <td></td>) para garantizar compatibilidad con parsers estrictos.
        $nonSelfClosing = ['td', 'th', 'tr', 'thead', 'tbody', 'table', 'bold', 'italic', 'p', 'title', 'label', 'abstract'];
        foreach ($nonSelfClosing as $tag) {
            $xml = preg_replace(
                '/<(' . preg_quote($tag, '/') . ')((?:\s[^>]*?)?)\s*\/>/is',
                '<$1$2></$1>',
                $xml
            );
        }

        // Preserva el espacio de cierre exacto del original (DOMDocument añade \n tras </article>).
        // Toma el trailing whitespace del XML original y lo aplica al resultado.
        $origTrailing = substr($originalXml, strlen(rtrim($originalXml)));
        $xml = rtrim($xml) . $origTrailing;

        return $xml;
    }
}