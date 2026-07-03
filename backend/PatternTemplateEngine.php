<?php

require_once __DIR__ . '/parsers/FrontParser.php';
require_once __DIR__ . '/parsers/BodyParser.php';
require_once __DIR__ . '/parsers/BackParser.php';

class PatternTemplateEngine
{
    private string $patternDir;
    private ?array $defaultsCache = null;

    public function __construct()
    {
        $this->patternDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'archivosXMLPatron';
    }

    public function findPatternFile(?string $elocationId): ?string
    {
        if (empty($elocationId)) return null;
        $files = glob($this->patternDir . DIRECTORY_SEPARATOR . '*' . $elocationId . '.xml');
        return !empty($files) ? $files[0] : null;
    }

    public function getDefaults(): array
    {
        if ($this->defaultsCache !== null) return $this->defaultsCache;

        $files = glob($this->patternDir . DIRECTORY_SEPARATOR . '*.xml');
        if (empty($files)) return [];

        $this->defaultsCache = $this->extractJournalMeta($files[0]);
        return $this->defaultsCache;
    }

    private function extractJournalMeta(string $file): array
    {
        $content = @file_get_contents($file);
        if ($content === false) return [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadXML($content);
        $xpath = new DOMXPath($dom);

        $defaults = [];

        $node = $xpath->query('//*[local-name()="journal-title-group"]/*[local-name()="journal-title"]')->item(0);
        if ($node) $defaults['journalTitle'] = trim($node->textContent);

        $node = $xpath->query('//*[local-name()="journal-title-group"]/*[local-name()="abbrev-journal-title"]')->item(0);
        if ($node) $defaults['journalAbbrev'] = trim($node->textContent);

        $node = $xpath->query('//*[local-name()="journal-id"][@journal-id-type="publisher-id"]')->item(0);
        if ($node) $defaults['journalIdPublisher'] = trim($node->textContent);

        $node = $xpath->query('//*[local-name()="issn"][@pub-type="ppub"]')->item(0);
        if ($node) $defaults['issn_ppub'] = trim($node->textContent);

        $node = $xpath->query('//*[local-name()="issn"][@pub-type="epub"]')->item(0);
        if ($node) $defaults['issn_epub'] = trim($node->textContent);

        $node = $xpath->query('//*[local-name()="publisher"]/*[local-name()="publisher-name"]')->item(0);
        if ($node) $defaults['publisher'] = trim($node->textContent);

        return $defaults;
    }

    public function generateXml(string $patternFile, array $meta, string $bodyXml, string $backXml): string
    {
        $xml = @file_get_contents($patternFile);
        if ($xml === false) {
            return '';
        }

        $e = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };

        // Normalizar line endings del patrón (production siempre \n)
        $xml = str_replace("\r\n", "\n", $xml);
        $xml = str_replace("\r", "\n", $xml);

        // ------------------------------------------------------------------
        // 1. Reemplazar <body>...</body>
        // ------------------------------------------------------------------
        $bodyInner = $bodyXml;
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $bodyXml, $m)) {
            $bodyInner = $m[1];
        }
        $bodyInner = str_replace("\r\n", "\n", $bodyInner);
        $bodyInner = str_replace("\r", "\n", $bodyInner);
        $xml = preg_replace(
            '/(<body\b[^>]*>)(.*)(<\/body>)/is',
            '$1' . addcslashes($bodyInner, '$\\') . '$3',
            $xml
        );

        // ------------------------------------------------------------------
        // 2. Reemplazar valores dentro del <back>
        //    Preservamos la estructura XML del patrón (refs, fn-group)
        //    y solo actualizamos el texto de <mixed-citation> y <fn-group>.
        // ------------------------------------------------------------------
        $patternBackInner = '';
        if (preg_match('/<back\b[^>]*>(.*)<\/back>/is', $xml, $mb)) {
            $patternBackInner = $mb[1];
        }
        $patternBackInner = str_replace("\r\n", "\n", $patternBackInner);
        $patternBackInner = str_replace("\r", "\n", $patternBackInner);

        $backParser = new BackParser();
        $references = $meta['references'] ?? [];

        // Extraer todos los <ref id="BX"> del patrón (ordenados), preservando indentación
        $patternRefs = [];
        preg_match_all('/^(\s*)(<ref id="B\d+">.*?<\/ref>)/sm', $patternBackInner, $patternRefMatches, PREG_SET_ORDER);
        foreach ($patternRefMatches as $rm) {
            $patternRefs[] = ['indent' => $rm[1], 'content' => $rm[2]];
        }

        $newRefList = '';
        $maxRefs = max(count($references), count($patternRefs));
        for ($i = 0; $i < $maxRefs; $i++) {
            $num = $i + 1;

            if ($i < count($references) && $i < count($patternRefs)) {
                // Reemplazar mixed-citation en el ref existente del patrón
                $refText = $references[$i];
                $cleanRef = rtrim(trim(str_replace("\xc2\xa0", '', strip_tags($refText))));
                $cleanRef = preg_replace('/^\s*\d+\.\s*/u', '', $cleanRef);

                $refBlock = $backParser->buildReferenceXmlBlock($num, $cleanRef);
                $newMixed = '';
                if (preg_match('/<mixed-citation>(.*)<\/mixed-citation>/s', $refBlock, $mm)) {
                    $newMixed = $mm[1];
                    $newMixed = str_replace("\r\n", "\n", $newMixed);
                    $newMixed = str_replace("\r", "\n", $newMixed);
                }

                // Reemplazar solo el contenido de mixed-citation en el ref del patrón
                $patternRef = $patternRefs[$i];
                $newPatternRef = preg_replace(
                    '/(<mixed-citation>).*?(<\/mixed-citation>)/s',
                    '${1}' . addcslashes($newMixed, '$\\') . '${2}',
                    $patternRef['content']
                );
                $newRefList .= $patternRef['indent'] . $newPatternRef . "\n";
            } elseif ($i >= count($patternRefs) && $i < count($references)) {
                // Más refs en DOCX que en patrón: generar nuevo ref
                $refText = $references[$i];
                $cleanRef = rtrim(trim(str_replace("\xc2\xa0", '', strip_tags($refText))));
                $cleanRef = preg_replace('/^\s*\d+\.\s*/u', '', $cleanRef);
                $newRefBlock = $backParser->buildReferenceXmlBlock($num, $cleanRef);
                $newRefBlock = str_replace("\r\n", "\n", $newRefBlock);
                $newRefBlock = str_replace("\r", "\n", $newRefBlock);
                // Usar la misma indentación que el primer ref del patrón
                $indent = !empty($patternRefs) ? $patternRefs[0]['indent'] : "\t\t\t";
                $newRefList .= $indent . $newRefBlock . "\n";
            }
            // Si patrón tiene más refs que DOCX, se omiten (no se incluyen en newRefList)
        }

        // Reemplazar solo el interior del <ref-list> (entre title y </ref-list>)
        // preservando los espacios/blancos exactos del patrón.
        // $1 captura hasta </title>, $2 captura \n + refs, $3 captura \s* + </ref-list>
        // Insertamos \n explícito para evitar duplicar indentación del primer ref.
        $newBackInner = preg_replace(
            '/(<ref-list>.*?<title>.*?<\/title>)(.*?)(\s*<\/ref-list>)/s',
            '${1}' . "\n" . rtrim($newRefList) . '${3}',
            $patternBackInner
        );

        $newBackInner = str_replace("\r\n", "\n", $newBackInner);
        $newBackInner = str_replace("\r", "\n", $newBackInner);

        $xml = preg_replace(
            '/(<back\b[^>]*>)(.*)(<\/back>)/is',
            '$1' . addcslashes($newBackInner, '$\\') . '$3',
            $xml
        );

        // ------------------------------------------------------------------
        // 3. Reemplazar valores dentro del <front>
        //    Usamos el front builder para generar el front completo,
        //    extraemos journal-meta y article-meta, y reemplazamos
        //    solo article-meta en el patrón (journal-meta se deja igual).
        // ------------------------------------------------------------------
        $frontParser = new FrontParser();
        $frontXml = $frontParser->buildFrontXml($meta);
        $builtArticleMeta = $frontXml['articleMeta'];

        // Ajustar fig-count si body tiene figuras
        preg_match_all('/<fig id="f\d+"/', $bodyXml, $figMatches);
        $actualFigCount = count($figMatches[0]);
        if ($actualFigCount > 0) {
            $builtArticleMeta = preg_replace(
                '/<fig-count count="\d+"\/>/',
                '<fig-count count="' . $actualFigCount . '"/>',
                $builtArticleMeta
            );
        }

        // Normalizar line endings del article-meta generado
        $builtArticleMeta = str_replace("\r\n", "\n", $builtArticleMeta);
        $builtArticleMeta = str_replace("\r", "\n", $builtArticleMeta);

        // Extraer solo el interior de <article-meta>...</article-meta>
        // porque el builtArticleMeta ya viene envuelto, y el regex
        // usa los tags del patrón para preservar indentación exacta.
        $builtArticleMetaInner = '';
        if (preg_match('/<article-meta\b[^>]*>(.*)<\/article-meta>/is', $builtArticleMeta, $m)) {
            $builtArticleMetaInner = $m[1];
        }

        // Reemplazar <article-meta>...</article-meta> completo
        $xml = preg_replace(
            '/(<article-meta\b[^>]*>)(.*)(<\/article-meta>)/is',
            '$1' . addcslashes($builtArticleMetaInner, '$\\') . '$3',
            $xml
        );

        return $xml;
    }
}
