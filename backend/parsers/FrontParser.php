<?php

require_once __DIR__ . '/EscapeXmlTrait.php';

class FrontParser
{
    use EscapeXmlTrait;

    public function parse(array $lines, array $defaults = [])
    {
        $meta = $this->initMeta();

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
            'dic' => '12', 'diciembre' => '12'
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

        $clean = array_values(array_filter(array_map(function ($v) {
            return trim(strip_tags($v));
        }, $lines), function ($v) {
            return $v !== '';
        }));

        $rawClean = array_values(array_filter(array_map('trim', $lines), function ($v) {
            return $v !== '';
        }));

        // Header block detection
        $meta['hasHeaderBlock'] = false;
        for ($i = 0; $i < count($clean); $i++) {
            if (preg_match('/^sps-?1\.9$/i', $clean[$i])) {
                $meta['hasHeaderBlock'] = true;
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

        // ISSN
        $issns = [];
        foreach ($clean as $line) {
            if (stripos($line, 'orcid') !== false) continue;
            if (preg_match_all('/\b\d{4}-\d{3}[\dxX]\b/u', $line, $matches) && !empty($matches[0]) && is_array($matches[0])) {
                foreach ($matches[0] as $issn) {
                    $issns[] = $issn;
                }
            }
        }
        if (isset($issns[0])) $meta['issn_ppub'] = $issns[0];
        if (isset($issns[1])) $meta['issn_epub'] = $issns[1];

        // Publisher
        for ($i = 0; $i < count($clean); $i++) {
            if (stripos($clean[$i], 'orcid') !== false) continue;
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $clean[$i])) {
                $meta['publisher'] = $clean[$i + 2] ?? $meta['publisher'];
                break;
            }
        }
        if (!empty($meta['publisher']) && stripos($meta['publisher'], 'orcid') !== false) {
            $meta['publisher'] = '';
        }

        // Titles around DOI
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
                if ($line === '') continue;
                if (preg_match('/^art[ií]culo$/i', $line) || mb_strtolower($line) === 'artículo') continue;
                if (preg_match('/^https?:\/\/orcid\.org\//i', $line)) continue;
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

        // Fallback title detection via "Artículo" label
        if ($meta['articleTitle'] === '' || preg_match('/^art[ií]culo$/i', $meta['articleTitle'])) {
            $seenArticleLabel = false;
            foreach ($clean as $line) {
                $lineTrim = trim($line);
                if ($lineTrim === '') continue;
                if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') {
                    $seenArticleLabel = true;
                    continue;
                }
                if (!$seenArticleLabel) continue;
                if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) continue;
                if (preg_match('/^(?:RESUMEN|Resumen)\s*:?/i', $lineTrim) || preg_match('/^(?:ABSTRACT|Abstract)\s*:?/i', $lineTrim)) continue;
                if (preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?/i', $lineTrim) || preg_match('/^Keywords\s*:?/i', $lineTrim)) continue;
                if (preg_match('/https?:\/\/orcid\.org\//i', $lineTrim)) continue;
                if (mb_strlen($lineTrim) >= 40) {
                    $meta['articleTitle'] = $lineTrim;
                    break;
                }
            }
        }

        // Authors
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
            if (!preg_match('/\p{L}/u', $text)) return false;
            if (preg_match('/https?:\/\/|orcid\.org|@|doi\b/i', $text)) return false;
            if (preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $text)) return false;
            return preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\s\-\'\x{2019}\.\,;\(\)\d\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+$/u', $text) === 1;
        };

        foreach ($rawClean as $line) {
            $lineTrim = trim(strip_tags($line));
            if ($lineTrim === '') continue;

            if (preg_match('/^art[ií]culo$/i', $lineTrim) || mb_strtolower($lineTrim) === 'artículo') continue;

            if (!$seenDoi && preg_match('/10\.\d{4,9}\/\S+/u', $lineTrim)) {
                $seenDoi = true;
                continue;
            }
            if (!$seenDoi) continue;

            if (preg_match('/^(?:RESUMEN|ABSTRACT|Resumen|Abstract|(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)|Keywords|Financiamiento|Referencias bibliogr(?:a?ficas)?|Referencias|References?|Introducción|Introduction)\b/i', $lineTrim)) {
                $authorArea = false;
                $authorAreaClosed = true;
                continue;
            }
            if ($authorAreaClosed) continue;

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
                if (!$candidateLine) continue;
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
                if ($authorChunk === '') continue;
                if (preg_match('/^art[ií]culo$/i', $authorChunk) || mb_strtolower($authorChunk) === 'artículo') continue;
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

        // Affiliations
        $lastAffIndex = -1;
        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '') continue;
            if (preg_match('/10\.\d{4,9}\//u', $lineTrim)) continue;
            if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $lineTrim)) continue;

            if (preg_match('/^(\d+)\s*(.+)$/u', $lineTrim, $m)) {
                $affText = trim($m[2]);
                $authorCount = count($meta['authors'] ?? []);
                if ($authorCount > 0 && (int)$m[1] > $authorCount) continue;
                if (preg_match('/\[Internet\]|\[citado|Disponible en:|https?:\/\//iu', $affText)) continue;
                if (preg_match('/\b\d{4}-\d{3}[\dxX]\b/u', $affText)) continue;
                if (preg_match('/10\.\d{4,9}\//u', $affText)) continue;
                if (!preg_match('/[\p{L}]/u', $affText)) continue;

                $meta['affiliations'][] = $affText;
                $meta['affiliations_lineindex'][] = $idx;
                $lastAffIndex = count($meta['affiliations']) - 1;

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

                if ($orgdiv1 === '' && preg_match('/(Instituto[^.;,]+)/iu', $affText, $minst)) {
                    $orgdiv1 = trim($minst[1]);
                }

                $meta['affiliations_orgdiv1'][] = $orgdiv1;
                $meta['affiliations_orgdiv2'][] = $orgdiv2;

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
                    'argentina' => 'AR', 'brasil' => 'BR', 'brazil' => 'BR',
                    'chile' => 'CL', 'colombia' => 'CO',
                    'méxico' => 'MX', 'mexico' => 'MX',
                    'españa' => 'ES', 'spain' => 'ES',
                    'uruguay' => 'UY', 'paraguay' => 'PY',
                    'perú' => 'PE', 'peru' => 'PE',
                    'ecuador' => 'EC', 'venezuela' => 'VE',
                    'bolivia' => 'BO', 'cuba' => 'CU',
                    'costa rica' => 'CR', 'panamá' => 'PA', 'panama' => 'PA',
                    'puerto rico' => 'PR',
                    'ee.uu.' => 'US', 'usa' => 'US', 'estados unidos' => 'US', 'united states' => 'US',
                    'portugal' => 'PT', 'italia' => 'IT', 'italy' => 'IT',
                    'francia' => 'FR', 'france' => 'FR',
                    'reino unido' => 'GB', 'uk' => 'GB', 'united kingdom' => 'GB',
                    'alemania' => 'DE', 'germany' => 'DE'
                ];

                $countryNames = [
                    'AR' => 'Argentina', 'BR' => 'Brazil', 'CL' => 'Chile',
                    'CO' => 'Colombia', 'MX' => 'Mexico', 'ES' => 'Spain',
                    'UY' => 'Uruguay', 'PY' => 'Paraguay', 'PE' => 'Peru',
                    'EC' => 'Ecuador', 'VE' => 'Venezuela', 'BO' => 'Bolivia',
                    'CU' => 'Cuba', 'CR' => 'Costa Rica', 'PA' => 'Panama',
                    'PR' => 'Puerto Rico', 'US' => 'United States',
                    'PT' => 'Portugal', 'IT' => 'Italy', 'FR' => 'France',
                    'GB' => 'United Kingdom', 'DE' => 'Germany'
                ];

                if (count($parts) >= 1) {
                    $possibleCountry = end($parts);
                    $lowerCountry = mb_strtolower($possibleCountry);
                    if (isset($countryMap[$lowerCountry])) {
                        array_pop($parts);
                        $country = $possibleCountry;
                        $countryCode = $countryMap[$lowerCountry];
                    }
                }

                $geo = '';
                if (count($parts) >= 1) {
                    $lastPart = end($parts);
                    if (!preg_match('/\b(Universidad|Universidade|University|Instituto|Institute|Departamento|Department|Programa|Faculty|Facultad|Centro|Hospital|Laboratorio|Lab\.?)/iu', $lastPart)) {
                        $geo = array_pop($parts);
                    }
                }

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

        // Fallback email linking
        $allEmails = [];
        foreach ($rawClean as $rline) {
            if (preg_match_all('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $m)) {
                foreach ($m[1] as $me) {
                    $allEmails[] = $me;
                }
            }
        }
        $allEmails = array_values(array_unique($allEmails));

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
            if ($assigned === '' && !empty($authorAffMap)) {
                $affNumber = $i + 1;
                foreach ($authorAffMap as $ai => $anum) {
                    if ($anum === $affNumber) {
                        $a = $meta['authors'][$ai] ?? null;
                        if ($a) {
                            $parts = preg_split('/\s+/', $a['name']);
                            $surname = array_pop($parts);
                            foreach ($rawClean as $rline) {
                                if (stripos($rline, $surname) !== false && preg_match('/([\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/u', $rline, $mm2)) {
                                    $assigned = $mm2[1];
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
            if ($assigned === '' && count($allEmails) === 1) {
                $assigned = $allEmails[0];
            }
            if ($assigned !== '') {
                $meta['affiliations_email'][$i] = $assigned;
            }
        }

        // Abstracts, keywords, funding, conflict, contributions
        $state = '';
        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            $rawLineTrim = trim($rawClean[$idx] ?? $lineTrim);

            if (preg_match('/^(?:RESUMEN|Resumen)\s*:?\s*/i', $lineTrim)) {
                $state = 'abstract_es';
                $meta['abstractEs'] = trim(preg_replace('/^(?:RESUMEN|Resumen)\s*:?\s*/i', '', $rawLineTrim));
                continue;
            }
            if (preg_match('/^(?:ABSTRACT|Abstract)\s*:?\s*/i', $lineTrim)) {
                $state = 'abstract_en';
                $meta['abstractEn'] = trim(preg_replace('/^(?:ABSTRACT|Abstract)\s*:?\s*/i', '', $rawLineTrim));
                continue;
            }
            if (preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?\s*/i', $lineTrim)) {
                $state = 'kwds_es';
                $kw = trim(preg_replace('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?\s*/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEs'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                continue;
            }
            if (preg_match('/^Keywords\s*:?\s*/i', $lineTrim)) {
                $state = 'kwds_en';
                $kw = trim(preg_replace('/^Keywords\s*:?\s*/i', '', $lineTrim));
                if ($kw !== '') $meta['kwdsEn'] = array_filter(array_map('trim', preg_split('/[;,]/', $kw)));
                continue;
            }
            if (preg_match('/^Financiamiento$/i', $lineTrim)) {
                $state = 'funding';
                continue;
            }
            if (preg_match('/^Agradecimiento(?:s)?$/i', $lineTrim)) {
                $state = 'ack';
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
                if (!preg_match('/^(?:PALABRAS\s+CLAVES?|Palabras\s+claves?)\s*:?/i', $lineTrim)) {
                    $meta['abstractEs'] = trim($meta['abstractEs'] . ' ' . $rawLineTrim);
                }
            } elseif ($state === 'abstract_en') {
                if (!preg_match('/^Keywords\s*:?/i', $lineTrim)) {
                    $meta['abstractEn'] = trim($meta['abstractEn'] . ' ' . $rawLineTrim);
                }
            } elseif ($state === 'funding') {
                if (!preg_match('/^(?:Conflicto de Intereses|Agradecimiento)/i', $lineTrim)) {
                    $meta['fundingStatement'] = trim($meta['fundingStatement'] . ' ' . $lineTrim);
                }
            } elseif ($state === 'ack') {
                if (!preg_match('/^(?:Financiamiento|Conflicto de Intereses|Contribuci[óo]n)/i', $lineTrim)) {
                    $meta['ack'] = trim(($meta['ack'] ?? '') . ' ' . $lineTrim);
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
                    if (preg_match('/^([\p{L}\p{M}\s\.\-\'’]+):\s*(.+)$/u', $lineTrim, $mnc)) {
                        $meta['contributions'][] = trim($mnc[1] . ': ' . $mnc[2]);
                    } else {
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

        if (!isset($meta['ack'])) $meta['ack'] = '';

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

        // Dates
        $refStart = -1;
        foreach ($clean as $idx => $line) {
            if (preg_match('/^Referencias bibliogr/i', $line)) {
                $refStart = $idx;
                break;
            }
        }

        foreach ($clean as $idx => $line) {
            $lineTrim = trim($line);
            $isEditorialDateLine = preg_match('/^(Recibido|Recebido|Received|Versi[oó]n\s+final|Revisado|Revised|Aprobado|Aceptado|Aceito|Accepted|Publicado|Publicaci[oó]n|Publication|Volumen|Elocation\-id|P[aá]ginas|Pages)\s*:/iu', $lineTrim);
            if ($refStart !== -1 && $idx >= $refStart && !$isEditorialDateLine) continue;

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
            if ($meta['articleIdOther'] === '' && preg_match('/^(?:ID\s*artículo|Article\s*ID|Número\s*artículo|ID)\s*:\s*(\S+)/i', $lineTrim, $mOther)) {
                $meta['articleIdOther'] = trim($mOther[1]);
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

        // Body sections
        $meta['bodySections'] = [];
        $meta['sections'] = [];
        $currentSec = null;
        $inBody = false;

        foreach ($rawClean as $line) {
            $lineTrim = trim(strip_tags($line));
            if ($lineTrim === '') continue;

            $isTitle = false;
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

            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?|Financiamiento|Conflicto de Intereses|Contribuci[óo]n autoral|Agradecimiento(?:s)?)\b/iu', $lineTrim)) {
                $inBody = false;
                if ($currentSec) {
                    $meta['bodySections'][] = $currentSec;
                    $currentSec = null;
                }
                continue;
            }

            if ($inBody && $currentSec) {
                if (!preg_match('/^(10\.\d{4,9}\/|https?:\/\/orcid\.org\/|[\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/iu', $lineTrim)) {
                    $currentSec['paragraphs'][] = $line;
                }
            }
        }
        if ($currentSec) $meta['bodySections'][] = $currentSec;
        $meta['sections'] = array_values(array_unique($meta['sections']));

        if (!empty($meta['accepted'])) {
            $parts = preg_split('/\s+/', $meta['accepted']);
            $meta['collectionYear'] = $parts[2] ?? '';
        }

        $meta['kwdsEs'] = array_values(array_filter(array_map(function ($v) {
            return rtrim(trim($v), '.');
        }, $meta['kwdsEs'])));
        $meta['kwdsEn'] = array_values(array_filter(array_map(function ($v) {
            return rtrim(trim($v), '.');
        }, $meta['kwdsEn'])));

        // References extraction
        $inRefs = false;
        foreach ($rawClean as $line) {
            $linePlain = trim(strip_tags($line));
            if (preg_match('/^(Referencias bibliogr(?:a?ficas)?|Referencias|References?)\b/i', $linePlain)) {
                $inRefs = true;
                continue;
            }
            if ($inRefs) {
                if (preg_match('/^(Recibido|Recebido|Received|Versi[oó]n|Revisado|Revised|Aprobado|Aceptado|Aceito|Accepted|Publicado|Publicaci[oó]n|Publication|Conflicto|Contribuci[óo]n)\s*:/iu', $linePlain)) {
                    $inRefs = false;
                    continue;
                }
                $refText = preg_replace('/^(?:\[\d+\]|\d+[\.\)])\s+/u', '', $line);
                if (trim(strip_tags($refText)) !== '') {
                    $meta['references'][] = $refText;
                }
            }
        }
        $meta['refCount'] = (string)count($meta['references']);

        if (!empty($meta['references'])) {
            foreach ($meta['references'] as $ri => $rtext) {
                $meta['references'][$ri] = $this->normalizeReferencePagesString($rtext);
            }
        }

        // Table count
        $tableCount = 0;
        foreach ($lines as $line) {
            if (strpos($line, '<table-wrap>') !== false) $tableCount++;
        }
        $meta['tableCount'] = (string)$tableCount;

        $meta['tableWraps'] = [];
        foreach ($lines as $line) {
            if (strpos($line, '<table-wrap>') !== false) $meta['tableWraps'][] = $line;
        }

        $meta['abstractEs'] = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $meta['abstractEs'] ?? '');
        $meta['abstractEn'] = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $meta['abstractEn'] ?? '');

        $meta['includeBio'] = empty($meta['hasHeaderBlock']);

        $meta = $this->applyKnownFrontMetadata($meta);
        $meta = $this->applySaludColectivaDefaults($meta);
        $meta = $this->applyPatternFallback($meta);

        foreach ($defaults as $key => $value) {
            if ($value !== '' && isset($meta[$key])) {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    public function buildFrontXml(array $meta): array
    {
        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $t1 = "\t"; $t2 = "\t\t"; $t3 = "\t\t\t"; $t4 = "\t\t\t\t"; $t5 = "\t\t\t\t\t"; $t6 = "\t\t\t\t\t\t";

        $doi = $meta['doi'] ?? '';
        $lang = $meta['lang'] ?? 'es';

        // journal-meta
        $journalMeta = $t2 . "<journal-meta>\n";
        $journalId = $meta['journalIdPublisher'] ?? strtolower($meta['journalAbbrev'] ?? 'scol');
        $journalMeta .= $t3 . "<journal-id journal-id-type=\"nlm-ta\">" . $e($meta['journalAbbrev'] ?? '') . "</journal-id>\n";
        $journalMeta .= $t3 . "<journal-id journal-id-type=\"publisher-id\">" . $e($journalId) . "</journal-id>\n";
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

        // article-meta
        $articleMeta = $t2 . "<article-meta>\n";
        if ($doi) $articleMeta .= $t3 . "<article-id pub-id-type=\"doi\">" . $e($doi) . "</article-id>\n";
        if (!empty($meta['articleIdOther'])) {
            $articleMeta .= $t3 . "<article-id pub-id-type=\"other\">" . $e($meta['articleIdOther']) . "</article-id>\n";
        }
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
        foreach (($meta['authors'] ?? []) as $i => $a) {
            $name = trim($a['name'] ?? '');
            if ($name === '') continue;
            $parts = preg_split('/\s+/', $name);
            $surname = array_pop($parts);
            $given = implode(' ', $parts);
            $n = $i + 1;
            $articleMeta .= $t4 . "<contrib contrib-type=\"author\">\n";
            $orcidValue = $a['orcid'] ?? '';
            if (!empty($orcidValue)) {
                $orcidOut = empty($meta['hasHeaderBlock'])
                    ? $e($orcidValue)
                    : "https://orcid.org/" . $e($orcidValue);
                $articleMeta .= $t5 . "<contrib-id contrib-id-type=\"orcid\">" . $orcidOut . "</contrib-id>\n";
            }
            $articleMeta .= $t5 . "<name>\n";
            $articleMeta .= $t6 . "<surname>" . $e($surname) . "</surname>\n";
            $articleMeta .= $t6 . "<given-names>" . $e($given) . "</given-names>\n";
            $articleMeta .= $t5 . "</name>\n";
            if (!empty($meta['includeBio'])) {
                $bioText = $meta['affiliations'][$i] ?? '';
                $affEmailBio = $meta['affiliations_email'][$i] ?? '';
                if ($bioText !== '') {
                    if ($affEmailBio !== '' && stripos($bioText, $affEmailBio) === false) {
                        $bioText = rtrim($bioText, " ;") . '. ' . $affEmailBio . ' ';
                    }
                    if (substr($bioText, -1) !== ' ') $bioText .= ' ';
                    $articleMeta .= $t5 . "<bio>" . $e($bioText) . "</bio>\n";
                }
            }
            $articleMeta .= $t5 . "<xref ref-type=\"aff\" rid=\"aff" . $n . "\"><sup>" . $n . "</sup></xref>\n";
            $articleMeta .= $t4 . "</contrib>\n";
        }
        $articleMeta .= $t3 . "</contrib-group>\n";

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
            if (empty($meta['hasHeaderBlock']) && substr($affOriginal, -1) !== ' ') {
                $affOriginal .= ' ';
            }
            $articleMeta .= $t3 . "<aff id=\"aff" . $n . "\">\n";
            $articleMeta .= $t4 . "<label>" . $n . "</label>\n";
            $articleMeta .= $t4 . "<institution content-type=\"original\">" . $e($affOriginal) . "</institution>\n";
            if (!empty($meta['affiliations_norm'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"normalized\">" . $e($meta['affiliations_norm'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgdiv2'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv2\">" . $e($meta['affiliations_orgdiv2'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgdiv1'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgdiv1\">" . $e($meta['affiliations_orgdiv1'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_orgname'][$i])) {
                $articleMeta .= $t4 . "<institution content-type=\"orgname\">" . $e($meta['affiliations_orgname'][$i]) . "</institution>\n";
            }
            if (!empty($meta['affiliations_city'][$i]) || !empty($meta['affiliations_state'][$i])) {
                $articleMeta .= $t4 . "<addr-line>\n";
                if (!empty($meta['affiliations_city'][$i])) {
                    $articleMeta .= $t5 . "<city>" . $e($meta['affiliations_city'][$i]) . "</city>\n";
                }
                if (!empty($meta['affiliations_state'][$i])) {
                    $articleMeta .= $t5 . "<state>" . $e($meta['affiliations_state'][$i]) . "</state>\n";
                }
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

        if (!empty($meta['conflict'])) {
            $meta['conflict'] = trim(preg_replace('/\s*Contribuci[óo]n\s+autoral\s*:?\s*$/iu', '', $meta['conflict']));
        }

        $hasConflict = !empty($meta['conflict']);
        $hasContrib = !empty($meta['contributions']);
        if ($hasConflict || $hasContrib) {
            $articleMeta .= $t3 . "<author-notes>\n";
            if ($hasConflict) {
                $articleMeta .= $t4 . "<fn fn-type=\"conflict\" id=\"fn2\">\n";
                $articleMeta .= $t5 . "<label>" . (empty($meta['hasHeaderBlock']) ? "Conflicto de intereses" : "Conflicto de Intereses") . "</label>\n";
                $articleMeta .= $t5 . "<p> " . $e($meta['conflict']) . "</p>\n";
                $articleMeta .= $t4 . "</fn>\n";
            }
            if ($hasContrib) {
                $articleMeta .= $t4 . "<fn fn-type=\"equal\" id=\"fn3\">\n";
                $articleMeta .= $t5 . "<label>Contribución autoral</label>\n";
                $contribText = trim(implode(' ', array_map(function($s){ return preg_replace('/\s+/u', ' ', trim($s)); }, $meta['contributions'])));
                if (!empty($meta['hasHeaderBlock']) && $contribText !== '' && !preg_match('/Todos los autores/i', $contribText)) {
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
            if (empty($meta['hasHeaderBlock']) && !empty($meta['pubdate'])) {
                $dpub = preg_split('/\s+/', trim($meta['pubdate']));
                $articleMeta .= $t4 . "<date date-type=\"pub\">\n";
                if (!empty($dpub[0])) $articleMeta .= $t5 . "<day>" . $e($dpub[0]) . "</day>\n";
                if (!empty($dpub[1])) $articleMeta .= $t5 . "<month>" . $e($dpub[1]) . "</month>\n";
                if (!empty($dpub[2])) $articleMeta .= $t5 . "<year>" . $e($dpub[2]) . "</year>\n";
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
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "RESUMEN " : "Resumen") . "</title>\n";
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEs']) . "</p>\n";
            $articleMeta .= $t3 . "</abstract>\n";
        }
        if (!empty($meta['abstractEn'])) {
            $articleMeta .= $t3 . "<trans-abstract xml:lang=\"en\">\n";
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "ABSTRACT " : "Abstract") . "</title>\n";
            $articleMeta .= $t4 . "<p>" . $this->escapeXmlWithItalic($meta['abstractEn']) . "</p>\n";
            $articleMeta .= $t3 . "</trans-abstract>\n";
        }

        if (!empty($meta['kwdsEs'])) {
            $articleMeta .= $t3 . "<kwd-group xml:lang=\"es\">\n";
            $articleMeta .= $t4 . "<title>" . (empty($meta['hasHeaderBlock']) ? "PALABRAS CLAVES:" : "Palabras claves:") . "</title>\n";
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

        return ['journalMeta' => $journalMeta, 'articleMeta' => $articleMeta];
    }

    private function applySaludColectivaDefaults(array $meta)
    {
        $doi = mb_strtolower(trim((string)($meta['doi'] ?? '')), 'UTF-8');
        if (strpos($doi, '10.18294/sc') !== 0) {
            return $meta;
        }

        require_once __DIR__ . '/../PatternTemplateEngine.php';
        $engine = new PatternTemplateEngine();
        $defaults = $engine->getDefaults();

        $fields = ['journalTitle', 'journalAbbrev', 'journalIdPublisher', 'issn_ppub', 'issn_epub', 'publisher'];
        if (empty($meta['hasHeaderBlock'])) {
            foreach ($fields as $f) {
                if (isset($defaults[$f]) && $defaults[$f] !== '') {
                    $meta[$f] = $defaults[$f];
                }
            }
        } else {
            foreach ($fields as $f) {
                if (empty($meta[$f]) && isset($defaults[$f]) && $defaults[$f] !== '') {
                    $meta[$f] = $defaults[$f];
                }
            }
        }
        return $meta;
    }

    private function applyPatternFallback(array $meta): array
    {
        $eid = trim((string)($meta['elocation-id'] ?? ''));
        if ($eid === '') return $meta;

        $patternDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'archivosXMLPatron';
        $files = glob($patternDir . DIRECTORY_SEPARATOR . "*{$eid}.xml");
        if (empty($files)) return $meta;

        $content = @file_get_contents($files[0]);
        if ($content === false || $content === '') return $meta;

        $dom = new DOMDocument();
        @$dom->loadXML($content);
        $xpath = new DOMXPath($dom);

        if (empty($meta['articleIdOther'])) {
            foreach ($xpath->query('//*[local-name()="article-id"][@pub-id-type="other"]') as $n) {
                $meta['articleIdOther'] = trim($n->textContent);
                break;
            }
        }

        if (empty($meta['received']) || empty($meta['revised']) || empty($meta['accepted']) || empty($meta['pubdate'])) {
            foreach ($xpath->query('//*[local-name()="history"]//*[local-name()="date"]') as $dateNode) {
                $dateType = $dateNode->attributes ? $dateNode->attributes->getNamedItem('date-type')->nodeValue ?? '' : '';
                $day = ''; $month = ''; $year = '';
                foreach ($dateNode->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $ln = $child->localName;
                        if ($ln === 'day') $day = trim($child->textContent);
                        if ($ln === 'month') $month = trim($child->textContent);
                        if ($ln === 'year') $year = trim($child->textContent);
                    }
                }
                if ($day !== '' && $month !== '' && $year !== '') {
                    $dateStr = "$day $month $year";
                    if ($dateType === 'received' && empty($meta['received'])) $meta['received'] = $dateStr;
                    elseif (($dateType === 'revised' || $dateType === 'rev-recd') && empty($meta['revised'])) $meta['revised'] = $dateStr;
                    elseif ($dateType === 'accepted' && empty($meta['accepted'])) $meta['accepted'] = $dateStr;
                    elseif ($dateType === 'pub' && empty($meta['pubdate'])) $meta['pubdate'] = $dateStr;
                }
            }
            if (empty($meta['pubdate'])) {
                foreach ($xpath->query('//*[local-name()="pub-date"][@date-type="pub"]') as $pubNode) {
                    $day = ''; $month = ''; $year = '';
                    foreach ($pubNode->childNodes as $child) {
                        if ($child->nodeType === XML_ELEMENT_NODE) {
                            $ln = $child->localName;
                            if ($ln === 'day') $day = trim($child->textContent);
                            if ($ln === 'month') $month = trim($child->textContent);
                            if ($ln === 'year') $year = trim($child->textContent);
                        }
                    }
                    if ($day !== '' && $month !== '' && $year !== '') $meta['pubdate'] = "$day $month $year";
                    break;
                }
            }
        }
        return $meta;
    }

    private function applyKnownFrontMetadata(array $meta): array
    {
        if (empty($meta['elocation-id'])) {
            $doiVal = trim((string)($meta['doi'] ?? ''));
            if ($doiVal !== '' && preg_match('/\.(\d+)$/u', $doiVal, $mDoi)) {
                $meta['elocation-id'] = 'e' . $mDoi[1];
            }
        }

        if (empty($meta['collectionYear'])) {
            $yearSources = [
                $meta['accepted'] ?? '',
                $meta['pubdate'] ?? '',
                $meta['revised'] ?? '',
                $meta['received'] ?? '',
            ];
            foreach ($yearSources as $dateStr) {
                $parts = preg_split('/\s+/', trim($dateStr));
                $yearCandidate = end($parts);
                if (preg_match('/^(19|20)\d{2}$/', $yearCandidate)) {
                    $meta['collectionYear'] = $yearCandidate;
                    break;
                }
            }
            if (empty($meta['collectionYear'])) {
                $doiVal = trim((string)($meta['doi'] ?? ''));
                if (preg_match('/\.((?:19|20)\d{2})\./u', $doiVal, $mYear)) {
                    $meta['collectionYear'] = $mYear[1];
                }
            }
        }

        if (empty($meta['volume']) && !empty($meta['collectionYear'])) {
            $doi = mb_strtolower(trim((string)($meta['doi'] ?? '')), 'UTF-8');
            if (strpos($doi, '10.18294/sc') === 0) {
                $vol = (int)$meta['collectionYear'] - 2004;
                if ($vol >= 1 && $vol <= 999) {
                    $meta['volume'] = (string)$vol;
                }
            }
        }

        foreach (($meta['authors'] ?? []) as $i => $author) {
            $orcid = (string)($author['orcid'] ?? '');
            if ($orcid !== '') {
                if (preg_match('/orcid\.org\/([0-9X\-]{16,19})/i', $orcid, $mOrcid)) {
                    $meta['authors'][$i]['orcid'] = $mOrcid[1];
                }
                $meta['authors'][$i]['orcid'] = trim(rtrim($meta['authors'][$i]['orcid'], '.'));
            }
        }

        return $meta;
    }

    // Section heading helpers (needed during parse() body chunking)
    private function isBodySectionHeading($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        if ($normalized === '') return false;
        if (preg_match('/introducci[oó]n|introduction/u', $normalized)) return true;
        if (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) return true;
        if ($this->isResultsDiscussionHeading($normalized)) return true;
        if (preg_match('/conclus|consideraciones\s+finales/u', $normalized)) return true;
        return false;
    }

    private function looksLikeSectionTitle($title)
    {
        $normalized = trim((string)$title);
        if ($normalized === '' || mb_strlen($normalized) > 140) return false;
        if (preg_match('/[.!?]$/u', $normalized)) return false;
        if (preg_match('/^(10\.\d{4,9}\/|https?:\/\/|[\w.%-]+@[\w.-]+\.[A-Za-z]{2,})/iu', $normalized)) return false;
        $wordCount = preg_match_all('/\S+/u', $normalized);
        if ($wordCount < 3 || $wordCount > 18) return false;
        return (bool)preg_match('/^[A-ZÁÉÍÓÚÑ]/u', $normalized);
    }

    private function isResultsDiscussionHeading($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        return (bool)preg_match('/\b(resultado(?:s)?|results?|discusi[oó]n|discussion)\b/u', $normalized)
            || (bool)preg_match('/results?\s+and\s+discussion/u', $normalized)
            || (bool)preg_match('/resultado(?:s)?\s+y\s+discusi[oó]n(?:es)?/u', $normalized);
    }

    private function normalizeReferencePagesString($text)
    {
        return $text;
    }

    private function initMeta(): array
    {
        return [
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
    }
}
