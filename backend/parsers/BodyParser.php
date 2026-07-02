<?php

require_once __DIR__ . '/EscapeXmlTrait.php';

class BodyParser
{
    use EscapeXmlTrait;

    public function buildBodyXml(array $meta): string
    {
        $sections = $meta['bodySections'] ?? [];
        $xml = "\t<body>\r\n";
        $ts = false;

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
            $grouped = [
                'intro'            => [],
                'intro_children'   => [],
                'methods'          => [],
                'results_children' => [],
                'discussion'       => [],
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
                    $grouped['intro_children'][] = $sec;
                } else {
                    $grouped['other'][] = $sec;
                }
            }

            foreach ($grouped['intro'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"intro\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                }
                foreach ($grouped['intro_children'] as $child) {
                    $xml .= "\t\t\t<sec>\r\n";
                    $xml .= "\t\t\t\t<title>" . htmlspecialchars($child['title']) . "</title>\r\n";
                    foreach ($child['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t\t", $ts);
                    }
                    $xml .= "\t\t\t</sec>\r\n";
                }
                $xml .= "\t\t</sec>\r\n";
            }

            foreach ($grouped['methods'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"methods\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                }
                $xml .= "\t\t</sec>\r\n";
            }

            if (!empty($grouped['results_children'])) {
                $firstSec = $grouped['results_children'][0];
                $firstNorm = mb_strtolower(trim($firstSec['title']));
                $isParent = $this->isResultsDiscussionHeading($firstNorm);

                $rsecType = 'results|discussion';
                if ($isParent) {
                    $rnorm = mb_strtolower(trim($firstSec['title']), 'UTF-8');
                    if (preg_match('/^resultado|^results?/u', $rnorm) && !preg_match('/discusi|discussion/u', $rnorm)) {
                        $rsecType = 'results';
                    }
                }
                $xml .= "\t\t<sec sec-type=\"$rsecType\">\r\n";
                if ($isParent) {
                    $xml .= "\t\t\t<title>" . htmlspecialchars($firstSec['title']) . "</title>\r\n";
                    foreach ($firstSec['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                    }
                    $children = array_slice($grouped['results_children'], 1);
                } else {
                    $xml .= "\t\t\t<title>Resultado y discusiones</title>\r\n";
                    $children = $grouped['results_children'];
                }

                foreach ($children as $child) {
                    $xml .= "\t\t\t<sec>\r\n";
                    $xml .= "\t\t\t\t<title>" . htmlspecialchars($child['title']) . "</title>\r\n";
                    foreach ($child['paragraphs'] as $para) {
                        $xml .= $this->formatParagraphGranular($para, "\t\t\t\t", $ts);
                    }
                    $xml .= "\t\t\t</sec>\r\n";
                }
                $xml .= "\t\t</sec>\r\n";
            }

            foreach ($grouped['discussion'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"discussion\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                }
                $xml .= "\t\t</sec>\r\n";
            }

            foreach ($grouped['conclusions'] as $sec) {
                $xml .= "\t\t<sec sec-type=\"conclusions\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                }
                $xml .= "\t\t</sec>\r\n";
            }

            foreach ($grouped['other'] as $sec) {
                $type = $this->inferBodySecType($sec['title']);
                $xml .= "\t\t<sec sec-type=\"" . htmlspecialchars($type) . "\">\r\n";
                $xml .= "\t\t\t<title>" . htmlspecialchars($sec['title']) . "</title>\r\n";
                foreach ($sec['paragraphs'] as $para) {
                    $xml .= $this->formatParagraphGranular($para, "\t\t\t", $ts);
                }
                $xml .= "\t\t</sec>\r\n";
            }
        }

        $xml .= "\t</body>\r\n";
        $xml = $this->convertFigureBlocks($xml, $meta);
        return $xml;
    }

    private function convertFigureBlocks(string $xml, array $meta): string
    {
        $issn    = $meta['issn_epub'] ?? '1851-8265';
        $journal = $meta['journalIdPublisher'] ?? 'scol';
        $vol     = $meta['volume'] ?? '22';
        $eloc    = ltrim($meta['elocation-id'] ?? 'e6032', 'e');
        $base    = "$issn-$journal-$vol-e$eloc";

        $xml = preg_replace_callback(
            '/<p>(Figura|Imagen|Gráfico|Grafico)\s*(\d+)\.\s*(.+?)\s*<\/p>\s*(\t+)?(?:<p>(Fuente:\s*.+?)\s*<\/p>)?/isu',
            function ($m) use ($base) {
                $keyword = $m[1];
                $n       = $m[2];
                $caption = rtrim($m[3]);
                $indent  = $m[4] ?? "\t\t\t";
                $attrib  = $m[5] ?? '';
                if (substr($caption, -1) !== ' ') $caption .= ' ';
                $graphic = "$base-gf$n.png";
                return "<p>\r\n$indent\t<fig id=\"f$n\">\r\n"
                     . "$indent\t\t<label>$keyword $n</label>\r\n"
                     . "$indent\t\t<caption>\r\n"
                     . "$indent\t\t\t<title>" . htmlspecialchars($caption, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</title>\r\n"
                     . "$indent\t\t</caption>\r\n"
                     . "$indent\t\t<graphic xlink:href=\"$graphic\"/>\r\n"
                     . ($attrib !== '' ? "$indent\t\t<attrib>$attrib</attrib>\r\n" : "")
                     . "$indent\t</fig>\r\n"
                     . "$indent</p>";
            },
            $xml
        );

        $xml = preg_replace_callback(
            '/(?<!<label>)(?<!<title>)\b((Figura|Imagen|Gráfico|Grafico)\s*(\d+))\b(?![^<]*<\/(?:label|title)>)(?![^<]*<\/fig>)/iu',
            function ($m) {
                return '<xref ref-type="fig" rid="f' . $m[3] . '">' . $m[1] . '</xref>';
            },
            $xml
        );

        return $xml;
    }

    private function formatParagraphGranular($text, $indent = "\t\t\t", $trailingSpace = false)
    {
        if (strpos($text, '<table-wrap>') !== false) {
            return $indent . $text . "\r\n";
        }
        $isQuote = preg_match('/^[“"]/u', trim(strip_tags($text)));
        $content = $this->escapeXmlWithItalic($text);
        $content = preg_replace('/  +/', ' ', $content);

        $yearRangePlaceholders = [];
        $content = preg_replace_callback('/\((19\d{2}|20\d{2})\s*[-–]\s*(19\d{2}|20\d{2})\)/u', function ($matches) use (&$yearRangePlaceholders) {
            $placeholder = '[[YEAR_RANGE_' . count($yearRangePlaceholders) . ']]';
            $yearRangePlaceholders[$placeholder] = $matches[0];
            return $placeholder;
        }, $content);

        $re = '/(?<=[A-ZÁÉÍÓÚÑ&&[^D]])(\d+(?:[\d,\-\s]*\d+)?)(?=[\s\.\,])|\[([\d,\-\s]+)\]|\((\d[\d,\-\s]*\d?)\)(?=[\s\.\,\;\)\-"\x{201d}\:\x{201c}]|$)/u';
        $content = preg_replace_callback($re, function($m) {
            $val = str_replace(' ', '', !empty($m[3]) ? $m[3] : (!empty($m[2]) ? $m[2] : $m[1]));
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

        if (!empty($yearRangePlaceholders)) {
            $content = strtr($content, $yearRangePlaceholders);
        }
        $content = preg_replace('/\s+([\.,;:\)])/u', '$1', $content);
        $content = preg_replace('/([\(\[]+)\s+/u', '$1', $content);

        if (trim(strip_tags(str_replace("\xc2\xa0", '', $content))) === '') {
            return '';
        }

        $plain = trim(strip_tags($text));
        if (preg_match('/^(Financiamiento|Conflicto de Intereses|Contribuci[óo]n autoral|Conflicto de Intereses\s*y\s*Contribuci[óo]n autoral)\s*$/iu', $plain)) {
            return '';
        }
        if (preg_match('/^(El presente trabajo fue realizado|Los autores declaran|Todos los autores revisaron)/iu', $plain)) {
            return '';
        }
        if (preg_match('/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+){1,4}:\s*(Conceptualiz|Investigaci|Análisis|Elaboraci|Redacci|Revisi)/u', $plain)) {
            return '';
        }

        if ($isQuote) {
            if (preg_match('/<\/xref>\s*$/', $content)) {
                return $indent . "<disp-quote>\r\n"
                     . $indent . "\t<p>" . $content . "\r\n"
                     . $indent . "\t</p>\r\n"
                     . $indent . "</disp-quote>\r\n";
            } else {
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
        $display = htmlspecialchars($display, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safePrefix = htmlspecialchars($prefix, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return "<comment>{$safePrefix}<ext-link ext-link-type=\"uri\" xlink:href=\"$href\">$display</ext-link>\r\n\t\t\t\t\t</comment>";
    }

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

    private function inferBodySecType($title)
    {
        $normalized = mb_strtolower(trim((string)$title));
        if (preg_match('/introducci[oó]n|introduction/u', $normalized)) return 'intro';
        if (preg_match('/metodolog[ií]a|m[eé]todos|methods/u', $normalized)) return 'methods';
        if (preg_match('/^resultado|^results?/u', $normalized)) return 'results';
        if (preg_match('/^discusi[óo]n|^discussion/u', $normalized)) return 'discussion';
        if (preg_match('/resultado|results?|discusi[oó]n|discussion/u', $normalized)) return 'results|discussion';
        if (preg_match('/conclus|consideraciones finales/u', $normalized)) return 'conclusions';
        return 'sec';
    }
}
