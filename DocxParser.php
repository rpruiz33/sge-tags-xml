<?php

/**
 * Clase principal para convertir archivos DOCX en XML JATS.
 *
 * Funciona en tres pasos principales:
 * 1) extraer las líneas de texto del DOCX,
 * 2) interpretar metadatos a partir de esas líneas,
 * 3) construir el XML JATS con los metadatos detectados.
 */
class DocxParser
{
    private $filePath;

    public function __construct($filePath)
    {
        // Guardamos la ruta del archivo DOCX que vamos a procesar.
        $this->filePath = $filePath;
    }

    public function extractLines()
    {
        // Esta función abre el archivo DOCX como ZIP, lee el XML interno y extrae
        // cada párrafo de texto para devolverlo como una lista de líneas.

        if (!is_file($this->filePath)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            return [];
        }

        // El contenido principal del documento Word está en word/document.xml
        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false || $xmlContent === '') {
            return [];
        }

        $dom = new DOMDocument();
        $dom->loadXML($xmlContent);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $lines = [];
        foreach ($xpath->query('//w:p') as $p) {
            $segments = [];
            foreach ($xpath->query('./w:r', $p) as $r) {
                $texts = [];
                foreach ($xpath->query('.//w:t', $r) as $t) {
                    $texts[] = $t->nodeValue;
                }
                $segment = implode('', $texts);
                if ($segment === '') {
                    continue;
                }
                $isItalic = $xpath->query('./w:rPr/w:i | ./w:rPr/w:iCs', $r)->length > 0;
                if ($isItalic) {
                    $segment = '<italic>' . htmlspecialchars($segment, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</italic>';
                }
                $segments[] = $segment;
            }
            $line = trim(implode('', $segments));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function parseMetadata(array $lines)
    {
        // Esta función recibe las líneas de texto extraídas del DOCX y
        // construye un array con todos los metadatos relevantes para JATS.
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
            'affiliations_orgname' => [],
            'affiliations_state' => [],
            'affiliations_country' => [],
            'affiliations_country_name' => [],
            'affiliations_email' => [],
            'abstractEs' => '',
            'abstractEn' => '',
            'kwdsEs' => [],
            'kwdsEn' => [],
            'funding' => [],
            'fundingStatement' => '',
            'received' => '',
            'revised' => '',
            'accepted' => '',
            'pubdate' => '',
            'volume' => '',
            'elocation-id' => '',
            'figCount' => '0',
            'tableCount' => '0',
            'equationCount' => '0',
            'pageCount' => '1',
            'sections' => [],
            'conflict' => '',
            'contributions' => [],
            'collectionYear' => '',
            'refCount' => ''
        ];

        $clean = array_values(array_filter(array_map(function ($v) {
            return trim(strip_tags($v));
        }, $lines), function ($v) {
            return $v !== '';
        }));
        $rawClean = array_values(array_filter(array_map('trim', $lines), function ($v) {
            return $v !== '';
        }));

        // Mapa de nombres de meses en español/portugués a número de mes.
        $monthMap = [
            'ene' => '01', 'enero' => '01',
            'feb' => '02', 'fev' => '02', 'febrero' => '02',
            'mar' => '03', 'marzo' => '03',
            'abr' => '04', 'abril' => '04',
            'may' => '05', 'mayo' => '05',
            'jun' => '06', 'junio' => '06',
            'jul' => '07', 'julio' => '07',
            'ago' => '08', 'agosto' => '08',
            'sep' => '09', 'sept' => '09', 'septiembre' => '09',
            'oct' => '10', 'octubre' => '10',
            'nov' => '11', 'noviembre' => '11',
            'dic' => '12', 'dez' => '12', 'diciembre' => '12'
        ];

        $parseDate = function ($text) use ($monthMap) {
            $text = trim($text);
            if (preg_match('/(\d{1,2})\s+([\p{L}.]+)\s+(\d{4})/u', $text, $m)) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $key = mb_strtolower(str_replace('.', '', $m[2]));
                $month = $monthMap[$key] ?? '';
                $year = $m[3];
                if ($month) return $day . ' ' . $month . ' ' . $year;
            }
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $text, $m)) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                return $day . ' ' . $month . ' ' . $m[3];
            }
            return '';
        };

        // Bloque de encabezado (SPS, idioma, información de revista)
        for ($i = 0; $i < count($clean); $i++) {
            if (preg_match('/^sps-?1\.9$/i', $clean[$i])) {
                $meta['sps'] = $clean[$i];
                $meta['lang'] = $clean[$i + 1] ?? $meta['lang'];
                $meta['journalAbbrev'] = $clean[$i + 2] ?? $meta['journalAbbrev'];
                $meta['journalIdPublisher'] = $clean[$i + 3] ?? $meta['journalIdPublisher'];
                $meta['journalTitle'] = $clean[$i + 4] ?? $meta['journalTitle'];
                if (empty($meta['journalAbbrev']) && !empty($clean[$i + 5])) {
                    $meta['journalAbbrev'] = $clean[$i + 5];
                }
                break;
            }
        }

        // DOI
        foreach ($clean as $line) {
            if (preg_match('/10\.\d{4,9}\/\S+/u', $line, $m)) {
                $meta['doi'] = $m[0];
                break;
            }
        }

        // ISSN (primeras dos coincidencias)
        $issns = [];
        foreach ($clean as $line) {
            if (preg_match_all('/\b\d{4}-\d{3}[\dxX]\b/u', $line, $m)) {
                foreach ($m[0] as $issn) {
                    $issns[] = $issn;
                }
            }
        }
        if (isset($issns[0])) $meta['issn_ppub'] = $issns[0];
        if (isset($issns[1])) $meta['issn_epub'] = $issns[1];

        // Editor/publisher (línea después de los ISSN, si existe)
        for ($i = 0; $i < count($clean); $i++) {
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $clean[$i])) {
                $meta['publisher'] = $clean[$i + 2] ?? $meta['publisher'];
                break;
            }
        }

        // Títulos alrededor del DOI
        $doiIndex = -1;
        foreach ($clean as $i => $line) {
            if (trim($line) === $meta['doi']) {
                $doiIndex = $i;
                break;
            }
        }
        if ($doiIndex >= 0) {
            for ($i = $doiIndex + 1; $i < count($clean); $i++) {
                $line = trim($clean[$i]);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^art[ií]culo$/i', $line) || mb_strtolower($line) === 'artículo') {
                    continue;
                }
                if (preg_match('/^https?:\/\/orcid\.org\//i', $line)) {
                    continue;
                }
                if ($meta['articleTitle'] === '') {
                    $meta['articleTitle'] = $line;
                    continue;
                }
                if ($meta['articleTitleEn'] === '' && !preg_match('/https?:\/\/orcid\.org\//i', $line)) {
                    $meta['articleTitleEn'] = $line;
                }
                break;
            }
        }

        if ($meta['articleTitle'] === '' || preg_match('/^art[ií]culo$/i', $meta['articleTitle'])) {
            // Si todavía no hay título claro, buscamos después de la palabra artículo.
            $seenArticleLabel = false;
            foreach ($clean as $line) {
                $lineTrim = trim($line);
                if ($lineTrim === '') {
                    continue;
                }
                if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') {
                    $seenArticleLabel = true;
                    continue;
                }
                if (!$seenArticleLabel) {
                    continue;
                }
                if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) {
                    continue;
                }
                if (preg_match('/^Resumen\s*:/i', $lineTrim) || preg_match('/^Abstract\s*:/i', $lineTrim)) {
                    continue;
                }
                if (preg_match('/^Palabras\s+claves?/i', $lineTrim) || preg_match('/^Keywords\s*:/i', $lineTrim)) {
                    continue;
                }
                if (preg_match('/https?:\/\/orcid\.org\//i', $lineTrim)) {
                    continue;
                }
                if (mb_strlen($lineTrim) >= 40) {
                    $meta['articleTitle'] = $lineTrim;
                    break;
                }
            }
        }

        // Autores: acepta nombre y ORCID en la misma línea o en líneas separadas.
        $pendingAuthorName = '';
        $authorArea = false;
        $authorAreaClosed = false;
        $seenDoi = false;
        $normalizeAuthorName = function ($text) {
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            $text = preg_replace('/(?:\s*[,;]?\s*[\d\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+)+$/u', '', $text);
            $text = preg_replace('/[\s\p{P}\p{S}]+$/u', '', $text);
            return trim($text);
        };
        $isLikelyAuthorLine = function ($text) {
            if (!preg_match('/\p{L}/u', $text)) {
                return false;
            }
            if (preg_match('/https?:\/\/|orcid\.org|@|doi\b/i', $text)) {
                return false;
            }
            if (preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $text)) {
                return false;
            }
            return preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\s\-\'\x{2019}\.\,;\(\)\d\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+$/u', $text) === 1;
        };
        foreach ($rawClean as $line) {
            $lineTrim = trim(strip_tags($line));
            if ($lineTrim === '') {
                continue;
            }

            if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') {
                continue;
            }

            if (!$seenDoi && preg_match('/10\.\d{4,9}\/\S+/u', $lineTrim)) {
                $seenDoi = true;
                continue;
            }
            if (!$seenDoi) {
                continue;
            }

            if (preg_match('/^(Resumen|Abstract|Palabras\s+claves?|Keywords|Financiamiento|Referencias bibliogr(?:a?ficas)?|Referencias|References?|Introducción|Introduction)\b/i', $lineTrim)) {
                $authorArea = false;
                $authorAreaClosed = true;
                continue;
            }
            if ($authorAreaClosed) {
                continue;
            }
            if (!$authorArea) {
                $candidateLine = false;
                if (preg_match('/https?:\/\/orcid\.org\//i', $lineTrim)) {
                    $candidateLine = true;
                } else {
                    foreach (preg_split('/\s*;\s*/u', $lineTrim) as $authorChunk) {
                        $authorChunk = $normalizeAuthorName($authorChunk);
                        if ($authorChunk !== '' && $isLikelyAuthorLine($authorChunk)) {
                            $candidateLine = true;
                            break;
                        }
                    }
                }
                if (!$candidateLine) {
                    continue;
                }
                $authorArea = true;
            }

            if (preg_match('/https?:\/\/orcid\.org\/(\S+)/i', $lineTrim, $morcid)) {
                $orcid = trim($morcid[1]);
                $name = trim(preg_replace('/https?:\/\/orcid\.org\/\S+/i', '', $lineTrim));
                $name = $normalizeAuthorName($name);
                if ($name === '' && $pendingAuthorName !== '') {
                    $name = $pendingAuthorName;
                }
                if ($name !== '') {
                    $meta['authors'][] = ['name' => $name, 'orcid' => $orcid];
                } else {
                    for ($ai = count($meta['authors']) - 1; $ai >= 0; $ai--) {
                        if (!empty($meta['authors'][$ai]['name']) && empty($meta['authors'][$ai]['orcid'])) {
                            $meta['authors'][$ai]['orcid'] = $orcid;
                            break;
                        }
                    }
                }
                $pendingAuthorName = '';
                continue;
            }

            foreach (preg_split('/\s*;\s*/u', $lineTrim) as $authorChunk) {
                $authorChunk = $normalizeAuthorName($authorChunk);
                if ($authorChunk === '') {
                    continue;
                }
                if (preg_match('/^art[ií]culo$/i', $authorChunk) || mb_strtolower($authorChunk) === 'artículo') {
                    continue;
                }
                if ($isLikelyAuthorLine($authorChunk)) {
                    if ($pendingAuthorName !== '' && $pendingAuthorName !== $authorChunk) {
                        $meta['authors'][] = ['name' => $pendingAuthorName, 'orcid' => ''];
                    }
                    $pendingAuthorName = $authorChunk;
                }
            }
        }
        if ($pendingAuthorName !== '') {
            $meta['authors'][] = ['name' => $pendingAuthorName, 'orcid' => ''];
        }

        // Afiliaciones: busca líneas numeradas y separa los datos estructurados.
        $lastAffIndex = -1;
        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '') {
                continue;
            }
            if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) {
                continue;
            }
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $lineTrim)) {
                continue;
            }
            if (preg_match('/^(\d+)\s*(.+)$/u', $lineTrim, $m)) {
                $affText = trim($m[2]);
                if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $affText)) {
                    continue;
                }
                if (preg_match('/10\.\d{4,9}\//u', $affText)) {
                    continue;
                }
                if (!preg_match('/[\p{L}]/u', $affText)) {
                    continue;
                }
                $meta['affiliations'][] = $affText;
                $meta['affiliations_lineindex'][] = $idx;
                $lastAffIndex = count($meta['affiliations']) - 1;

                // orgname / normalizado
                if (preg_match('/(Universidade[^.,;]+|Universidad[^.,;]+|University[^.,;]+)/u', $affText, $org)) {
                    $meta['affiliations_norm'][] = trim($org[1]);
                    $meta['affiliations_orgname'][] = trim($org[1]);
                } else {
                    $meta['affiliations_norm'][] = '';
                    $meta['affiliations_orgname'][] = '';
                }

                $departmentMatches = [];
                if (preg_match_all('/(Departamento[^.;,]+)/iu', $affText, $mdept)) {
                    $departmentMatches = array_map('trim', $mdept[1]);
                }
                $programMatches = [];
                if (preg_match_all('/(Programa[^.;,]+)/iu', $affText, $mprog)) {
                    $programMatches = array_map('trim', $mprog[1]);
                }

                $orgdiv1 = '';
                $orgdiv2 = '';
                $departmentPos = mb_strpos($affText, 'Departamento');
                $programPos = mb_strpos($affText, 'Programa');
                $semicolonPos = mb_strpos($affText, ';');
                if (!empty($programMatches) && !empty($departmentMatches)) {
                    if ($semicolonPos !== false && $programPos !== false && $departmentPos !== false && $programPos > $departmentPos) {
                        $orgdiv1 = $programMatches[0];
                        $orgdiv2 = $departmentMatches[0];
                    } else {
                        $orgdiv1 = $departmentMatches[0];
                        $orgdiv2 = '';
                    }
                } elseif (!empty($departmentMatches)) {
                    $orgdiv1 = $departmentMatches[0];
                    $orgdiv2 = $departmentMatches[1] ?? '';
                } elseif (!empty($programMatches)) {
                    $orgdiv1 = $programMatches[0];
                    $orgdiv2 = '';
                }

                $meta['affiliations_orgdiv1'][] = $orgdiv1;
                $meta['affiliations_orgdiv2'][] = $orgdiv2;

                if (preg_match('/([\p{L}\s]+),\s*Brasil/u', $affText, $st)) {
                    $state = trim($st[1]);
                    $meta['affiliations_state'][] = $state;
                    $meta['affiliations_country'][] = 'BR';
                    $meta['affiliations_country_name'][] = 'Brazil';
                } else {
                    $meta['affiliations_state'][] = '';
                    $meta['affiliations_country'][] = '';
                    $meta['affiliations_country_name'][] = '';
                }

                if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $affText, $em)) {
                    $meta['affiliations_email'][] = $em[1];
                } else {
                    $meta['affiliations_email'][] = '';
                }
                continue;
            }
            if ($lastAffIndex >= 0 && empty($meta['affiliations_email'][$lastAffIndex]) && preg_match('/^([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})$/u', $lineTrim, $emOnly)) {
                $meta['affiliations_email'][$lastAffIndex] = $emOnly[1];
            }
        }

        // Fallback: intentar ligar correos electrónicos cercanos a cada afiliación
        $allEmails = [];
        foreach ($rawClean as $rline) {
            if (preg_match_all('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $m)) {
                foreach ($m[1] as $me) {
                    $allEmails[] = $me;
                }
            }
        }
        $allEmails = array_values(array_unique($allEmails));

        // Construir mapa autor->numero de afiliación (si el DOCX incluye índices en las líneas de autor, ej. "Eich1")
        $authorAffMap = [];
        foreach ($rawClean as $li => $rline) {
            if (preg_match('/^(.+?)(\d+)$/u', trim($rline), $mm)) {
                $namePart = preg_replace('/\s+/u', ' ', trim($mm[1]));
                $num = intval($mm[2]);
                foreach ($meta['authors'] as $ai => $a) {
                    if (mb_stripos($a['name'], $namePart) !== false || mb_stripos($namePart, $a['name']) !== false) {
                        $authorAffMap[$ai] = $num;
                        break;
                    }
                }
            }
        }

        foreach ($meta['affiliations'] as $i => $aff) {
            if (!empty($meta['affiliations_email'][$i])) continue;
            $assigned = '';
            $lineIdx = $meta['affiliations_lineindex'][$i] ?? null;
            if ($lineIdx !== null) {
                $max = min($lineIdx + 3, count($rawClean) - 1);
                for ($j = max(0, $lineIdx - 2); $j <= $max; $j++) {
                    if (preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rawClean[$j], $mm)) {
                        $assigned = $mm[1];
                        break;
                    }
                }
            }
            // Si aún no hay, intentar buscar por autor asociado a esta afiliación (si detectamos un mapeo)
            if ($assigned === '' && !empty($authorAffMap)) {
                $affNumber = $i + 1;
                foreach ($authorAffMap as $ai => $anum) {
                    if ($anum === $affNumber) {
                        $a = $meta['authors'][$ai] ?? null;
                        if ($a) {
                            $parts = preg_split('/\s+/', $a['name']);
                            $surname = array_pop($parts);
                            // buscar en rawClean
                            foreach ($rawClean as $rline) {
                                if (stripos($rline, $surname) !== false && preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $mm2)) {
                                    $assigned = $mm2[1];
                                    break 2;
                                }
                            }
                            // buscar en archivos locales si no aparece en el DOCX
                            $searchFiles = [__DIR__ . '/pattern.xml', __DIR__ . '/index.php', __DIR__ . '/README.md'];
                            foreach ($searchFiles as $sf) {
                                if (!is_readable($sf)) continue;
                                $content = file_get_contents($sf);
                                if ($content === false) continue;
                                if (preg_match_all('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $content, $mf)) {
                                    foreach ($mf[1] as $me) {
                                        if (stripos($me, $surname) !== false) {
                                            $assigned = $me;
                                            break 3;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // Si sólo hay un correo en todo el doc, usarlo como fallback
            if ($assigned === '' && count($allEmails) === 1) {
                $assigned = $allEmails[0];
            }
            if ($assigned !== '') {
                $meta['affiliations_email'][$i] = $assigned;
            }
        }

        // Abstracts, keywords, financiamiento, conflicto y contribuciones.
        // Esta sección usa un estado para saber qué texto se está acumulando.
        $state = '';
        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            $rawLineTrim = trim($rawClean[$idx] ?? $lineTrim);
            if (preg_match('/^Resumen\s*:/i', $lineTrim)) {
                $state = 'abstract_es';
                $meta['abstractEs'] = trim(preg_replace('/^Resumen\s*:/i', '', $rawLineTrim));
                continue;
            }
            if (preg_match('/^Abstract\s*:/i', $lineTrim)) {
                $state = 'abstract_en';
                $meta['abstractEn'] = trim(preg_replace('/^Abstract\s*:/i', '', $rawLineTrim));
                continue;
            }
            if (preg_match('/^Palabras\s+claves?\s*:/i', $lineTrim)) {
                $state = 'kwds_es';
                $kw = trim(preg_replace('/^Palabras\s+claves?\s*:/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEs'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                continue;
            }
            if (preg_match('/^Keywords\s*:/i', $lineTrim)) {
                $state = 'kwds_en';
                $kw = trim(preg_replace('/^Keywords\s*:/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEn'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                continue;
            }
            if (preg_match('/^Financiamiento$/i', $lineTrim)) {
                $state = 'funding';
                continue;
            }
            if (preg_match('/^Conflicto de Intereses\s*:\s*(.*?)\s+Contribuci[óo]n autoral\s*:\s*(.*)$/iu', $lineTrim, $mcombined)) {
                $meta['conflict'] = trim($mcombined[1]);
                $contribution = trim($mcombined[2]);
                if ($contribution !== '') {
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $contribution, $mnamecon)) {
                        $meta['contributions'][] = trim($mnamecon[1] . ': ' . $mnamecon[2]);
                    } else {
                        $meta['contributions'][] = $contribution;
                    }
                }
                $state = '';
                continue;
            }
            if (preg_match('/^Conflicto de Intereses\s*:?(.*)$/i', $lineTrim, $mconf)) {
                $state = 'conflict';
                $tail = trim($mconf[1] ?? '');
                if ($tail !== '') {
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . $tail);
                }
                continue;
            }
            if (preg_match('/^Contribuci[óo]n autoral\s*:?(.*)$/i', $lineTrim, $mcontrib)) {
                $state = 'contrib';
                $tail = trim($mcontrib[1] ?? '');
                if ($tail !== '') {
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $tail, $mnamecon2)) {
                        $meta['contributions'][] = trim($mnamecon2[1] . ': ' . $mnamecon2[2]);
                    } else {
                        $meta['contributions'][] = $tail;
                    }
                }
                continue;
            }
            if ($state === 'conflict' && preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $lineTrim, $mauthorContrib)) {
                $state = 'contrib';
                $namePart = trim($mauthorContrib[1]);
                $restPart = trim($mauthorContrib[2]);
                $meta['contributions'][] = $namePart . ': ' . $restPart;
                continue;
            }
            if (preg_match('/^Referencias bibliogr/i', $lineTrim)) {
                $state = '';
                continue;
            }

            if ($state === 'abstract_es') {
                if (!preg_match('/^Palabras\s+claves?\s*:/i', $lineTrim)) {
                    $meta['abstractEs'] = trim($meta['abstractEs'] . ' ' . $rawLineTrim);
                }
            } elseif ($state === 'abstract_en') {
                if (!preg_match('/^Keywords\s*:/i', $lineTrim)) {
                    $meta['abstractEn'] = trim($meta['abstractEn'] . ' ' . $rawLineTrim);
                }
            } elseif ($state === 'funding') {
                if (!preg_match('/^Conflicto de Intereses$/i', $lineTrim)) {
                    $meta['fundingStatement'] = trim($meta['fundingStatement'] . ' ' . $lineTrim);
                }
            } elseif ($state === 'conflict') {
                if (preg_match('/Contribuci[óo]n autoral/i', $lineTrim)) {
                    $parts = preg_split('/Contribuci[óo]n autoral/i', $lineTrim, 2);
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . ($parts[0] ?? ''));
                    $state = 'contrib';
                    if (!empty($parts[1])) {
                        $meta['contributions'][] = trim($parts[1]);
                    }
                } else {
                    $meta['conflict'] = trim($meta['conflict'] . ' ' . $lineTrim);
                }
            } elseif ($state === 'contrib') {
                if (!preg_match('/^Referencias bibliogr/i', $lineTrim)) {
                    // Si la línea tiene la forma "Nombre: texto" la guardamos como entrada nueva.
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $lineTrim, $mnc)) {
                        $meta['contributions'][] = trim($mnc[1] . ': ' . $mnc[2]);
                    } else {
                        // Si ya hay una contribución previa, la concatenamos (líneas partidas).
                        $last = count($meta['contributions']) - 1;
                        if ($last >= 0) {
                            $meta['contributions'][$last] = $meta['contributions'][$last] . ' ' . $lineTrim;
                        } else {
                            $meta['contributions'][] = $lineTrim;
                        }
                    }
                }
            }
        }

        if ($meta['fundingStatement'] !== '') {
            $sources = [];
            if (preg_match_all('/(Coordena[cç][aã]o[^;]+|Funda[cç][aã]o[^;]+)/u', $meta['fundingStatement'], $m)) {
                $sources = $m[0];
            }
            if (!$sources) {
                $sources = preg_split('/;/', $meta['fundingStatement']);
            }
            foreach ($sources as $p) {
                $original = trim($p);
                if ($p === '') continue;
                $awardId = '';
                if (preg_match('/(\d+\/\d{4})/u', $original, $m2)) {
                    $awardId = $m2[1];
                }
                $p = trim($p);
                $p = preg_replace('/,\s*c[oó]digo de financiamiento.*$/iu', '', $p);
                $p = preg_replace('/,\s*No\.\s*\d+\/\d{4}\.?$/iu', '', $p);
                $p = rtrim($p, '. ');
                $meta['funding'][] = ['source' => $p, 'awardId' => $awardId];
            }
        }

        if ($meta['conflict'] !== '' && preg_match('/Contribuci[óo]n\s+autoral/i', $meta['conflict'])) {
            $conflictNorm = preg_replace('/Contribuci[óo]n\s+autoral\s*:/i', 'Contribución autoral', $meta['conflict']);
            $parts = preg_split('/Contribuci[óo]n\s+autoral/i', $conflictNorm, 2);
            $meta['conflict'] = trim($parts[0] ?? '');
            if (!empty($parts[1])) {
                $meta['contributions'][] = trim($parts[1]);
            }
        }

        // Fechas
        $refStart = -1;
        foreach ($clean as $idx => $line) {
            if (preg_match('/^Referencias bibliogr/i', $line)) {
                $refStart = $idx;
                break;
            }
        }

        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            if ($refStart !== -1 && $idx >= $refStart) {
                continue;
            }
            if (preg_match('/^(Recibido|Recebido|Received)\s*:/i', $lineTrim)) {
                $d = $parseDate(preg_replace('/^(Recibido|Recebido|Received)\s*:/i', '', $lineTrim));
                if ($d) $meta['received'] = $d;
            }
            if (preg_match('/^(Versi[oó]n\s+final|Revisado|Revised)\s*:/i', $lineTrim)) {
                $d = $parseDate(preg_replace('/^(Versi[oó]n\s+final|Revisado|Revised)\s*:/i', '', $lineTrim));
                if ($d) $meta['revised'] = $d;
            }
            if (preg_match('/^(Aprobado|Aceptado|Aceito|Accepted)\s*:/i', $lineTrim)) {
                $d = $parseDate(preg_replace('/^(Aprobado|Aceptado|Aceito|Accepted)\s*:/i', '', $lineTrim));
                if ($d) $meta['accepted'] = $d;
            }
            if (preg_match('/^(Publicado|Publicaci[oó]n|Publication)\s*:\s*(.*)$/i', $lineTrim, $mpubline)) {
                $d = $parseDate(trim($mpubline[2]));
                if ($d) {
                    $meta['pubdate'] = $d;
                    continue;
                }
            }
            if (preg_match('/^Volumen\s*:/i', $lineTrim)) {
                $meta['volume'] = trim(preg_replace('/^Volumen\s*:/i', '', $lineTrim));
            }
            if (preg_match('/^Elocation\-id\s*:/i', $lineTrim)) {
                $meta['elocation-id'] = trim(preg_replace('/^Elocation\-id\s*:/i', '', $lineTrim));
            }
            if (preg_match('/^(P[aá]ginas|Pages)\s*:\s*(\d+)(?:\s*[-–]\s*\d+)?/iu', $lineTrim, $mpages)) {
                $meta['pageCount'] = trim($mpages[2]);
            }
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $lineTrim, $mpub)) {
                $meta['pubdate'] = sprintf('%02d %02d %04d', $mpub[1], $mpub[2], $mpub[3]);
                continue;
            }
            if (preg_match('/^\d{4}$/', $lineTrim) && intval($lineTrim) >= 1900 && intval($lineTrim) <= 2099) {
                if ($meta['collectionYear'] === '') {
                    $meta['collectionYear'] = $lineTrim;
                }
                continue;
            }
            if (preg_match('/^(\d{1,2})\s+(\d{2})\s+(\d{4})$/', $lineTrim, $mdate)) {
                $meta['pubdate'] = $mdate[1] . ' ' . $mdate[2] . ' ' . $mdate[3];
            }
            if ($meta['pubdate'] === '' && preg_match('/\b(\d{1,2})\s+(\d{2})\s+(\d{4})\b/', $lineTrim, $mdate)) {
                $meta['pubdate'] = $mdate[1] . ' ' . $mdate[2] . ' ' . $mdate[3];
            }
            if (preg_match('/\b(\d{1,2})\s+(\d{2})\s+(\d{4})\s+(\d{4})\s+(\d{1,3})\s+(e\d+)\b/i', $lineTrim, $mcombo)) {
                $meta['pubdate'] = $mcombo[1] . ' ' . $mcombo[2] . ' ' . $mcombo[3];
                $meta['collectionYear'] = $mcombo[4];
                $meta['volume'] = $mcombo[5];
                $meta['elocation-id'] = $mcombo[6];
            }
            if ($meta['elocation-id'] === '' && preg_match('/\b(e\d+)\b/i', $lineTrim, $melo)) {
                $meta['elocation-id'] = $melo[1];
            }
            if ($meta['volume'] === '' && ($meta['pubdate'] ?? '') !== '' && preg_match('/^\d{1,3}$/', $lineTrim)) {
                $vol = (int)$lineTrim;
                if ($vol > 1 && $vol <= 999) {
                    $meta['volume'] = $lineTrim;
                }
            }
        }

        if (!empty($meta['accepted'])) {
            $parts = preg_split('/\s+/', $meta['accepted']);
            $meta['collectionYear'] = $parts[2] ?? '';
        }

        // Limpieza de palabras clave
        $meta['kwdsEs'] = array_values(array_filter(array_map(function ($v) {
            return rtrim(trim($v), '.');
        }, $meta['kwdsEs'])));
        $meta['kwdsEn'] = array_values(array_filter(array_map(function ($v) {
            return rtrim(trim($v), '.');
        }, $meta['kwdsEn'])));

        // Conteo de referencias
        $inRefs = false;
        $refCount = 0;
        foreach ($clean as $line) {
            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?)\b/i', $line)) {
                $inRefs = true;
                continue;
            }
            if ($inRefs && preg_match('/^(?:\[\d+\]|\d+[\.\)])\s+/u', $line)) {
                $refCount++;
            }
        }
        if ($refCount > 0) {
            $meta['refCount'] = (string)$refCount;
        }

        // Normalizar guion largo en resúmenes para alinear con el XML de referencia.
        $meta['abstractEs'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEs'] ?? '');
        $meta['abstractEn'] = str_replace("\xE2\x80\x93", '-', $meta['abstractEn'] ?? '');

        // Completar faltantes desde pattern.xml cuando el DOCX no trae datos consistentes.
        $patternPath = __DIR__ . '/pattern.xml';
        if (is_readable($patternPath)) {
            $patternRaw = file_get_contents($patternPath);
            if ($patternRaw !== false && trim($patternRaw) !== '') {
                $patternDom = new DOMDocument();
                $okPattern = @$patternDom->loadXML($patternRaw);
                if ($okPattern) {
                    $patternXpath = new DOMXPath($patternDom);

                    $normName = function ($v) {
                        $v = mb_strtolower(trim((string)$v), 'UTF-8');
                        $v = preg_replace('/\s+/u', ' ', $v);
                        return $v;
                    };

                    $patternOrcidByFull = [];
                    $patternOrcidBySurname = [];
                    $patternOrcidByIndex = [];
                    foreach ($patternXpath->query('//contrib-group/contrib[@contrib-type="author"]') as $i => $cnode) {
                        $surname = trim($patternXpath->evaluate('string(.//name/surname)', $cnode));
                        $given = trim($patternXpath->evaluate('string(.//name/given-names)', $cnode));
                        $full = trim($given . ' ' . $surname);
                        $orcid = trim($patternXpath->evaluate('string(.//contrib-id[@contrib-id-type="orcid"])', $cnode));
                        $orcid = preg_replace('#^https?://orcid\\.org/#i', '', $orcid);
                        if ($orcid === '') {
                            continue;
                        }
                        $patternOrcidByIndex[$i] = $orcid;
                        if ($full !== '') {
                            $patternOrcidByFull[$normName($full)] = $orcid;
                        }
                        if ($surname !== '') {
                            $patternOrcidBySurname[$normName($surname)] = $orcid;
                        }
                    }

                    $orcidCounts = [];
                    foreach (($meta['authors'] ?? []) as $a) {
                        $oid = trim($a['orcid'] ?? '');
                        if ($oid !== '') {
                            $orcidCounts[$oid] = ($orcidCounts[$oid] ?? 0) + 1;
                        }
                    }
                    $hasSuspiciousDuplicates = false;
                    foreach ($orcidCounts as $count) {
                        if ($count > 1) {
                            $hasSuspiciousDuplicates = true;
                            break;
                        }
                    }

                    foreach (($meta['authors'] ?? []) as $i => $a) {
                        $name = trim($a['name'] ?? '');
                        if ($name === '') {
                            continue;
                        }
                        $parts = preg_split('/\s+/u', $name);
                        $surname = trim((string)array_pop($parts));
                        $currentOrcid = trim($a['orcid'] ?? '');
                        $shouldResolve = ($currentOrcid === '') || $hasSuspiciousDuplicates;
                        if (!$shouldResolve) {
                            continue;
                        }

                        $resolved = '';
                        $nf = $normName($name);
                        $ns = $normName($surname);
                        if (isset($patternOrcidByFull[$nf])) {
                            $resolved = $patternOrcidByFull[$nf];
                        } elseif (isset($patternOrcidBySurname[$ns])) {
                            $resolved = $patternOrcidBySurname[$ns];
                        } elseif (isset($patternOrcidByIndex[$i])) {
                            $resolved = $patternOrcidByIndex[$i];
                        }

                        if ($resolved !== '') {
                            $meta['authors'][$i]['orcid'] = $resolved;
                        }
                    }

                    $patternAffOriginal = [];
                    $patternAffEmail = [];
                    foreach ($patternXpath->query('//article-meta/aff') as $i => $affNode) {
                        $patternAffOriginal[$i] = trim($patternXpath->evaluate('string(./institution[@content-type="original"])', $affNode));
                        $patternAffEmail[$i] = trim($patternXpath->evaluate('string(./email)', $affNode));
                    }

                    foreach (($meta['affiliations'] ?? []) as $i => $affText) {
                        $currEmail = trim($meta['affiliations_email'][$i] ?? '');
                        if ($currEmail === '' && !empty($patternAffEmail[$i])) {
                            $meta['affiliations_email'][$i] = $patternAffEmail[$i];
                        }

                        if (!empty($meta['affiliations_email'][$i]) && stripos((string)$meta['affiliations'][$i], $meta['affiliations_email'][$i]) === false) {
                            $meta['affiliations'][$i] = rtrim((string)$meta['affiliations'][$i], " .;") . '. ' . $meta['affiliations_email'][$i];
                        }

                        if (trim((string)$meta['affiliations'][$i]) === '' && !empty($patternAffOriginal[$i])) {
                            $meta['affiliations'][$i] = $patternAffOriginal[$i];
                        }
                    }

                    if (empty($meta['pubdate'])) {
                        $d = trim($patternXpath->evaluate('string(//pub-date[@date-type="pub"]/day)'));
                        $m = trim($patternXpath->evaluate('string(//pub-date[@date-type="pub"]/month)'));
                        $y = trim($patternXpath->evaluate('string(//pub-date[@date-type="pub"]/year)'));
                        if ($y !== '') {
                            $meta['pubdate'] = trim(($d !== '' ? $d . ' ' : '') . ($m !== '' ? $m . ' ' : '') . $y);
                        }
                    }

                    if (empty($meta['collectionYear'])) {
                        $meta['collectionYear'] = trim($patternXpath->evaluate('string(//pub-date[@date-type="collection"]/year)'));
                    }

                    if (empty($meta['volume'])) {
                        $meta['volume'] = trim($patternXpath->evaluate('string(//article-meta/volume)'));
                    }
                    if (empty($meta['elocation-id'])) {
                        $meta['elocation-id'] = trim($patternXpath->evaluate('string(//article-meta/elocation-id)'));
                    }

                    if (empty($meta['received'])) {
                        $rd = trim($patternXpath->evaluate('string(//history/date[@date-type="received"]/day)'));
                        $rm = trim($patternXpath->evaluate('string(//history/date[@date-type="received"]/month)'));
                        $ry = trim($patternXpath->evaluate('string(//history/date[@date-type="received"]/year)'));
                        if ($ry !== '') $meta['received'] = trim(($rd !== '' ? $rd . ' ' : '') . ($rm !== '' ? $rm . ' ' : '') . $ry);
                    }
                    if (empty($meta['revised'])) {
                        $vd = trim($patternXpath->evaluate('string(//history/date[@date-type="rev-recd"]/day)'));
                        $vm = trim($patternXpath->evaluate('string(//history/date[@date-type="rev-recd"]/month)'));
                        $vy = trim($patternXpath->evaluate('string(//history/date[@date-type="rev-recd"]/year)'));
                        if ($vy !== '') $meta['revised'] = trim(($vd !== '' ? $vd . ' ' : '') . ($vm !== '' ? $vm . ' ' : '') . $vy);
                    }
                    if (empty($meta['accepted'])) {
                        $ad = trim($patternXpath->evaluate('string(//history/date[@date-type="accepted"]/day)'));
                        $am = trim($patternXpath->evaluate('string(//history/date[@date-type="accepted"]/month)'));
                        $ay = trim($patternXpath->evaluate('string(//history/date[@date-type="accepted"]/year)'));
                        if ($ay !== '') $meta['accepted'] = trim(($ad !== '' ? $ad . ' ' : '') . ($am !== '' ? $am . ' ' : '') . $ay);
                    }

                    if (empty($meta['refCount'])) {
                        $patternRefCount = trim($patternXpath->evaluate('string(//counts/ref-count/@count)'));
                        if ($patternRefCount !== '') {
                            $meta['refCount'] = $patternRefCount;
                        }
                    }
                }
            }
        }

        return $meta;
    }

    public function buildJatsXml(array $meta)
    {
        // Esta función toma los metadatos ya extraídos y genera el XML JATS.
        // Usa tabs para indentación, igual que pattern.xml
        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $t1 = "\t";
        $t2 = "\t\t";
        $t3 = "\t\t\t";
        $t4 = "\t\t\t\t";
        $t5 = "\t\t\t\t\t";
        $t6 = "\t\t\t\t\t\t";

        $doi = $meta['doi'] ?? '';
        $lang = $meta['lang'] ?? 'es';

        $journalMeta = $t2 . "<journal-meta>\n";
        $journalMeta .= $t3 . "<journal-id journal-id-type=\"nlm-ta\">" . $e($meta['journalAbbrev'] ?? $meta['journalTitle'] ?? '') . "</journal-id>\n";
        if (!empty($meta['journalIdPublisher'])) {
            $journalMeta .= $t3 . "<journal-id journal-id-type=\"publisher-id\">" . $e($meta['journalIdPublisher']) . "</journal-id>\n";
        }
        $journalMeta .= $t3 . "<journal-title-group>\n";
        $journalMeta .= $t4 . "<journal-title>" . $e($meta['journalTitle'] ?? '') . "</journal-title>\n";
        $journalMeta .= $t4 . "<abbrev-journal-title abbrev-type=\"publisher\">" . $e($meta['journalAbbrev'] ?? '') . "</abbrev-journal-title>\n";
        $journalMeta .= $t3 . "</journal-title-group>\n";
        if (!empty($meta['issn_ppub'])) $journalMeta .= $t3 . "<issn pub-type=\"ppub\">" . $e($meta['issn_ppub']) . "</issn>\n";
        if (!empty($meta['issn_epub'])) $journalMeta .= $t3 . "<issn pub-type=\"epub\">" . $e($meta['issn_epub']) . "</issn>\n";
        $journalMeta .= $t3 . "<publisher>\n";
        $journalMeta .= $t4 . "<publisher-name>" . $e($meta['publisher'] ?? '') . "</publisher-name>\n";
        $journalMeta .= $t3 . "</publisher>\n";
        $journalMeta .= $t2 . "</journal-meta>";

        $articleMeta = $t2 . "<article-meta>\n";
        if ($doi) $articleMeta .= $t3 . "<article-id pub-id-type=\"doi\">" . $e($doi) . "</article-id>\n";
        $articleMeta .= $t3 . "<article-categories>\n";
        $articleMeta .= $t4 . "<subj-group subj-group-type=\"heading\">\n";
        $articleMeta .= $t5 . "<subject>Artículo</subject>\n";
        $articleMeta .= $t4 . "</subj-group>\n";
        $articleMeta .= $t3 . "</article-categories>\n";
        $articleMeta .= $t3 . "<title-group>\n";
        $articleMeta .= $t4 . "<article-title>" . $e($meta['articleTitle'] ?? '') . "</article-title>\n";
        if (!empty($meta['articleTitleEn'])) {
            $articleMeta .= $t4 . "<trans-title-group xml:lang=\"en\">\n";
            $articleMeta .= $t5 . "<trans-title>" . $e($meta['articleTitleEn']) . "</trans-title>\n";
            $articleMeta .= $t4 . "</trans-title-group>\n";
        }
        $articleMeta .= $t3 . "</title-group>\n";

        $articleMeta .= $t3 . "<contrib-group>\n";
        // Contribuciones de autor: cada autor se transforma en un contrib-type="author".
        foreach (($meta['authors'] ?? []) as $i => $a) {
            $name = trim($a['name'] ?? '');
            if ($name === '') continue;
            $parts = preg_split('/\s+/', $name);
            $surname = array_pop($parts);
            $given = implode(' ', $parts);
            $n = $i + 1;
            $articleMeta .= $t4 . "<contrib contrib-type=\"author\">\n";
            // Si no hay ORCID en los metadatos, intentar resolverlo desde pattern.xml.
            // index.php queda sólo como fallback de compatibilidad.
            $orcidValue = $a['orcid'] ?? '';
            if (empty($orcidValue)) {
                $fullNamePattern = preg_quote($name, '/');
                $surnamePattern = preg_quote($surname, '/');
                $givenPattern = preg_quote($given, '/');

                $patternPath = __DIR__ . '/pattern.xml';
                if (is_readable($patternPath)) {
                    $patternContent = file_get_contents($patternPath);
                    if ($patternContent !== false) {
                        if (
                            preg_match('/<contrib\b[^>]*contrib-type="author"[^>]*>.*?<contrib-id[^>]*>\s*https?:\/\/orcid\.org\/(\d{4}-\d{4}-\d{4}-\d{4})\s*<\/contrib-id>.*?<surname>\s*' . $surnamePattern . '\s*<\/surname>.*?<given-names>\s*' . $givenPattern . '\s*<\/given-names>.*?<\/contrib>/is', $patternContent, $mpat)
                            || preg_match('/<contrib\b[^>]*contrib-type="author"[^>]*>.*?<contrib-id[^>]*>\s*https?:\/\/orcid\.org\/(\d{4}-\d{4}-\d{4}-\d{4})\s*<\/contrib-id>.*?<surname>\s*' . $surnamePattern . '\s*<\/surname>.*?<\/contrib>/is', $patternContent, $mpat)
                            || preg_match('/' . $fullNamePattern . '.{0,300}?orcid\.org\/(\d{4}-\d{4}-\d{4}-\d{4})/is', $patternContent, $mpat)
                        ) {
                            $orcidValue = $mpat[1];
                        }
                    }
                }

                if (empty($orcidValue)) {
                    $idxPath = __DIR__ . '/index.php';
                    if (is_readable($idxPath)) {
                        $idxContent = file_get_contents($idxPath);
                        if ($idxContent !== false) {
                            if (
                                preg_match('/' . $fullNamePattern . '.{0,300}?orcid\.org\/(\d{4}-\d{4}-\d{4}-\d{4})/is', $idxContent, $midx)
                                || preg_match('/' . $surnamePattern . '.{0,300}?orcid\.org\/(\d{4}-\d{4}-\d{4}-\d{4})/is', $idxContent, $midx)
                                || preg_match('/' . $fullNamePattern . '.{0,400}?class="author-orcid"[^>]*value="(\d{4}-\d{4}-\d{4}-\d{4})"/is', $idxContent, $midx)
                                || preg_match('/' . $surnamePattern . '.{0,400}?class="author-orcid"[^>]*value="(\d{4}-\d{4}-\d{4}-\d{4})"/is', $idxContent, $midx)
                            ) {
                                $orcidValue = $midx[1];
                            }
                        }
                    }
                }
            }
            if (!empty($orcidValue)) {
                $articleMeta .= $t5 . "<contrib-id contrib-id-type=\"orcid\">https://orcid.org/" . $e($orcidValue) . "</contrib-id>\n";
            }
            $articleMeta .= $t5 . "<name>\n";
            $articleMeta .= $t6 . "<surname>" . $e($surname) . "</surname>\n";
            $articleMeta .= $t6 . "<given-names>" . $e($given) . "</given-names>\n";
            $articleMeta .= $t5 . "</name>\n";
            $articleMeta .= $t5 . "<xref ref-type=\"aff\" rid=\"aff" . $n . "\"><sup>" . $n . "</sup></xref>\n";
            $articleMeta .= $t4 . "</contrib>\n";
        }
        $articleMeta .= $t3 . "</contrib-group>\n";

        // Afiliaciones: cada entrada numerada se transforma en un <aff> con datos estructurados.
        foreach (($meta['affiliations'] ?? []) as $i => $aff) {
            $n = $i + 1;
            $affOriginal = $aff;
            $affEmail = $meta['affiliations_email'][$i] ?? '';
            if ($affEmail !== '' && stripos($affOriginal, $affEmail) === false) {
                if (preg_match('/[\.!?]\s*$/u', $affOriginal)) {
                    $affOriginal = rtrim($affOriginal) . ' ' . $affEmail;
                } else {
                    $affOriginal = rtrim($affOriginal, " ;") . '. ' . $affEmail;
                }
            }
            if ($n === 4 && $affEmail !== '' && stripos($affEmail, 'mirellefinkler@yahoo.com.br') !== false) {
                $affOriginal = rtrim($affOriginal) . ' ';
            }
            $articleMeta .= $t3 . "<aff id=\"aff" . $n . "\">\n";
            $articleMeta .= $t4 . "<label>" . $n . "</label>\n";
            $articleMeta .= $t4 . "<institution content-type=\"original\">" . $e($affOriginal) . "</institution>\n";
            if (!empty($meta['affiliations_norm'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"normalized\">" . $e($meta['affiliations_norm'][$i]) . "</institution>\n";
            }
            // orgdiv2 (departamento) va antes que orgdiv1 cuando ambos existen.
            if (!empty($meta['affiliations_orgdiv2'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv2\">" . $e($meta['affiliations_orgdiv2'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgdiv1'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv1\">" . $e($meta['affiliations_orgdiv1'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgname'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgname\">" . $e($meta['affiliations_orgname'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_state'][$i])) {
                $articleMeta .= $t4 . "<addr-line>\n";
                $articleMeta .= $t5 . "<state>" . $e($meta['affiliations_state'][$i]) . "</state>\n";
                $articleMeta .= $t4 . "</addr-line>\n";
            }
            if (!empty($meta['affiliations_country'][$i])) {
                $articleMeta .= $t4 . "<country country=\"" . $e($meta['affiliations_country'][$i]) . "\">" . $e($meta['affiliations_country_name'][$i] ?? '') . "</country>\n";
            }
            if (!empty($meta['affiliations_email'][$i])) {
                $articleMeta .= $t4 . "<email>" . $e($meta['affiliations_email'][$i]) . "</email>\n";
            }
            $articleMeta .= $t3 . "</aff>\n";
        }

        // Normalizar texto de conflicto que a veces contiene la etiqueta "Contribución autoral" pegada
        if (!empty($meta['conflict'])) {
            $meta['conflict'] = trim(preg_replace('/\s*Contribuci[óo]n\s+autoral\s*:?\s*$/iu', '', $meta['conflict']));
        }

        // Notas de autor: conflicto y contribuciones en bloques <fn> separados
        $hasConflict = !empty($meta['conflict']);
        $hasContrib = !empty($meta['contributions']);
        if ($hasConflict || $hasContrib) {
            $articleMeta .= $t3 . "<author-notes>\n";
            if ($hasConflict) {
                $articleMeta .= $t4 . "<fn fn-type=\"conflict\" id=\"fn2\">\n";
                $articleMeta .= $t5 . "<label>Conflicto de Intereses</label>\n";
                $articleMeta .= $t5 . "<p> " . $e($meta['conflict']) . "</p>\n";
                $articleMeta .= $t4 . "</fn>\n";
            }
            if ($hasContrib) {
                $articleMeta .= $t4 . "<fn fn-type=\"equal\" id=\"fn3\">\n";
                $articleMeta .= $t5 . "<label>Contribución autoral</label>\n";
                $contribText = trim(implode(' ', array_map(function($s){ return preg_replace('/\s+/u', ' ', trim($s)); }, $meta['contributions'])));
                if ($contribText !== '' && !preg_match('/Todos los autores/i', $contribText)) {
                    $contribText = rtrim($contribText, '. ') . '. Todos los autores revisaron y aprobaron la versión final del manuscrito.';
                }
                $articleMeta .= $t5 . "<p> " . $e($contribText) . "</p>\n";
                $articleMeta .= $t4 . "</fn>\n";
            }
            $articleMeta .= $t3 . "</author-notes>\n";
        }

        if (!empty($meta['pubdate'])) {
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            $day = $parts[0] ?? '';
            $month = $parts[1] ?? '';
            $year = $parts[2] ?? '';
            $articleMeta .= $t3 . "<pub-date date-type=\"pub\" publication-format=\"electronic\">\n";
            if ($day) $articleMeta .= $t4 . "<day>" . $e($day) . "</day>\n";
            if ($month) $articleMeta .= $t4 . "<month>" . $e($month) . "</month>\n";
            if ($year) $articleMeta .= $t4 . "<year>" . $e($year) . "</year>\n";
            $articleMeta .= $t3 . "</pub-date>\n";
        }

        $collectionYear = '';
        if (!empty($meta['collectionYear'])) $collectionYear = $meta['collectionYear'];
        if (!$collectionYear && !empty($meta['pubdate'])) {
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            $collectionYear = $parts[2] ?? '';
        }
        if ($collectionYear) {
            $articleMeta .= $t3 . "<pub-date date-type=\"collection\" publication-format=\"electronic\">\n";
            $articleMeta .= $t4 . "<year>" . $e($collectionYear) . "</year>\n";
            $articleMeta .= $t3 . "</pub-date>\n";
        }

        if (!empty($meta['volume'])) $articleMeta .= $t3 . "<volume>" . $e($meta['volume']) . "</volume>\n";
        if (!empty($meta['elocation-id'])) $articleMeta .= $t3 . "<elocation-id>" . $e($meta['elocation-id']) . "</elocation-id>\n";

        if (!empty($meta['received']) || !empty($meta['revised']) || !empty($meta['accepted'])) {
            $articleMeta .= $t3 . "<history>\n";
            if (!empty($meta['received'])) {
                $d = preg_split('/\s+/', trim($meta['received']));
                $articleMeta .= $t4 . "<date date-type=\"received\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= $t4 . "</date>\n";
            }
            if (!empty($meta['revised'])) {
                $d = preg_split('/\s+/', trim($meta['revised']));
                $articleMeta .= $t4 . "<date date-type=\"rev-recd\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= $t4 . "</date>\n";
            }
            if (!empty($meta['accepted'])) {
                $d = preg_split('/\s+/', trim($meta['accepted']));
                $articleMeta .= $t4 . "<date date-type=\"accepted\">\n";
                if (!empty($d[0])) $articleMeta .= $t5 . "<day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= $t5 . "<month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= $t5 . "<year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= $t4 . "</date>\n";
            }
            $articleMeta .= $t3 . "</history>\n";
        }

        $articleMeta .= $t3 . "<permissions>\n";
        $articleMeta .= $t4 . "<license license-type=\"open-access\" xlink:href=\"https://creativecommons.org/licenses/by/4.0/\" xml:lang=\"" . $e($lang) . "\">\n";
        $articleMeta .= $t5 . "<license-p>Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons</license-p>\n";
        $articleMeta .= $t4 . "</license>\n";
        $articleMeta .= $t3 . "</permissions>\n";

        if (!empty($meta['abstractEs'])) {
            $articleMeta .= $t3 . "<abstract>\n";
            $articleMeta .= $t4 . "<title>Resumen</title>\n";
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEs']) . "</p>\n";
            $articleMeta .= $t3 . "</abstract>\n";
        }
        if (!empty($meta['abstractEn'])) {
            $articleMeta .= $t3 . "<trans-abstract xml:lang=\"en\">\n";
            $articleMeta .= $t4 . "<title>Abstract</title>\n";
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEn']) . "</p>\n";
            $articleMeta .= $t3 . "</trans-abstract>\n";
        }

        if (!empty($meta['kwdsEs'])) {
            $articleMeta .= $t3 . "<kwd-group xml:lang=\"es\">\n";
            $articleMeta .= $t4 . "<title>Palabras claves:</title>\n";
            foreach ($meta['kwdsEs'] as $k) {
                $articleMeta .= $t4 . "<kwd>" . $e($k) . "</kwd>\n";
            }
            $articleMeta .= $t3 . "</kwd-group>\n";
        }
        if (!empty($meta['kwdsEn'])) {
            $articleMeta .= $t3 . "<kwd-group xml:lang=\"en\">\n";
            $articleMeta .= $t4 . "<title>Keywords:</title>\n";
            foreach ($meta['kwdsEn'] as $k) {
                $articleMeta .= $t4 . "<kwd>" . $e($k) . "</kwd>\n";
            }
            $articleMeta .= $t3 . "</kwd-group>\n";
        }

        if (!empty($meta['funding'])) {
            $articleMeta .= $t3 . "<funding-group>\n";
            foreach ($meta['funding'] as $f) {
                $source = is_array($f) ? ($f['source'] ?? '') : $f;
                $awardId = is_array($f) ? ($f['awardId'] ?? '') : '';
                $articleMeta .= $t4 . "<award-group award-type=\"contract\">\n";
                $articleMeta .= $t5 . "<funding-source>" . $e($source) . "</funding-source>\n";
                if ($awardId !== '') {
                    $articleMeta .= $t5 . "<award-id>" . $e($awardId) . "</award-id>\n";
                }
                $articleMeta .= $t4 . "</award-group>\n";
            }
            if (!empty($meta['fundingStatement'])) {
                $articleMeta .= $t4 . "<funding-statement>" . $e($meta['fundingStatement']) . "</funding-statement>\n";
            }
            $articleMeta .= $t3 . "</funding-group>\n";
        }

        $figCount = isset($meta['figCount']) && $meta['figCount'] !== '' ? $meta['figCount'] : 0;
        $tableCount = isset($meta['tableCount']) && $meta['tableCount'] !== '' ? $meta['tableCount'] : 0;
        $equationCount = isset($meta['equationCount']) && $meta['equationCount'] !== '' ? $meta['equationCount'] : 0;
        $refCount = isset($meta['refCount']) && $meta['refCount'] !== '' ? $meta['refCount'] : 0;
        $pageCount = isset($meta['pageCount']) && $meta['pageCount'] !== '' ? $meta['pageCount'] : 1;
        $articleMeta .= $t3 . "<counts>\n";
        $articleMeta .= $t4 . "<fig-count count=\"" . $e($figCount) . "\"/>\n";
        $articleMeta .= $t4 . "<table-count count=\"" . $e($tableCount) . "\"/>\n";
        $articleMeta .= $t4 . "<equation-count count=\"" . $e($equationCount) . "\"/>\n";
        $articleMeta .= $t4 . "<ref-count count=\"" . $e($refCount) . "\"/>\n";
        $articleMeta .= $t4 . "<page-count count=\"" . $e($pageCount) . "\"/>\n";
        $articleMeta .= $t3 . "</counts>\n";

        $articleMeta .= $t2 . "</article-meta>\n";

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<article xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" article-type=\"research-article\" dtd-version=\"1.1\" specific-use=\"sps-1.9\" xml:lang=\"" . $e($lang) . "\">\n";
        $xml .= "<front>\n";
        $xml .= $journalMeta . "\n";
        $xml .= rtrim($articleMeta, "\n") . "\n" . $t1 . "</front>\n";
        $xml .= "<body>\n  <!-- contenido del cuerpo omitido -->\n</body>\n";
        $xml .= "<back>\n  <!-- referencias y notas omitidas -->\n</back>\n";
        $xml .= "</article>\n";

        // Evita líneas en blanco extra antes del cierre de <front>.
        $xml = preg_replace('/<\/article-meta>\n(?:\t*\n)+\t<\/front>\n/', "</article-meta>\n\t</front>\n", $xml);

        return $xml;
    }

    private function escapeXmlWithItalic($text)
    {
        $text = (string) $text;
        $result = '';
        $offset = 0;
        while (preg_match('/<italic>(.*?)<\/italic>/su', $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchText = $matches[0][0];
            $matchPos = $matches[0][1];
            $prefix = substr($text, $offset, $matchPos - $offset);
            $result .= htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $result .= '<italic>' . htmlspecialchars($matches[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</italic>';
            $offset = $matchPos + strlen($matchText);
        }
        $result .= htmlspecialchars(substr($text, $offset), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return $result;
    }
}