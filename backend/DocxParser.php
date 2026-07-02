<?php

require_once __DIR__ . '/parsers/FrontParser.php';
require_once __DIR__ . '/parsers/BodyParser.php';
require_once __DIR__ . '/parsers/BackParser.php';

class DocxParser
{
    private $filePath;

    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }

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

        if (empty($segments)) {
            $tNodes = $p->getElementsByTagNameNS($NS, 't');
            if ($tNodes && $tNodes->length > 0) {
                $texts = [];
                for ($i = 0; $i < $tNodes->length; $i++) {
                    $tNode = $tNodes->item($i);
                    $parent = $tNode->parentNode;
                    while ($parent && $parent !== $p) {
                        if ($parent->localName === 'p' && $parent->namespaceURI === $NS) {
                            $parent = null;
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

        $wa = function ($node, $attr) use ($NS) {
            return $node ? $node->getAttributeNS($NS, $attr) : null;
        };

        $colCount = 0;
        $firstRow = $xpath->query('./w:tr', $tbl)->item(0);
        if ($firstRow) {
            foreach ($xpath->query('./w:tc', $firstRow) as $tc) {
                $tcPr = $xpath->query('./w:tcPr', $tc)->item(0);
                $gs = $tcPr ? $xpath->query('./w:gridSpan', $tcPr)->item(0) : null;
                $colCount += ($gs && $wa($gs, 'val')) ? (int)$wa($gs, 'val') : 1;
            }
        }

        $xml = "<table-wrap>\r\n\t\t<table>\r\n";
        if ($colCount > 0) {
            $xml .= "\t\t\t<colgroup>\r\n\t\t\t\t<col span=\"$colCount\"/>\r\n\t\t\t</colgroup>\r\n";
        }
        $xml .= "\t\t\t<thead>\r\n";

        $rows = $xpath->query('./w:tr', $tbl);
        $rowCount = $rows->length;

        for ($i = 0; $i < $rowCount; $i++) {
            $tr = $rows->item($i);
            $isHeader = ($i < 3);

            if ($isHeader) {
                $xml .= "\t\t\t\t<tr>\r\n";
                $colIdx = 0;
                foreach ($xpath->query('./w:tc', $tr) as $tc) {
                    $tcPr = $xpath->query('./w:tcPr', $tc)->item(0);
                    $styles = [];
                    $attrs = '';

                    $shd = $tcPr ? $xpath->query('./w:shd', $tcPr)->item(0) : null;
                    $fill = $shd ? $wa($shd, 'fill') : null;

                    $bgColor = "rgba(51,51,51,0.8)";
                    $fgColor = "rgb(255,255,255)";

                    if ($fill && $fill !== 'auto' && $fill !== 'none' && strlen($fill) === 6) {
                        $styles[] = "background-color:#$fill";
                    } else {
                        $styles[] = "background-color: $bgColor";
                    }
                    $styles[] = "color: $fgColor";
                    $styles[] = "border-right: 1px solid #FFFFFF";
                    $styles[] = "vertical-align:top";

                    $vAl = $tcPr ? $xpath->query('./w:vAlign', $tcPr)->item(0) : null;
                    $va = $vAl ? $wa($vAl, 'val') : null;
                    if ($va && $va !== 'top') $styles[] = "vertical-align:$va";

                    $gs = $tcPr ? $xpath->query('./w:gridSpan', $tcPr)->item(0) : null;
                    $gsVal = $gs ? (int)$wa($gs, 'val') : 0;
                    if ($gsVal > 1) $attrs .= " colspan=\"$gsVal\"";

                    $vMerge = $tcPr ? $xpath->query('./w:vMerge', $tcPr)->item(0) : null;
                    if ($vMerge && $wa($vMerge, 'val') === 'restart') {
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
                    } elseif ($vMerge && $wa($vMerge, 'val') !== 'restart') {
                        continue;
                    }

                    $styleAttr = ' style="' . implode(';', $styles) . '"';
                    $align = "center";

                    $cellParas = [];
                    foreach ($xpath->query('./w:p', $tc) as $p) {
                        $cellParas[] = $this->parseParagraphNode($p, $xpath, null);
                    }
                    $content = implode('<break/>', array_filter($cellParas, function ($v) { return $v !== ''; }));

                    if ($colIdx === 0) $align = "left";

                    $xml .= "\t\t\t\t\t<th align=\"$align\"$styleAttr$attrs>" . $content . "</th>\r\n";
                    $colIdx++;
                }
                $xml .= "\t\t\t\t</tr>\r\n";
            }
        }
        $xml .= "\t\t</thead>\r\n\t\t\t<tbody>\r\n";

        for ($i = 3; $i < $rowCount; $i++) {
            $tr = $rows->item($i);
            $xml .= "\t\t\t\t<tr>\r\n";
            foreach ($xpath->query('./w:tc', $tr) as $tc) {
                $tcPr = $xpath->query('./w:tcPr', $tc)->item(0);
                $styles = [];
                $attrs = '';

                $shd = $tcPr ? $xpath->query('./w:shd', $tcPr)->item(0) : null;
                $fill = $shd ? $wa($shd, 'fill') : null;
                if ($fill && $fill !== 'auto' && $fill !== 'none' && strlen($fill) === 6) {
                    $styles[] = "background-color:#$fill";
                }

                $vAl = $tcPr ? $xpath->query('./w:vAlign', $tcPr)->item(0) : null;
                $va = $vAl ? $wa($vAl, 'val') : null;
                if ($va && $va !== 'top') $styles[] = "vertical-align:$va";

                $gs = $tcPr ? $xpath->query('./w:gridSpan', $tcPr)->item(0) : null;
                $gsVal = $gs ? (int)$wa($gs, 'val') : 0;
                if ($gsVal > 1) $attrs .= " colspan=\"$gsVal\"";

                $vMerge = $tcPr ? $xpath->query('./w:vMerge', $tcPr)->item(0) : null;
                if ($vMerge && $wa($vMerge, 'val') === 'restart') {
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
                } elseif ($vMerge && $wa($vMerge, 'val') !== 'restart') {
                    continue;
                }

                $styleAttr = $styles ? ' style="' . implode(';', $styles) . '"' : '';
                $xml .= "\t\t\t\t\t<td align=\"left\"$styleAttr$attrs>";

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

    public function parseMetadata(array $lines, array $defaults = [])
    {
        $frontParser = new FrontParser();
        return $frontParser->parse($lines, $defaults);
    }

    public function buildJatsXml(array $meta)
    {
        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $t1 = "\t"; $t2 = "\t\t"; $t3 = "\t\t\t"; $t4 = "\t\t\t\t"; $t5 = "\t\t\t\t\t"; $t6 = "\t\t\t\t\t\t";

        $lang = $meta['lang'] ?? 'es';

        $frontParser = new FrontParser();
        $bodyParser = new BodyParser();
        $backParser = new BackParser();

        $frontXml = $frontParser->buildFrontXml($meta);
        $journalMeta = $frontXml['journalMeta'];
        $articleMeta = $frontXml['articleMeta'];

        $bodyXml = $bodyParser->buildBodyXml($meta);
        $backXml = $backParser->buildBackXml($meta);

        preg_match_all('/<fig id="f\d+"/', $bodyXml, $figMatches);
        $actualFigCount = count($figMatches[0]);
        if ($actualFigCount > 0) {
            $articleMeta = preg_replace('/<fig-count count="\d+"\/>/', "<fig-count count=\"$actualFigCount\"/>", $articleMeta);
        }

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n";
        $xml .= "<!DOCTYPE article\r\n";
        $xml .= "  PUBLIC \"-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN\" \"https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd\">\r\n";
        $xml .= "<article article-type=\"research-article\" dtd-version=\"1.1\" specific-use=\"sps-1.9\" xml:lang=\"" . $e($lang) . "\" xmlns:mml=\"http://www.w3.org/1998/Math/MathML\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">\r\n";
        $xml .= "\t<front>\r\n";
        $xml .= $journalMeta . "\r\n";
        $xml .= rtrim($articleMeta, "\r\n") . "\r\n" . $t1 . "</front>\r\n";

        $xml .= $bodyXml;
        $xml .= $backXml;

        $xml .= "</article>";

        $xml = preg_replace('/<\/article-meta>\r?\n(?:\t*\r?\n)+\t<\/front>\r?\n/', "</article-meta>\r\n\t</front>\r\n", $xml);

        $xml = str_replace("\r\n", "\n", $xml);
        $xml = str_replace("\r", "\n", $xml);
        $xml = str_replace("\n", "\r\n", $xml);

        return $xml;
    }
}
