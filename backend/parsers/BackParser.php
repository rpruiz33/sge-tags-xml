<?php

require_once __DIR__ . '/EscapeXmlTrait.php';

class BackParser
{
    use EscapeXmlTrait;

    public function buildBackXml(array $meta): string
    {
        $xml = "\t<back>\r\n";

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
        $isSpecialLegislativeBook = (bool)preg_match(
            '/\b(C[oó]digo de [EÉ]tica|C[oó]digo de [EÉ]tica M[eé]dica|PL\s*6544\/2009|Projeto de Lei do Senado\s*n\.\s*149|Projeto de Lei do Senado\s+149|Resolu[cç][aã]o\s+CFM\s+No\.\s+2217)\b/iu',
            $cleanRef
        );

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

        $mixedText = $this->escapeXmlWithItalic($cleanRef);
        if (($isWebpage || $isSpecialLegislativeBook || $forcedPubType === 'webpage') && ($hasUrl || $forcedUrl !== '') && preg_match('/(https?:\/\/\S+)/i', $cleanRef, $mu)) {
            $rawU = rtrim($mu[1], '.');
            $safeU = htmlspecialchars(rtrim(trim($rawU), '.'), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $commentPrefix = 'Disponible en: ';
            if (preg_match('/(Disponible en:\s*)https?:\/\/\S+/iu', $cleanRef, $pm)) {
                $commentPrefix = $pm[1];
            }
            $displaySource = rtrim(str_replace("\xc2\xa0", ' ', $rawU), '.');
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
        $mixedContent = str_replace("\xc2\xa0", '', $mixedContent);
        $mixedContent = preg_replace('/\betal\./u', 'et al.', $mixedContent);
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
                $pageParts = preg_split('/[\\-\xe2\x80\x93]/', $pages, 2);
                $fp = trim($pageParts[0]); $lp = trim($pageParts[1] ?? $pageParts[0]);
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[2]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$fp</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$lp</lpage>\r\n";
            } elseif (preg_match('/\b(?:19|20)\d{2}\s*;\s*\((\d+)\)\s*:\s*(\d[\d\-]*)\b/u', $cleanRef, $mp)) {
                $pages = trim($mp[2]);
                $pageParts = preg_split('/[\\-\xe2\x80\x93]/', $pages, 2);
                $fp = trim($pageParts[0]); $lp = trim($pageParts[1] ?? $pageParts[0]);
                $xml .= "\t\t\t\t\t<issue>" . trim($mp[1]) . "</issue>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$fp</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$lp</lpage>\r\n";
            } elseif (preg_match('/\b(\d+)\s*:\s*(e\d+)\b/u', $cleanRef, $mp)) {
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<elocation-id>{$mp[2]}</elocation-id>\r\n";
            } elseif (preg_match('/\b(\d+)\s*:\s*(\d[\d\-]*)\b/u', $cleanRef, $mp)) {
                $pages = trim($mp[2]);
                $pageParts = preg_split('/[\\-\xe2\x80\x93]/', $pages, 2);
                $fp = trim($pageParts[0]); $lp = trim($pageParts[1] ?? $pageParts[0]);
                $xml .= "\t\t\t\t\t<volume>{$mp[1]}</volume>\r\n";
                $xml .= "\t\t\t\t\t<fpage>$fp</fpage>\r\n";
                $xml .= "\t\t\t\t\t<lpage>$lp</lpage>\r\n";
            }
        }

        if ($hasDoi && $pubType !== 'webpage' && preg_match('/10\.\d{4,9}\/\S+/u', $cleanRef, $md)) {
            $xml .= "\t\t\t\t\t<pub-id pub-id-type=\"doi\">" . htmlspecialchars($md[0]) . "</pub-id>\r\n";
        }

        $xml .= "\t\t\t\t</element-citation>\r\n";
        $xml .= "\t\t\t</ref>\r\n";
        return $xml;
    }

    private function extractCitationYear($text, $pubType = '')
    {
        if (!is_string($text) || $text === '') return '';
        $normalized = preg_replace('/10\.\d{4,9}\/\S+/u', '', $text);
        $normalized = preg_replace('/\[citado\s+.+?\]/iu', '', $normalized);
        $normalized = preg_replace('/https?:\/\/\S+/i', '', $normalized);
        if (!preg_match_all('/\b((?:19|20)\d{2})\b/u', $normalized, $matches)) return '';
        $years = $matches[1] ?? [];
        if (empty($years)) return '';
        return (string) end($years);
    }

    private function normalizeReferencePagesString($text)
    {
        return $text;
    }

    private function parseAuthorList($authorText)
    {
        $res = ['authors' => [], 'etal' => false];
        if (!is_string($authorText) || trim($authorText) === '') return $res;

        if (preg_match('/et\s*al\b/i', $authorText)) {
            $res['etal'] = true;
            $authorText = preg_replace('/\s*et\s*al\b/i', '', $authorText);
        }

        $parts = preg_split('/\s*(?:,|;|\band\b|\by\b)\s*/iu', trim($authorText));
        $parts = array_filter(array_map('trim', $parts));

        foreach ($parts as $p) {
            if ($p === '') continue;

            if (preg_match('/^([\p{L}\p{M}\s\-\']+,)\s*([\p{L}\p{M}\s\-\'\.]+)$/u', $p, $m)) {
                $surname = trim($m[1], ',');
                $given = $m[2];
            } elseif (preg_match('/^([\p{L}\p{M}\s\-\'\.]+\s+[\p{L}\p{M}\s\-\'\.]+)$/u', $p, $m)) {
                $tokens = preg_split('/\s+/u', $p);
                if (count($tokens) > 1) {
                    $lastToken = end($tokens);
                    $isInitials = preg_match('/^[\p{Lu}]{1,3}\.?$/u', $lastToken);
                    if ($isInitials) {
                        $given = array_pop($tokens);
                        $surname = implode(' ', $tokens);
                    } else {
                        $surname = array_pop($tokens);
                        $given = implode(' ', $tokens);
                    }
                } else {
                    $surname = $p;
                    $given = '';
                }
            } elseif (preg_match('/^([\p{L}\p{M}\s\-\']+)$/u', $p, $m)) {
                $surname = $m[1];
                $given = '';
            } else {
                $tokens = preg_split('/\s+/u', $p);
                if (count($tokens) > 1) {
                    $lastToken = end($tokens);
                    if (preg_match('/^[\p{Lu}]{1,3}\.?$/u', $lastToken)) {
                        $given = array_pop($tokens);
                        $surname = implode(' ', $tokens);
                    } else {
                        $surname = array_pop($tokens);
                        $given = implode(' ', $tokens);
                    }
                } else {
                    $surname = $p;
                    $given = '';
                }
            }

            $res['authors'][] = ['surname' => trim($surname), 'given' => trim($given)];
        }

        return $res;
    }

    private function buildInlineUrlComment($url, $prefix = 'Disponible en: ', $displayUrl = null)
    {
        $href = htmlspecialchars(rtrim(trim((string) $url), '.'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $displaySource = $displayUrl === null ? (string) $url : (string) $displayUrl;
        $displaySource = str_replace("\xc2\xa0", ' ', $displaySource);
        $display = rtrim($displaySource, '.');
        $display = htmlspecialchars($display, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safePrefix = htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return "<comment>{$safePrefix}<ext-link ext-link-type=\"uri\" xlink:href=\"$href\">$display</ext-link>\r\n\t\t\t\t\t</comment>";
    }
}
