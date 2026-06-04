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
     */
    public function parseXmlToMeta(string $xmlContent): array
    {
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
            'affiliations_email' => [],
            'abstractEs' => '',
            'abstractEn' => '',
            'kwdsEs' => [],
            'kwdsEn' => [],
            'funding' => [],
            'fundingStatement' => '',
            'conflict' => '',
            'contributions' => [],
            'references' => [],
            'tableWraps' => [],
            'figures' => [],
            'figCount' => '0',
            'tableCount' => '0',
            'refCount' => '0',
            'pageCount' => '1',
            'source' => 'xml',
            'volume' => '',
            'elocation-id' => '',
            'collectionYear' => '',
            'received' => '',
            'revised' => '',
            'accepted' => '',
            'pubdate' => ''
        ];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadXML($xmlContent) === false) return $meta;
        $xpath = new DOMXPath($dom);

        // Journal meta (namespace-agnostic)
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-title-group"]/*[local-name()="journal-title"]')->item(0);
        if ($node) $meta['journalTitle'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-title-group"]/*[local-name()="abbrev-journal-title"]')->item(0);
        if ($node) $meta['journalAbbrev'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="journal-id" and @journal-id-type="publisher-id"]')->item(0);
        if ($node) $meta['journalIdPublisher'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="issn" and @pub-type="ppub"]')->item(0);
        if ($node) $meta['issn_ppub'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="journal-meta"]/*[local-name()="issn" and @pub-type="epub"]')->item(0);
        if ($node) $meta['issn_epub'] = trim($node->textContent);

        // Article ids and titles
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="doi"]')->item(0);
        if ($node) $meta['doi'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="article-title"]')->item(0);
        if ($node) $meta['articleTitle'] = trim($node->textContent);
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="trans-title-group"]/*[local-name()="trans-title"]')->item(0);
        if ($node) $meta['articleTitleEn'] = trim($node->textContent);

        // Volume
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="volume"]')->item(0);
        if ($node) $meta['volume'] = trim($node->textContent);

        // Elocation-id
        $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="elocation-id"]')->item(0);
        if ($node) $meta['elocation-id'] = trim($node->textContent);

        // Helper to parse date
        $helperParseDate = function($xpath, $type) {
            $dNode = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="history"]/*[local-name()="date" and @date-type="' . $type . '"]')->item(0);
            if ($dNode) {
                $day = $xpath->query('.//*[local-name()="day"]', $dNode)->item(0);
                $month = $xpath->query('.//*[local-name()="month"]', $dNode)->item(0);
                $year = $xpath->query('.//*[local-name()="year"]', $dNode)->item(0);
                if ($year) {
                    $dayStr = $day ? str_pad(trim($day->textContent), 2, '0', STR_PAD_LEFT) : '01';
                    $monthStr = $month ? str_pad(trim($month->textContent), 2, '0', STR_PAD_LEFT) : '01';
                    $yearStr = trim($year->textContent);
                    return "$dayStr $monthStr $yearStr";
                }
            }
            return null;
        };

        $received = $helperParseDate($xpath, 'received');
        if ($received) $meta['received'] = $received;

        $revised = $helperParseDate($xpath, 'revised') ?: $helperParseDate($xpath, 'rev-recd');
        if ($revised) $meta['revised'] = $revised;

        $accepted = $helperParseDate($xpath, 'accepted');
        if ($accepted) $meta['accepted'] = $accepted;

        // Pub-date
        $pubNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="pub"]')->item(0);
        if ($pubNode) {
            $day = $xpath->query('.//*[local-name()="day"]', $pubNode)->item(0);
            $month = $xpath->query('.//*[local-name()="month"]', $pubNode)->item(0);
            $year = $xpath->query('.//*[local-name()="year"]', $pubNode)->item(0);
            if ($year) {
                $dayStr = $day ? str_pad(trim($day->textContent), 2, '0', STR_PAD_LEFT) : '01';
                $monthStr = $month ? str_pad(trim($month->textContent), 2, '0', STR_PAD_LEFT) : '01';
                $yearStr = trim($year->textContent);
                $meta['pubdate'] = "$dayStr $monthStr $yearStr";
            }
        }
        
        // Collection Year
        $collNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="collection"]')->item(0);
        if ($collNode) {
            $year = $xpath->query('.//*[local-name()="year"]', $collNode)->item(0);
            if ($year) $meta['collectionYear'] = trim($year->textContent);
        }

        // Affiliations
        $affIdToIndex = [];
        $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
        foreach ($affs as $a) {
            if (!($a instanceof DOMElement)) continue;
            $origNode = $xpath->query('.//*[local-name()="institution" and @content-type="original"]', $a)->item(0);
            $inst = $origNode ? trim($origNode->textContent) : trim($a->textContent);
            $affIndex = count($meta['affiliations']) + 1;
            $meta['affiliations'][] = $inst;
            $emailNode = $xpath->query('.//*[local-name()="email"]', $a)->item(0);
            $meta['affiliations_email'][] = $emailNode ? trim($emailNode->textContent) : '';
            $aid = $a->getAttribute('id');
            if ($aid) $affIdToIndex[$aid] = $affIndex;
        }

        // Authors
        $contribs = $xpath->query('//*[local-name()="contrib" and @contrib-type="author"]');
        foreach ($contribs as $c) {
            if (!($c instanceof DOMElement)) continue;
            $given = $xpath->query('.//*[local-name()="name"]/*[local-name()="given-names"]', $c)->item(0);
            $surname = $xpath->query('.//*[local-name()="name"]/*[local-name()="surname"]', $c)->item(0);
            $name = trim((($given? $given->textContent.' ':'').($surname? $surname->textContent:'')));
            if ($name === '') {
                $collab = $xpath->query('.//collab', $c)->item(0);
                $name = $collab ? trim($collab->textContent) : '';
            }
            $orcidN = $xpath->query('.//*[local-name()="contrib-id" and @contrib-id-type="orcid"]', $c)->item(0);
            $orcid = $orcidN ? trim($orcidN->textContent) : '';
            $affRefs = [];
            $xrefs = $xpath->query('.//*[local-name()="xref" and @ref-type="aff"]', $c);
            if ($xrefs && $xrefs->length) {
                foreach ($xrefs as $xr) {
                    if (!($xr instanceof DOMElement)) continue;
                    $rid = $xr->getAttribute('rid') ?: $xr->getAttribute('ref') ?: '';
                    if ($rid === '') {
                        $txt = trim($xr->textContent);
                        if (preg_match('/(\d+)/', $txt, $m)) $rid = $m[1];
                    }
                    if ($rid !== '') $affRefs[] = $rid;
                }
            }
            $affNumeric = [];
            foreach ($affRefs as $r) {
                $key = ltrim((string)$r, '#');
                if (isset($affIdToIndex[$key])) $affNumeric[] = $affIdToIndex[$key];
                else $affNumeric[] = $r;
            }
            $meta['authors'][] = ['name' => $name, 'orcid' => $orcid, 'aff' => $affNumeric];
        }

        // Abstracts
        $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
        if ($abs) $meta['abstractEs'] = trim($abs->textContent);
        $absEn = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="trans-abstract"]')->item(0);
        if ($absEn) $meta['abstractEn'] = trim($absEn->textContent);

        // Keywords by language
        $kwdGroups = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group"]');
        foreach ($kwdGroups as $kg) {
            if (!($kg instanceof DOMElement)) continue;
            $klang = $kg->getAttribute('xml:lang') ?: $kg->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang') ?: 'es';
            $kwds = $xpath->query('.//*[local-name()="kwd"]', $kg);
            foreach ($kwds as $k) {
                if ($klang === 'en') {
                    $meta['kwdsEn'][] = trim($k->textContent);
                } else {
                    $meta['kwdsEs'][] = trim($k->textContent);
                }
            }
        }

        // Figures (raw xml + fields)
        $figs = $xpath->query('//*[local-name()="fig"]');
        foreach ($figs as $fg) {
            if (!($fg instanceof DOMElement)) continue;
            $fid = $fg->getAttribute('id') ?: '';
            $label = $xpath->query('.//label', $fg)->item(0);
            $caption = $xpath->query('.//caption', $fg)->item(0);
            $graphic = $xpath->query('.//*[local-name()="graphic"]', $fg)->item(0);
            $href = '';
            if ($graphic instanceof DOMElement) {
                $href = $graphic->getAttribute('xlink:href') ?: $graphic->getAttribute('href');
                if ($href === '') {
                    $href = $graphic->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: '';
                }
            }
            $meta['figures'][] = ['id' => $fid, 'label' => $label?trim($label->textContent):'', 'caption' => $caption?trim($caption->textContent):'', 'href' => $href, 'xml' => $dom->saveXML($fg)];
        }
        if (!empty($meta['figures'])) $meta['figCount'] = (string)count($meta['figures']);

        // Table-wraps (raw + structured)
        $tables = $xpath->query('//*[local-name()="table-wrap"]');
        foreach ($tables as $t) {
            if (!($t instanceof DOMElement)) continue;
            $raw = $dom->saveXML($t);
            $structured = ['id' => $t->getAttribute('id') ?: '', 'label' => '', 'caption' => '', 'thead' => [], 'tbody' => [], 'xml' => $raw];
            $lab = $xpath->query('.//*[local-name()="label"]', $t)->item(0);
            if ($lab) $structured['label'] = trim($lab->textContent);
            $cap = $xpath->query('.//*[local-name()="caption"]', $t)->item(0);
            if ($cap) {
                $tt = $xpath->query('.//*[local-name()="title"]', $cap)->item(0);
                $structured['caption'] = $tt ? trim($tt->textContent) : trim($cap->textContent);
            }
            
            $tableNode = $xpath->query('.//*[local-name()="table"]', $t)->item(0);
            if ($tableNode instanceof DOMElement) {
                // thead
                $thead = $xpath->query('.//*[local-name()="thead"]/*[local-name()="tr"]', $tableNode);
                foreach ($thead as $tr) {
                    $row = [];
                    foreach ($tr->childNodes as $cell) {
                        if (!($cell instanceof DOMElement)) continue;
                        $tag = $cell->localName;
                        $text = trim($cell->textContent);
                        $colspan = $cell->getAttribute('colspan') ?: '1';
                        $rowspan = $cell->getAttribute('rowspan') ?: '1';
                        $value = $this->convertCellValue($text);
                        $attrs = [];
                        if ($cell->hasAttributes()) {
                            foreach ($cell->attributes as $a) {
                                $attrs[$a->name] = $a->value;
                            }
                        }
                        $cellXml = $dom->saveXML($cell);
                        $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan, 'attrs' => $attrs, 'style' => $cell->getAttribute('style'), 'xml' => $cellXml];
                    }
                    $structured['thead'][] = $row;
                }
                // tbody
                $tbody = $xpath->query('.//*[local-name()="tbody"]/*[local-name()="tr"]', $tableNode);
                if ($tbody->length === 0) {
                    $tbody = $xpath->query('.//*[local-name()="tr"]', $tableNode);
                    $filtered = [];
                    foreach ($tbody as $tr) {
                        $parent = $tr->parentNode;
                        if ($parent && $parent->localName === 'thead') continue;
                        $filtered[] = $tr;
                    }
                    foreach ($filtered as $tr) {
                        $row = [];
                        foreach ($tr->childNodes as $cell) {
                            if (!($cell instanceof DOMElement)) continue;
                            $tag = $cell->localName;
                            $text = trim($cell->textContent);
                            $colspan = $cell->getAttribute('colspan') ?: '1';
                            $rowspan = $cell->getAttribute('rowspan') ?: '1';
                            $value = $this->convertCellValue($text);
                            $attrs = [];
                            if ($cell->hasAttributes()) {
                                foreach ($cell->attributes as $a) {
                                    $attrs[$a->name] = $a->value;
                                }
                            }
                            $cellXml = $dom->saveXML($cell);
                            $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan, 'attrs' => $attrs, 'style' => $cell->getAttribute('style'), 'xml' => $cellXml];
                        }
                        $structured['tbody'][] = $row;
                    }
                } else {
                    foreach ($tbody as $tr) {
                        $row = [];
                        foreach ($tr->childNodes as $cell) {
                            if (!($cell instanceof DOMElement)) continue;
                            $tag = $cell->localName;
                            $text = trim($cell->textContent);
                            $colspan = $cell->getAttribute('colspan') ?: '1';
                            $rowspan = $cell->getAttribute('rowspan') ?: '1';
                            $value = $this->convertCellValue($text);
                            $attrs = [];
                            if ($cell->hasAttributes()) {
                                foreach ($cell->attributes as $a) {
                                    $attrs[$a->name] = $a->value;
                                }
                            }
                            $cellXml = $dom->saveXML($cell);
                            $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan, 'attrs' => $attrs, 'style' => $cell->getAttribute('style'), 'xml' => $cellXml];
                        }
                        $structured['tbody'][] = $row;
                    }
                }
            }
            $meta['tableWraps'][] = $raw;
            $meta['tableWraps_structured'][] = $structured;
        }
        if (!empty($meta['tableWraps'])) $meta['tableCount'] = (string)count($meta['tableWraps']);

        // References
        $refs = $xpath->query('//*[local-name()="ref-list"]//*[local-name()="ref"] | //*[local-name()="back"]//*[local-name()="ref-list"]//*[local-name()="ref"]');
        foreach ($refs as $r) {
            if (!($r instanceof DOMElement)) continue;
            $mc = $xpath->query('.//mixed-citation', $r)->item(0);
            $text = $mc ? trim($mc->textContent) : trim($r->textContent);
            $meta['references'][] = ['id' => $r->getAttribute('id') ?: '', 'text' => $text];
        }
        if (!empty($meta['references'])) $meta['refCount'] = (string)count($meta['references']);

        $meta['originalXml'] = $xmlContent;
        return $meta;
    }

    /**
     * Normaliza los valores numéricos decimales, enteros o nulos de una celda.
     */
    private function convertCellValue(string $text)
    {
        $t = trim($text);
        if ($t === '' || $t === '-' ) return null;
        $isPercent = false;
        if (strpos($t, '%') !== false) {
            $isPercent = true;
            $t = str_replace('%', '', $t);
            $t = trim($t);
        }
        if (preg_match('/^[0-9\.\,\s\-]+$/', $t)) {
            if (preg_match('/\d+[\.\d]*,\d+$/', $t)) {
                $num = str_replace('.', '', $t);
                $num = str_replace(',', '.', $num);
                $val = (float)$num;
            } elseif (strpos($t, ',') !== false && strpos($t, '.') === false) {
                $num = str_replace(',', '.', $t);
                $val = (float)$num;
            } else {
                $num = str_replace('.', '', $t);
                $num = str_replace(' ', '', $num);
                if ($num === '') return null;
                if (strpos($num, ',') !== false) $num = str_replace(',', '.', $num);
                if (strpos($num, '.') !== false) $val = (float)$num;
                else $val = (int)$num;
            }
            return $val;
        }
        return $text;
    }

    /**
     * Hace el merge de metadatos modificados dentro del XML original.
     * Guarda el body y back nativos para evitar mutación o pérdida de estilos CSS en las tablas.
     */
    public function mergeMetaIntoOriginalXml(string $originalXml, array $meta): string
    {
        // 1. Aislamiento quirúrgico de los strings originales de body y back
        $bodyContent = '';
        $backContent = '';
        
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $originalXml, $matches)) {
            $bodyContent = $matches[1];
        }
        if (preg_match('/<back\b[^>]*>(.*)<\/back>/is', $originalXml, $matches)) {
            $backContent = $matches[1];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (@$dom->loadXML($originalXml) === false) return $originalXml;
        $xpath = new DOMXPath($dom);

        // Helper para nodos de texto
        $setText = function($node, $text) {
            while ($node->firstChild) $node->removeChild($node->firstChild);
            if (trim((string)$text) !== '') $node->appendChild($node->ownerDocument->createTextNode($text));
        };

        // Helper para fechas de historial técnico
        $updateDateNode = function($dateNode, $dateValue) use ($dom, $xpath, $setText) {
            if (!$dateNode || empty($dateValue)) return;
            $parts = preg_split('/\s+/', trim($dateValue));
            if (count($parts) >= 3) {
                $dayVal = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $monthVal = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                $yearVal = $parts[2];
                
                $day = $xpath->query('.//*[local-name()="day"]', $dateNode)->item(0);
                if ($day) $setText($day, $dayVal);
                
                $month = $xpath->query('.//*[local-name()="month"]', $dateNode)->item(0);
                if ($month) $setText($month, $monthVal);
                
                $year = $xpath->query('.//*[local-name()="year"]', $dateNode)->item(0);
                if ($year) $setText($year, $yearVal);
            }
        };

        // --- Actualización de Nodos en Front-Matter (<front>) ---

        // --- Remove redundant funding elements under article-meta ---
        $fundingNodes = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="funding-group"] | //*[local-name()="article-meta"]//*[local-name()="award-group"] | //*[local-name()="article-meta"]//*[local-name()="funding-source"] | //*[local-name()="article-meta"]//*[local-name()="funding-statement"]');
        foreach ($fundingNodes as $fnNode) {
            if ($fnNode->parentNode) {
                $fnNode->parentNode->removeChild($fnNode);
            }
        }

        if (!empty($meta['articleTitle'])) {
            $t = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="title-group"]/*[local-name()="article-title"]')->item(0);
            if ($t) $setText($t, $meta['articleTitle']);
        }
        if (!empty($meta['doi'])) {
            $n = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="article-id" and @pub-id-type="doi"]')->item(0);
            if ($n) $setText($n, $meta['doi']);
            else {
                $am = $xpath->query('//*[local-name()="article-meta"]')->item(0);
                if ($am) {
                    $nid = $dom->createElement('article-id', $meta['doi']);
                    $nid->setAttribute('pub-id-type','doi');
                    $am->appendChild($nid);
                }
            }
        }

        if (!empty($meta['volume'])) {
            $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="volume"]')->item(0);
            if ($node) $setText($node, $meta['volume']);
        }

        if (!empty($meta['elocation-id'])) {
            $node = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="elocation-id"]')->item(0);
            if ($node) $setText($node, $meta['elocation-id']);
        }

        if (!empty($meta['received'])) {
            $dateNode = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="history"]/*[local-name()="date" and @date-type="received"]')->item(0);
            $updateDateNode($dateNode, $meta['received']);
        }
        if (!empty($meta['revised'])) {
            $dateNode = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="history"]/*[local-name()="date" and (@date-type="revised" or @date-type="rev-recd")]')->item(0);
            $updateDateNode($dateNode, $meta['revised']);
        }
        if (!empty($meta['accepted'])) {
            $dateNode = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="history"]/*[local-name()="date" and @date-type="accepted"]')->item(0);
            $updateDateNode($dateNode, $meta['accepted']);
        }

        if (!empty($meta['pubdate'])) {
            $dateNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="pub"]')->item(0);
            $updateDateNode($dateNode, $meta['pubdate']);
        }

        if (!empty($meta['collectionYear'])) {
            $collNode = $xpath->query('//*[local-name()="article-meta"]/*[local-name()="pub-date" and @date-type="collection"]')->item(0);
            if ($collNode) {
                $year = $xpath->query('.//*[local-name()="year"]', $collNode)->item(0);
                if ($year) $setText($year, $meta['collectionYear']);
            }
        }

        if (isset($meta['abstractEs'])) {
            $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
            if ($abs) {
                $pNode = $xpath->query('.//*[local-name()="p"]', $abs)->item(0);
                if ($pNode) {
                    if (trim($pNode->textContent) !== trim($meta['abstractEs'])) {
                        $setText($pNode, $meta['abstractEs']);
                    }
                } else {
                    if (trim($abs->textContent) !== trim($meta['abstractEs'])) {
                        $setText($abs, $meta['abstractEs']);
                    }
                }
            }
        }

        if (!empty($meta['kwdsEs']) && is_array($meta['kwdsEs'])) {
            $kg = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group" and (@xml:lang="es" or not(@xml:lang))]')->item(0);
            if ($kg) {
                $existing = $xpath->query('.//*[local-name()="kwd"]', $kg);
                foreach ($existing as $e) $e->parentNode->removeChild($e);
                foreach ($meta['kwdsEs'] as $kw) {
                    $el = $dom->createElement('kwd', $kw);
                    $kg->appendChild($el);
                }
            }
        }

        if (!empty($meta['kwdsEn']) && is_array($meta['kwdsEn'])) {
            $kg = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group" and @xml:lang="en"]')->item(0);
            if ($kg) {
                $existing = $xpath->query('.//*[local-name()="kwd"]', $kg);
                foreach ($existing as $e) $e->parentNode->removeChild($e);
                foreach ($meta['kwdsEn'] as $kw) {
                    $el = $dom->createElement('kwd', $kw);
                    $kg->appendChild($el);
                }
            }
        }

        if (!empty($meta['affiliations']) && is_array($meta['affiliations'])) {
            $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
            $i = 0;
            foreach ($affs as $a) {
                if (!isset($meta['affiliations'][$i])) { $i++; continue; }
                
                // Keep label if it exists
                $labelNode = $xpath->query('.//*[local-name()="label"]', $a)->item(0);
                $labelText = $labelNode ? trim($labelNode->textContent) : (string)($i + 1);
                
                // Clear all children of $a
                while ($a->firstChild) {
                    $a->removeChild($a->firstChild);
                }
                
                // Append label back
                if ($labelText !== '') {
                    $lbl = $dom->createElement('label', $labelText);
                    $a->appendChild($lbl);
                }
                
                $affText = $meta['affiliations'][$i];
                
                // Extract email if any
                $email = '';
                if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $affText, $emMatches)) {
                    $email = $emMatches[1];
                }
                
                // Original institution node
                $origNode = $dom->createElement('institution', $affText);
                $origNode->setAttribute('content-type', 'original');
                $a->appendChild($origNode);
                
                // Parse normalized components (orgname)
                $orgname = '';
                if (preg_match('/(Universidade[^.,;]+|Universidad[^.,;]+|University[^.,;]+)/u', $affText, $orgMatches)) {
                    $orgname = trim($orgMatches[1]);
                }
                
                if ($orgname !== '') {
                    $normNode = $dom->createElement('institution', $orgname);
                    $normNode->setAttribute('content-type', 'normalized');
                    $a->appendChild($normNode);
                }
                
                // Parse divisions (orgdiv1, orgdiv2)
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
                
                $orgdivs = [];
                if (!empty($facMatches)) $orgdivs[] = $facMatches[0];
                if (!empty($instituteMatches)) $orgdivs[] = $instituteMatches[0];
                if (!empty($departmentMatches)) $orgdivs[] = $departmentMatches[0];
                if (!empty($programMatches)) $orgdivs[] = $programMatches[0];
                $orgdivs = array_values(array_unique($orgdivs));
                
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
                
                if ($orgname !== '') {
                    $nameNode = $dom->createElement('institution', $orgname);
                    $nameNode->setAttribute('content-type', 'orgname');
                    $a->appendChild($nameNode);
                }
                
                // Clean punctuation for parsing city and country
                $cleanedText = rtrim(trim($affText), ' .;,!?');
                if ($email !== '') {
                    $cleanedText = trim(str_replace($email, '', $cleanedText), ' .;,!?');
                }
                
                $parts = array_map('trim', preg_split('/[,;]/u', $cleanedText));
                $country = '';
                $countryCode = '';
                $city = '';
                
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
                
                if (count($parts) >= 2) {
                    $possibleCountry = array_pop($parts);
                    $possibleCity = array_pop($parts);
                    
                    $lowerCountry = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        $country = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                        $city = $possibleCity;
                    } else {
                        $parts[] = $possibleCity;
                        $parts[] = $possibleCountry;
                    }
                }
                
                if ($country === '' && count($parts) >= 1) {
                    $possibleCountry = array_pop($parts);
                    $lowerCountry = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        $country = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                    } else {
                        $parts[] = $possibleCountry;
                    }
                }
                
                if ($city === '' && count($parts) >= 1) {
                    $lastPart = array_pop($parts);
                    if (!preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $lastPart)) {
                        $city = $lastPart;
                    } else {
                        $parts[] = $lastPart;
                    }
                }
                
                if ($city !== '') {
                    $addrNode = $dom->createElement('addr-line');
                    $cityNode = $dom->createElement('city', $city);
                    $addrNode->appendChild($cityNode);
                    $a->appendChild($addrNode);
                }
                
                if ($country !== '') {
                    $countryNode = $dom->createElement('country', $country);
                    if ($countryCode !== '') {
                        $countryNode->setAttribute('country', $countryCode);
                    }
                    $a->appendChild($countryNode);
                }
                
                if ($email !== '') {
                    $emailNode = $dom->createElement('email', $email);
                    $a->appendChild($emailNode);
                }
                
                $i++;
            }
        }

        if (!empty($meta['authors']) && is_array($meta['authors'])) {
            $contribs = $xpath->query('//*[local-name()="contrib" and @contrib-type="author"]');
            $i = 0;
            foreach ($contribs as $c) {
                if (!isset($meta['authors'][$i])) { $i++; continue; }
                $aMeta = $meta['authors'][$i];
                $nameNode = $xpath->query('.//*[local-name()="name"]', $c)->item(0);
                if ($nameNode && !empty($aMeta['name'])) {
                    $parts = preg_split('/\s+/', trim($aMeta['name']));
                    $surname = array_pop($parts);
                    $given = implode(' ', $parts);
                    $gn = $xpath->query('.//*[local-name()="given-names"]', $nameNode)->item(0);
                    if (!$gn) { $gn = $dom->createElement('given-names'); $nameNode->appendChild($gn); }
                    $setText($gn, $given);
                    $sn = $xpath->query('.//*[local-name()="surname"]', $nameNode)->item(0);
                    if (!$sn) { $sn = $dom->createElement('surname'); $nameNode->appendChild($sn); }
                    $setText($sn, $surname);
                }
                if (!empty($aMeta['orcid'])) {
                    $cid = $xpath->query('.//*[local-name()="contrib-id" and @contrib-id-type="orcid"]', $c)->item(0);
                    $orcidVal = trim($aMeta['orcid']);
                    if (!preg_match('/^https?:\/\//i', $orcidVal)) {
                        $orcidVal = 'https://orcid.org/' . $orcidVal;
                    }
                    if ($cid) $setText($cid, $orcidVal);
                    else {
                        $nid = $dom->createElement('contrib-id', $orcidVal);
                        $nid->setAttribute('contrib-id-type','orcid');
                        $c->appendChild($nid);
                    }
                }
                $i++;
            }
        }

        // Guardamos el XML base modificado (aquí el front está actualizado, pero el body quedó mutado por DOM)
        $xml = $dom->saveXML();

        // 2. Inyección Directa: Se pisan los bloques body y back mutados con el texto exacto original
        if (!empty($bodyContent)) {
            $xml = preg_replace('/(<body\b[^>]*>)(.*)(<\/body>)/is', '$1' . addcslashes($bodyContent, '$') . '$3', $xml);
        }
        if (!empty($backContent)) {
            $xml = preg_replace('/(<back\b[^>]*>)(.*)(<\/back>)/is', '$1' . addcslashes($backContent, '$') . '$3', $xml);
        }

        // 3. Normalización final de tags autocerrados remanentes en metadatos y reemplazo de <br/> a <break/>
        $xml = preg_replace('/<br\s*\/?>/i', '<break/>', $xml);

        $nonSelfClosing = ['td', 'th', 'tr', 'thead', 'tbody', 'table', 'bold', 'italic', 'p', 'title', 'label', 'abstract'];
        foreach ($nonSelfClosing as $tag) {
            $xml = preg_replace(
                '/<(' . preg_quote($tag, '/') . ')((?:\s[^>]*?)?)\s*\/>/is',
                '<$1$2></$1>',
                $xml
            );
        }

        return $xml;
    }
}