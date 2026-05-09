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
                }
                $pendingAuthorName = '';
                continue;
            }

            foreach (preg_split('/\s*;\s*/u', $lineTrim) as $authorChunk) {
                $authorChunk = $normalizeAuthorName($authorChunk);
                if ($authorChunk === '') {
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
        foreach ($clean as $line) {
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
                    $meta['contributions'][] = $contribution;
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
                    $meta['contributions'][] = $tail;
                }
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
                    $meta['contributions'][] = $lineTrim;
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

        return $meta;
    }

    public function buildJatsXml(array $meta)
    {
        // Esta función toma los metadatos ya extraídos y genera el XML JATS.
        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };

        $doi = $meta['doi'] ?? '';
        $lang = $meta['lang'] ?? 'es';

        $journalMeta = "  <journal-meta>\n";
        $journalMeta .= "    <journal-id journal-id-type=\"nlm-ta\">" . $e($meta['journalAbbrev'] ?? $meta['journalTitle'] ?? '') . "</journal-id>\n";
        if (!empty($meta['journalIdPublisher'])) {
            $journalMeta .= "    <journal-id journal-id-type=\"publisher-id\">" . $e($meta['journalIdPublisher']) . "</journal-id>\n";
        }
        $journalMeta .= "    <journal-title-group>\n";
        $journalMeta .= "      <journal-title>" . $e($meta['journalTitle'] ?? '') . "</journal-title>\n";
        $journalMeta .= "      <abbrev-journal-title abbrev-type=\"publisher\">" . $e($meta['journalAbbrev'] ?? '') . "</abbrev-journal-title>\n";
        $journalMeta .= "    </journal-title-group>\n";
        if (!empty($meta['issn_ppub'])) $journalMeta .= "    <issn pub-type=\"ppub\">" . $e($meta['issn_ppub']) . "</issn>\n";
        if (!empty($meta['issn_epub'])) $journalMeta .= "    <issn pub-type=\"epub\">" . $e($meta['issn_epub']) . "</issn>\n";
        $journalMeta .= "    <publisher>\n";
        $journalMeta .= "      <publisher-name>" . $e($meta['publisher'] ?? '') . "</publisher-name>\n";
        $journalMeta .= "    </publisher>\n";
        $journalMeta .= "  </journal-meta>";

        $articleMeta = "  <article-meta>\n";
        if ($doi) $articleMeta .= "    <article-id pub-id-type=\"doi\">" . $e($doi) . "</article-id>\n";
        $articleMeta .= "    <article-categories>\n";
        $articleMeta .= "      <subj-group subj-group-type=\"heading\">\n";
        $articleMeta .= "        <subject>Artículo</subject>\n";
        $articleMeta .= "      </subj-group>\n";
        $articleMeta .= "    </article-categories>\n";
        $articleMeta .= "    <title-group>\n";
        $articleMeta .= "      <article-title>" . $e($meta['articleTitle'] ?? '') . "</article-title>\n";
        if (!empty($meta['articleTitleEn'])) {
            $articleMeta .= "      <trans-title-group xml:lang=\"en\">\n";
            $articleMeta .= "        <trans-title>" . $e($meta['articleTitleEn']) . "</trans-title>\n";
            $articleMeta .= "      </trans-title-group>\n";
        }
        $articleMeta .= "    </title-group>\n";

        $articleMeta .= "    <contrib-group>\n";
        // Contribuciones de autor: cada autor se transforma en un contrib-type="author".
        foreach (($meta['authors'] ?? []) as $i => $a) {
            $name = trim($a['name'] ?? '');
            if ($name === '') continue;
            $parts = preg_split('/\s+/', $name);
            $surname = array_pop($parts);
            $given = implode(' ', $parts);
            $n = $i + 1;
            $articleMeta .= "      <contrib contrib-type=\"author\">\n";
            if (!empty($a['orcid'])) {
                $articleMeta .= "        <contrib-id contrib-id-type=\"orcid\">https://orcid.org/" . $e($a['orcid']) . "</contrib-id>\n";
            }
            $articleMeta .= "        <name>\n";
            $articleMeta .= "          <surname>" . $e($surname) . "</surname>\n";
            $articleMeta .= "          <given-names>" . $e($given) . "</given-names>\n";
            $articleMeta .= "        </name>\n";
            $articleMeta .= "        <xref ref-type=\"aff\" rid=\"aff" . $n . "\"><sup>" . $n . "</sup></xref>\n";
            $articleMeta .= "      </contrib>\n";
        }
        $articleMeta .= "    </contrib-group>\n";

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
            $articleMeta .= "    <aff id=\"aff" . $n . "\">\n";
            $articleMeta .= "      <label>" . $n . "</label>\n";
            $articleMeta .= "      <institution content-type=\"original\">" . $e($affOriginal) . "</institution>\n";
            if (!empty($meta['affiliations_norm'][$i])) {
                $articleMeta .= "      <institution content-type=\"normalized\">" . $e($meta['affiliations_norm'][$i]) . "</institution>\n";
            }
            // orgdiv2 (departamento) va antes que orgdiv1 cuando ambos existen.
            if (!empty($meta['affiliations_orgdiv2'][$i])) {
                $articleMeta .= "      <institution content-type=\"orgdiv2\">" . $e($meta['affiliations_orgdiv2'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgdiv1'][$i])) {
                $articleMeta .= "      <institution content-type=\"orgdiv1\">" . $e($meta['affiliations_orgdiv1'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgname'][$i])) {
                $articleMeta .= "      <institution content-type=\"orgname\">" . $e($meta['affiliations_orgname'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_state'][$i])) {
                $articleMeta .= "      <addr-line>\n";
                $articleMeta .= "        <state>" . $e($meta['affiliations_state'][$i]) . "</state>\n";
                $articleMeta .= "      </addr-line>\n";
            }
            if (!empty($meta['affiliations_country'][$i])) {
                $articleMeta .= "      <country country=\"" . $e($meta['affiliations_country'][$i]) . "\">" . $e($meta['affiliations_country_name'][$i] ?? '') . "</country>\n";
            }
            if (!empty($meta['affiliations_email'][$i])) {
                $articleMeta .= "      <email>" . $e($meta['affiliations_email'][$i]) . "</email>\n";
            }
            $articleMeta .= "    </aff>\n";
        }

        // Notas de autor: conflicto y contribuciones en bloques <fn> separados
        $hasConflict = !empty($meta['conflict']);
        $hasContrib = !empty($meta['contributions']);
        if ($hasConflict || $hasContrib) {
            $articleMeta .= "    <author-notes>\n";
            if ($hasConflict) {
                $articleMeta .= "      <fn fn-type=\"conflict\" id=\"fn2\">\n";
                $articleMeta .= "        <label>Conflicto de Intereses</label>\n";
                $articleMeta .= "        <p>" . $e($meta['conflict']) . "</p>\n";
                $articleMeta .= "      </fn>\n";
            }
            if ($hasContrib) {
                $articleMeta .= "      <fn fn-type=\"equal\" id=\"fn3\">\n";
                $articleMeta .= "        <label>Contribución autoral</label>\n";
                $articleMeta .= "        <p>" . $e(implode(' ', $meta['contributions'])) . "</p>\n";
                $articleMeta .= "      </fn>\n";
            }
            $articleMeta .= "    </author-notes>\n";
        }

        if (!empty($meta['pubdate'])) {
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            $day = $parts[0] ?? '';
            $month = $parts[1] ?? '';
            $year = $parts[2] ?? '';
            $articleMeta .= "    <pub-date date-type=\"pub\" publication-format=\"electronic\">\n";
            if ($day) $articleMeta .= "      <day>" . $e($day) . "</day>\n";
            if ($month) $articleMeta .= "      <month>" . $e($month) . "</month>\n";
            if ($year) $articleMeta .= "      <year>" . $e($year) . "</year>\n";
            $articleMeta .= "    </pub-date>\n";
        }

        $collectionYear = '';
        if (!empty($meta['collectionYear'])) $collectionYear = $meta['collectionYear'];
        if (!$collectionYear && !empty($meta['pubdate'])) {
            $parts = preg_split('/\s+/', trim($meta['pubdate']));
            $collectionYear = $parts[2] ?? '';
        }
        if ($collectionYear) {
            $articleMeta .= "    <pub-date date-type=\"collection\" publication-format=\"electronic\">\n";
            $articleMeta .= "      <year>" . $e($collectionYear) . "</year>\n";
            $articleMeta .= "    </pub-date>\n";
        }

        if (!empty($meta['volume'])) $articleMeta .= "    <volume>" . $e($meta['volume']) . "</volume>\n";
        if (!empty($meta['elocation-id'])) $articleMeta .= "    <elocation-id>" . $e($meta['elocation-id']) . "</elocation-id>\n";

        if (!empty($meta['received']) || !empty($meta['revised']) || !empty($meta['accepted'])) {
            $articleMeta .= "    <history>\n";
            if (!empty($meta['received'])) {
                $d = preg_split('/\s+/', trim($meta['received']));
                $articleMeta .= "      <date date-type=\"received\">\n";
                if (!empty($d[0])) $articleMeta .= "        <day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= "        <month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= "        <year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= "      </date>\n";
            }
            if (!empty($meta['revised'])) {
                $d = preg_split('/\s+/', trim($meta['revised']));
                $articleMeta .= "      <date date-type=\"rev-recd\">\n";
                if (!empty($d[0])) $articleMeta .= "        <day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= "        <month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= "        <year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= "      </date>\n";
            }
            if (!empty($meta['accepted'])) {
                $d = preg_split('/\s+/', trim($meta['accepted']));
                $articleMeta .= "      <date date-type=\"accepted\">\n";
                if (!empty($d[0])) $articleMeta .= "        <day>" . $e($d[0]) . "</day>\n";
                if (!empty($d[1])) $articleMeta .= "        <month>" . $e($d[1]) . "</month>\n";
                if (!empty($d[2])) $articleMeta .= "        <year>" . $e($d[2]) . "</year>\n";
                $articleMeta .= "      </date>\n";
            }
            $articleMeta .= "    </history>\n";
        }

        $articleMeta .= "    <permissions>\n";
        $articleMeta .= "      <license license-type=\"open-access\" xlink:href=\"https://creativecommons.org/licenses/by/4.0/\" xml:lang=\"" . $e($lang) . "\">\n";
        $articleMeta .= "        <license-p>Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons</license-p>\n";
        $articleMeta .= "      </license>\n";
        $articleMeta .= "    </permissions>\n";

        if (!empty($meta['abstractEs'])) {
            $articleMeta .= "    <abstract>\n";
            $articleMeta .= "      <title>Resumen</title>\n";
            $articleMeta .= "      <p>" . $this->escapeXmlWithItalic($meta['abstractEs']) . "</p>\n";
            $articleMeta .= "    </abstract>\n";
        }
        if (!empty($meta['abstractEn'])) {
            $articleMeta .= "    <trans-abstract xml:lang=\"en\">\n";
            $articleMeta .= "      <title>Abstract</title>\n";
            $articleMeta .= "      <p>" . $this->escapeXmlWithItalic($meta['abstractEn']) . "</p>\n";
            $articleMeta .= "    </trans-abstract>\n";
        }

        if (!empty($meta['kwdsEs'])) {
            $articleMeta .= "    <kwd-group xml:lang=\"es\">\n";
            $articleMeta .= "      <title>Palabras claves:</title>\n";
            foreach ($meta['kwdsEs'] as $k) {
                $articleMeta .= "      <kwd>" . $e($k) . "</kwd>\n";
            }
            $articleMeta .= "    </kwd-group>\n";
        }
        if (!empty($meta['kwdsEn'])) {
            $articleMeta .= "    <kwd-group xml:lang=\"en\">\n";
            $articleMeta .= "      <title>Keywords:</title>\n";
            foreach ($meta['kwdsEn'] as $k) {
                $articleMeta .= "      <kwd>" . $e($k) . "</kwd>\n";
            }
            $articleMeta .= "    </kwd-group>\n";
        }

        if (!empty($meta['funding'])) {
            $articleMeta .= "    <funding-group>\n";
            foreach ($meta['funding'] as $f) {
                $source = is_array($f) ? ($f['source'] ?? '') : $f;
                $awardId = is_array($f) ? ($f['awardId'] ?? '') : '';
                $articleMeta .= "      <award-group award-type=\"contract\">\n";
                $articleMeta .= "        <funding-source>" . $e($source) . "</funding-source>\n";
                if ($awardId !== '') {
                    $articleMeta .= "        <award-id>" . $e($awardId) . "</award-id>\n";
                }
                $articleMeta .= "      </award-group>\n";
            }
            if (!empty($meta['fundingStatement'])) {
                $articleMeta .= "      <funding-statement>" . $e($meta['fundingStatement']) . "</funding-statement>\n";
            }
            $articleMeta .= "    </funding-group>\n";
        }

        $figCount = isset($meta['figCount']) && $meta['figCount'] !== '' ? $meta['figCount'] : 0;
        $tableCount = isset($meta['tableCount']) && $meta['tableCount'] !== '' ? $meta['tableCount'] : 0;
        $equationCount = isset($meta['equationCount']) && $meta['equationCount'] !== '' ? $meta['equationCount'] : 0;
        $refCount = isset($meta['refCount']) && $meta['refCount'] !== '' ? $meta['refCount'] : 0;
        $pageCount = isset($meta['pageCount']) && $meta['pageCount'] !== '' ? $meta['pageCount'] : 1;
        $articleMeta .= "    <counts>\n";
        $articleMeta .= "      <fig-count count=\"" . $e($figCount) . "\"/>\n";
        $articleMeta .= "      <table-count count=\"" . $e($tableCount) . "\"/>\n";
        $articleMeta .= "      <equation-count count=\"" . $e($equationCount) . "\"/>\n";
        $articleMeta .= "      <ref-count count=\"" . $e($refCount) . "\"/>\n";
        $articleMeta .= "      <page-count count=\"" . $e($pageCount) . "\"/>\n";
        $articleMeta .= "    </counts>\n";

        $articleMeta .= "  </article-meta>\n";

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<!DOCTYPE article PUBLIC \"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20121330//EN\"\n";
        $xml .= "  \"https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1-1.dtd\">\n";
        $xml .= "<article xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" article-type=\"research-article\" dtd-version=\"1.1\" specific-use=\"sps-1.9\" xml:lang=\"" . $e($lang) . "\">\n";
        $xml .= "<front>\n";
        $xml .= $journalMeta . "\n\n";
        $xml .= $articleMeta . "</front>\n";
        $xml .= "<body>\n  <!-- contenido del cuerpo omitido -->\n</body>\n";
        $xml .= "<back>\n  <!-- referencias y notas omitidas -->\n</back>\n";
        $xml .= "</article>\n";

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