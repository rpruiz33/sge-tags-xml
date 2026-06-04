<?php
/**
 * Parser especializado para JATS/XML ricos en figuras y tablas.
 * Extrae metadatos útiles para la UI y preserva los nodos <fig> y <table-wrap>.
 */
class JatsRichParser
{
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
            'source' => 'xml'
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

        // Affiliations
        $affIdToIndex = [];
        $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
        foreach ($affs as $a) {
            if (!($a instanceof DOMElement)) continue;
            $inst = trim($a->textContent);
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
                    // intentar varios atributos posibles
                    $rid = $xr->getAttribute('rid') ?: $xr->getAttribute('ref') ?: '';
                    // buscar numero en el texto como fallback
                    if ($rid === '') {
                        $txt = trim($xr->textContent);
                        if (preg_match('/(\d+)/', $txt, $m)) $rid = $m[1];
                    }
                    if ($rid !== '') $affRefs[] = $rid;
                }
            }
            // Convertir rid a índices numéricos si existe el mapping
            $affNumeric = [];
            foreach ($affRefs as $r) {
                $key = ltrim((string)$r, '#');
                if (isset($affIdToIndex[$key])) $affNumeric[] = $affIdToIndex[$key];
                else $affNumeric[] = $r; // fallback: dejar tal cual
            }
            $meta['authors'][] = ['name' => $name, 'orcid' => $orcid, 'aff' => $affNumeric];
        }

        // Abstracts
        $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
        if ($abs) $meta['abstractEs'] = trim($abs->textContent);
        $absEn = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="trans-abstract"]')->item(0);
        if ($absEn) $meta['abstractEn'] = trim($absEn->textContent);

        // Keywords
        $kwds = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group"]//*[local-name()="kwd"]');
        foreach ($kwds as $k) $meta['kwdsEs'][] = trim($k->textContent);

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
                    // intentar obtener via namespace xlink
                    $href = $graphic->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: '';
                }
            }
            $meta['figures'][] = ['id' => $fid, 'label' => $label?trim($label->textContent):'', 'caption' => $caption?trim($caption->textContent):'', 'href' => $href, 'xml' => $dom->saveXML($fg)];
        }
        if (!empty($meta['figures'])) $meta['figCount'] = (string)count($meta['figures']);

        // Table-wraps (raw)
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
            // encontrar la tabla interna
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
                        $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan];
                    }
                    $structured['thead'][] = $row;
                }
                // tbody (o filas directas si no hay tbody)
                $tbody = $xpath->query('.//*[local-name()="tbody"]/*[local-name()="tr"]', $tableNode);
                if ($tbody->length === 0) {
                    $tbody = $xpath->query('.//*[local-name()="tr"]', $tableNode);
                    // excluir those in thead
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
                            $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan];
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
                            $row[] = ['tag' => $tag, 'text' => $text, 'value' => $value, 'colspan' => (int)$colspan, 'rowspan' => (int)$rowspan];
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

        // Keep original XML for fidelity when rebuilding
        $meta['originalXml'] = $xmlContent;

        return $meta;
    }

    /**
     * Normaliza el contenido de una celda: números con separador europeo, porcentajes, guiones vacíos.
     * Devuelve int/float cuando es numérico, null para vacío, o el texto original si no se puede convertir.
     */
    private function convertCellValue(string $text)
    {
        $t = trim($text);
        if ($t === '' || $t === '-' ) return null;
        // porcentaje con símbolo
        $isPercent = false;
        if (strpos($t, '%') !== false) {
            $isPercent = true;
            $t = str_replace('%', '', $t);
            $t = trim($t);
        }
        // si contiene solo dígitos, puntos, comas y espacios
        if (preg_match('/^[0-9\.\,\s\-]+$/', $t)) {
            // casos europeos: 1.234,56 => remove dots, replace comma with dot
            if (preg_match('/\d+[\.\d]*,\d+$/', $t)) {
                $num = str_replace('.', '', $t);
                $num = str_replace(',', '.', $num);
                $val = (float)$num;
            } elseif (strpos($t, ',') !== false && strpos($t, '.') === false) {
                // formato 15,6 => decimal
                $num = str_replace(',', '.', $t);
                $val = (float)$num;
            } else {
                // solo puntos como separador de miles o número entero
                $num = str_replace('.', '', $t);
                // eliminar espacios
                $num = str_replace(' ', '', $num);
                if ($num === '') return null;
                if (strpos($num, ',') !== false) $num = str_replace(',', '.', $num);
                // si contiene decimal
                if (strpos($num, '.') !== false) $val = (float)$num;
                else $val = (int)$num;
            }
            // si era porcentaje, mantener el número (no dividido)
            return $val;
        }
        return $text;
    }

    /**
     * Merge simple metadata updates into an original JATS XML string.
     * Preserva todos los nodos no touch (fig, table-wrap, estilos, atributos).
     */
    public function mergeMetaIntoOriginalXml(string $originalXml, array $meta): string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (@$dom->loadXML($originalXml) === false) return $originalXml;
        $xpath = new DOMXPath($dom);

        // Helper to replace or create text nodes safely
        $setText = function($node, $text) {
            while ($node->firstChild) $node->removeChild($node->firstChild);
            if (trim((string)$text) !== '') $node->appendChild($node->ownerDocument->createTextNode($text));
        };

        // Article title
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

        // Abstract
        if (isset($meta['abstractEs'])) {
            $abs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="abstract"]')->item(0);
            if ($abs) $setText($abs, $meta['abstractEs']);
        }

        // Keywords (replace kwd-group contents)
        if (!empty($meta['kwdsEs']) && is_array($meta['kwdsEs'])) {
            $kg = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="kwd-group"]')->item(0);
            if ($kg) {
                // remove existing kwd
                $existing = $xpath->query('.//*[local-name()="kwd"]', $kg);
                foreach ($existing as $e) $e->parentNode->removeChild($e);
                foreach ($meta['kwdsEs'] as $kw) {
                    $el = $dom->createElement('kwd', $kw);
                    $kg->appendChild($el);
                }
            }
        }

        // Affiliations: try to update by index order
        if (!empty($meta['affiliations']) && is_array($meta['affiliations'])) {
            $affs = $xpath->query('//*[local-name()="article-meta"]//*[local-name()="aff"]');
            $i = 0;
            foreach ($affs as $a) {
                if (!isset($meta['affiliations'][$i])) { $i++; continue; }
                // remove text nodes directly under aff
                foreach (iterator_to_array($a->childNodes) as $child) {
                    if ($child instanceof DOMText) $a->removeChild($child);
                }
                $a->insertBefore($dom->createTextNode($meta['affiliations'][$i]), $a->firstChild);
                $i++;
            }
        }

        // Authors: update name nodes and contrib-id/orcid
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
                    if ($cid) $setText($cid, $aMeta['orcid']);
                    else {
                        $nid = $dom->createElement('contrib-id', $aMeta['orcid']);
                        $nid->setAttribute('contrib-id-type','orcid');
                        $c->appendChild($nid);
                    }
                }
                $i++;
            }
        }

        return $dom->saveXML();
    }

}
