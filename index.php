<?php
// ─── Configuración ─────────────────────────────────────────────────────────
define('MAX_SIZE_MB', 10);
define('UPLOAD_DIR', __DIR__ . '/uploads/');
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

// ─── Procesamiento AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['docx'])) {
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', '0');
    ob_start();
    
    try {
        $file = $_FILES['docx'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            if (ob_get_length()) ob_clean();
            echo json_encode(['error' => 'Error al subir el archivo (código ' . $file['error'] . ').']);
            exit;
        }
        if ($file['size'] > MAX_SIZE_MB * 1024 * 1024) {
            http_response_code(400);
            if (ob_get_length()) ob_clean();
            echo json_encode(['error' => 'El archivo supera el límite de ' . MAX_SIZE_MB . 'MB.']);
            exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            http_response_code(400);
            if (ob_get_length()) ob_clean();
            echo json_encode(['error' => 'Solo se aceptan archivos .docx']);
            exit;
        }

        $tmpName = UPLOAD_DIR . uniqid('doc_', true) . '.docx';
        if (!move_uploaded_file($file['tmp_name'], $tmpName)) {
            http_response_code(400);
            if (ob_get_length()) ob_clean();
            echo json_encode(['error' => 'No se pudo mover el archivo subido.']);
            exit;
        }

        $parser  = new DocxParser($tmpName);
        $lines   = $parser->extractLines();
        
        if (empty($lines)) {
            throw new Exception('No se pudo extraer contenido del documento DOCX');
        }
        
        $meta    = $parser->parseMetadata($lines);
        $xml     = $parser->buildJatsXml($meta);
        
        $response = [
            'success'  => true,
            'xml'      => $xml,
            'metadata' => $meta,
            'filename' => pathinfo($file['name'], PATHINFO_FILENAME),
        ];
        
        if (ob_get_length()) ob_clean();
        echo json_encode($response);
        
    } catch (Throwable $e) {
        http_response_code(400);
        $msg = $e->getMessage();
        if (empty($msg)) $msg = 'Error desconocido al procesar el archivo';
        error_log('[scielo-jats] ' . $msg);
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => $msg]);
    } finally {
        if (isset($tmpName) && file_exists($tmpName)) {
            @unlink($tmpName);
        }
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
    exit;
}

// ─── Clase DocxParser ───────────────────────────────────────────────────────
class DocxParser
{
    private string $path;

    public function __construct(string $path) { $this->path = $path; }

    public function extractLines(): array
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true)
            throw new Exception('No se puede abrir el archivo (¿es realmente un DOCX?)');

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false)
            throw new Exception('El DOCX no contiene word/document.xml');

        $xmlContent = trim($xmlContent);
        if (empty($xmlContent))
            throw new Exception('El archivo word/document.xml está vacío');

        // Remover BOM si existe
        if (substr($xmlContent, 0, 3) === "\xEF\xBB\xBF") {
            $xmlContent = substr($xmlContent, 3);
        }

        // Intenta con DOMDocument
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        
        $loaded = @$dom->loadXML($xmlContent, LIBXML_PARSEHUGE | LIBXML_NOWARNING);
        
        if ($loaded) {
            libxml_clear_errors();
            return $this->extractLinesFromDom($dom);
        }
        
        // Si DOMDocument falla, intenta expresiones regulares
        libxml_clear_errors();
        $regexLines = $this->extractLinesWithRegex($xmlContent);
        
        if (!empty($regexLines)) {
            return $regexLines;
        }
        
        // Último intento: dividir por saltos de línea del XML
        if (preg_match_all('/<w:p[^>]*>.*?<\/w:p>/ius', $xmlContent, $paras)) {
            foreach ($paras[0] as $para) {
                if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/iu', $para, $matches)) {
                    $text = implode('', $matches[1]);
                    if (!empty(trim($text))) {
                        $regexLines[] = trim($text);
                    }
                }
            }
        }
        
        return $regexLines ?? [];
    }

    private function extractLinesFromDom($dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        $lines = [];
        $paragraphs = $xpath->query('//w:p');
        
        foreach ($paragraphs as $p) {
            $textNodes = $xpath->query('.//w:t', $p);
            $lineText = '';
            foreach ($textNodes as $node) {
                $lineText .= $node->nodeValue;
            }
            $lineText = trim($lineText);
            if (!empty($lineText)) {
                $lines[] = $lineText;
            }
        }
        
        return $lines;
    }

    private function extractLinesWithRegex(string $xmlContent): array
    {
        $lines = [];
        
        // Buscar contenido entre etiquetas w:t
        if (preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/iu', $xmlContent, $matches)) {
            foreach ($matches[1] as $text) {
                $text = trim(html_entity_decode($text, ENT_XML1, 'UTF-8'));
                if (!empty($text)) {
                    $lines[] = $text;
                }
            }
        }
        
        // Si no hay resultados, buscar entre etiquetas de párrafo
        if (empty($lines)) {
            if (preg_match_all('/<w:p[^>]*>(.*?)<\/w:p>/ius', $xmlContent, $paras)) {
                foreach ($paras[1] as $para) {
                    if (preg_match_all('/>([^<]{3,})</', $para, $matches)) {
                        $text = trim(implode(' ', $matches[1]));
                        if (!empty($text) && strlen($text) > 3) {
                            $lines[] = $text;
                        }
                    }
                }
            }
        }
        
        return $lines;
    }

    public function parseMetadata(array $lines): array
    {
        $text = implode("\n", $lines);

        $meta = [
            'sps'            => '',
            'lang'           => '',
            'journalTitle'   => '',
            'journalAbbrev'  => '',
            'issn_ppub'      => '',
            'issn_epub'      => '',
            'publisher'      => '',
            'doi'            => '',
            'articleTitle'   => '',
            'articleTitleEn' => '',
            'authors'        => [],
            'affiliations'   => [],
            'abstractEs'     => '',
            'abstractEn'     => '',
            'kwdsEs'         => [],
            'kwdsEn'         => [],
            'received'       => '',
            'revised'        => '',
            'accepted'       => '',
            'funding'        => [],
            'sections'       => [],
            'conflict'       => '',
            'contributions'  => [],
        ];

        // SPS
        if (preg_match('/sps-[\d.]+/i', $text, $m)) $meta['sps'] = $m[0];

        // Idioma
        foreach ($lines as $l)
            if (preg_match('/^(es|en|pt)$/i', trim($l))) { $meta['lang'] = strtolower(trim($l)); break; }

        // ISSNs
        preg_match_all('/\d{4}-\d{3}[\dX]/i', $text, $issns);
        $meta['issn_ppub'] = $issns[0][0] ?? '';
        $meta['issn_epub'] = $issns[0][1] ?? '';

        // DOI
        if (preg_match('/10\.\d{4,9}\/[^\s\)\]\.,]+/', $text, $m))
            $meta['doi'] = rtrim($m[0], '.,)');

        // Revista (deteccion generica; evita valores fijos por revista)
        foreach (array_slice($lines, 0, 30) as $l) {
            $cand = trim($l);
            if (strlen($cand) < 5 || strlen($cand) > 120) continue;
            if (preg_match('/^10\.|\d{4}-\d{3}[\dX]|sps-[\d.]+/i', $cand)) continue;
            if (preg_match('/^(resumen|abstract|palabras|keywords|orcid|recib|aprobad|versi[oó]n)/iu', $cand)) continue;
            if (!preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $cand)) continue;
            $meta['journalTitle'] = $cand;
            break;
        }

        foreach (array_slice($lines, 0, 30) as $l) {
            $cand = trim($l);
            if (strlen($cand) < 3 || strlen($cand) > 30) continue;
            if (preg_match('/^(resumen|abstract|palabras|keywords|orcid|recib|aprobad|versi[oó]n)/iu', $cand)) continue;
            if (!preg_match('/[A-Za-zÁÉÍÓÚáéíóúÑñ]/u', $cand)) continue;
            if ($cand === $meta['journalTitle']) continue;
            $meta['journalAbbrev'] = $cand;
            break;
        }

        if (!$meta['journalAbbrev'] && $meta['journalTitle']) {
            // Fallback: abreviatura basada en iniciales del titulo
            preg_match_all('/\b[\p{L}]/u', $meta['journalTitle'], $m);
            $abbr = strtoupper(implode('', array_slice($m[0], 0, 8)));
            $meta['journalAbbrev'] = $abbr ?: '';
        }

        // Publisher
        foreach ($lines as $l) {
            if (preg_match('/universidad\s+nacional|editorial/i', $l) && strlen($l) < 100) { $meta['publisher'] = trim($l); break; }
        }

        // Títulos
        $doiIdx = -1;
        foreach ($lines as $i => $l) {
            if (strpos($l, '10.') !== false && strpos($l, '/') !== false) { $doiIdx = $i; break; }
        }
        if ($doiIdx >= 0) {
            $after = array_values(array_filter(array_slice($lines, $doiIdx + 1), fn($l) => strlen($l) > 50));
            $meta['articleTitle']   = $after[0] ?? '';
            $meta['articleTitleEn'] = $after[1] ?? '';
        }

        // Autores con ORCID
        foreach ($lines as $l) {
            if (preg_match('/orcid\.org\/([\d\-]{19})/i', $l, $om)) {
                $name = trim(preg_replace('/https?:\/\/orcid\.org\/[\d\-]+/i', '', $l));
                if ($name) $meta['authors'][] = ['name' => $name, 'orcid' => $om[1]];
            }
        }

        // Afiliaciones numeradas
        foreach ($lines as $l) {
            if (preg_match('/^\d+\s*[A-ZÁÉÍÓÚ]/', $l) && strlen($l) > 10 && strlen($l) < 300)
                $meta['affiliations'][] = trim($l);
        }

        // Resumen ES
        foreach ($lines as $i => $l) {
            if (preg_match('/^resumen\s*[:\-]/i', $l)) {
                $abs = preg_replace('/^resumen\s*[:\-]\s*/i', '', $l);
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (preg_match('/^abstract\s*[:\-]|^palabras?\s*(clave)/i', $lines[$j])) break;
                    $abs .= ' ' . $lines[$j];
                }
                $meta['abstractEs'] = trim($abs); break;
            }
        }

        // Resumen EN
        foreach ($lines as $i => $l) {
            if (preg_match('/^abstract\s*[:\-]/i', $l)) {
                $abs = preg_replace('/^abstract\s*[:\-]\s*/i', '', $l);
                for ($j = $i + 1; $j < count($lines); $j++) {
                    if (preg_match('/^keywords?\s*[:\-]|^palabras/i', $lines[$j])) break;
                    $abs .= ' ' . $lines[$j];
                }
                $meta['abstractEn'] = trim($abs); break;
            }
        }

        // Keywords ES
        foreach ($lines as $l) {
            if (preg_match('/palabras?\s*(clave|claves)\s*[:\*\-]/i', $l)) {
                $raw = trim(preg_replace('/palabras?\s*(clave|claves)\s*[:\*\-\s]*/i', '', $l));
                $raw = trim($raw, '*· ');
                if (strlen($raw) > 3) {
                    $meta['kwdsEs'] = array_values(array_filter(array_map(fn($k)=>trim($k,'* '), preg_split('/[;,]/', $raw))));
                }
                break;
            }
        }

        // Keywords EN
        foreach ($lines as $l) {
            if (preg_match('/^keywords?\s*[:\*\-]/i', $l)) {
                $raw = trim(preg_replace('/^keywords?\s*[:\*\-\s]*/i', '', $l));
                $raw = trim($raw, '*· ');
                if (strlen($raw) > 3) {
                    $meta['kwdsEn'] = array_values(array_filter(array_map(fn($k)=>trim($k,'* '), preg_split('/[;,]/', $raw))));
                }
                break;
            }
        }

        // Fechas
        if (preg_match('/recib[io]+[a-z]*\s*[:\-]?\s*([\d]+\s+\w+\s+\d{4}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu', $text, $m))
            $meta['received'] = $m[1];
        if (preg_match('/versi[oó]n\s+final\s*[:\-]?\s*([\d]+\s+\w+\s+\d{4}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu', $text, $m))
            $meta['revised'] = $m[1];
        if (preg_match('/aprobad[oa][a-z]*\s*[:\-]?\s*([\d]+\s+\w+\s+\d{4}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/iu', $text, $m))
            $meta['accepted'] = $m[1];

        // Financiamiento
        foreach ($lines as $l) {
            if (preg_match('/CAPES|FAPESC|CNPq|CONICET|ANPCYT|financiamiento|financiad/i', $l) && strlen($l) > 25) {
                $meta['funding'][] = trim($l); break;
            }
        }

        // Conflicto de intereses
        foreach ($lines as $i => $l) {
            if (preg_match('/conflicto\s+de\s+inter[eé]s/i', $l)) {
                $meta['conflict'] = $lines[$i+1] ?? trim(preg_replace('/conflicto\s+de\s+inter[eé]s\s*[:\-]?\s*/i','', $l));
                break;
            }
        }

        // Contribuciones autorales
        foreach ($lines as $l) {
            if (preg_match('/^[A-ZÁÉÍÓÚ][a-záéíóú]+\s+[A-ZÁÉÍÓÚ][a-záéíóú]+.*?:\s*.{20,}/u', $l) &&
                preg_match('/conceptualiz|investigaci|redacci|análisis|metodol|formal|supervis/i', $l)) {
                $meta['contributions'][] = trim($l);
            }
        }

        // Secciones del cuerpo
        $secKw = ['introducción','metodología','método','resultados y discusión','resultados','discusión','conclusiones','consideraciones finales'];
        foreach ($lines as $l) {
            $lower = mb_strtolower(trim($l));
            foreach ($secKw as $kw) {
                if (strpos($lower, $kw) === 0 && strlen($l) < 70) {
                    $meta['sections'][] = trim($l); break;
                }
            }
        }
        $meta['sections'] = array_unique($meta['sections']);

        return $meta;
    }

    private function parseDate(string $str): array
    {
        if (!$str) return ['day'=>'','month'=>'','year'=>''];
        $months = ['ene'=>'01','feb'=>'02','mar'=>'03','abr'=>'04','may'=>'05','jun'=>'06',
                   'jul'=>'07','ago'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dic'=>'12',
                   'jan'=>'01','apr'=>'04','aug'=>'08','dec'=>'12'];
        if (preg_match('/(\d{1,2})\s+(\w{3})\w*\s+(\d{4})/i', $str, $m)) {
            return ['day' => str_pad($m[1],2,'0',STR_PAD_LEFT),
                    'month' => $months[strtolower($m[2])] ?? '01',
                    'year'  => $m[3]];
        }
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $str, $m)) {
            return ['day' => str_pad($m[1],2,'0',STR_PAD_LEFT),
                    'month' => str_pad($m[2],2,'0',STR_PAD_LEFT),
                    'year'  => $m[3]];
        }
        preg_match('/\d{4}/', $str, $y);
        return ['day'=>'','month'=>'','year'=>$y[0]??''];
    }

    public function buildJatsXml(array $meta): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_XML1|ENT_QUOTES, 'UTF-8');
        $rec = $this->parseDate($meta['received'] ?? '');
        $rev = $this->parseDate($meta['revised'] ?? '');
        $acc = $this->parseDate($meta['accepted'] ?? '');
        $doiClean = rtrim($meta['doi'] ?? '', '. ');
        $pubId    = $doiClean ? (explode('/', $doiClean)[1] ?? 'XXXX') : 'XXXX';
        $year     = $acc['year'] ?: date('Y');
        $sps      = $meta['sps'] ?: 'sps-1.9';
        $lang     = $meta['lang'] ?: 'es';

        // Autores
        $authXml = '';
        foreach (($meta['authors'] ?? []) as $i => $a) {
            if (!isset($a['name'])) continue;
            $parts   = preg_split('/\s+/', trim($a['name']));
            $surname = array_pop($parts) ?: 'Autor';
            $given   = implode(' ', $parts) ?: $surname;
            $n = $i + 1;
            $authXml .= "\n        <contrib contrib-type=\"author\">"
                . (isset($a['orcid']) && $a['orcid'] ? "\n          <contrib-id contrib-id-type=\"orcid\">https://orcid.org/{$e($a['orcid'])}</contrib-id>" : '')
                . "\n          <name>\n            <surname>{$e($surname)}</surname>\n            <given-names>{$e($given)}</given-names>\n          </name>"
                . "\n          <xref ref-type=\"aff\" rid=\"aff{$n}\">{$n}</xref>"
                . ($i === 0 ? "\n          <xref ref-type=\"corresp\" rid=\"c1\">*</xref>" : '')
                . "\n        </contrib>";
        }

        // Afiliaciones
        $affsXml = '';
        foreach (($meta['affiliations'] ?? ['Ver afiliación en el manuscrito']) as $i => $a) {
            $n = $i + 1;
            $affsXml .= "\n      <aff id=\"aff{$n}\">\n        <label>{$n}</label>\n        <institution>{$e($a)}</institution>\n      </aff>";
        }

        // Keywords
        $kwEsXml = '';
        if (!empty($meta['kwdsEs'])) {
            $kwEsXml = "\n      <kwd-group xml:lang=\"es\" kwd-group-type=\"author\">\n        <title>Palabras clave</title>";
            foreach ($meta['kwdsEs'] as $k) $kwEsXml .= "\n        <kwd>{$e($k)}</kwd>";
            $kwEsXml .= "\n      </kwd-group>";
        }
        $kwEnXml = '';
        if (!empty($meta['kwdsEn'])) {
            $kwEnXml = "\n      <kwd-group xml:lang=\"en\" kwd-group-type=\"author\">\n        <title>Keywords</title>";
            foreach ($meta['kwdsEn'] as $k) $kwEnXml .= "\n        <kwd>{$e($k)}</kwd>";
            $kwEnXml .= "\n      </kwd-group>";
        }

        // Financiamiento
        $fundXml = '';
        if (!empty($meta['funding'])) {
            $fundXml = "\n      <funding-group>";
            foreach ($meta['funding'] as $f)
                $fundXml .= "\n        <award-group><funding-source>{$e($f)}</funding-source></award-group>";
            $fundXml .= "\n      </funding-group>";
        }

        // Historial
        $histXml = '';
        if ($rec['year'] || $rev['year'] || $acc['year']) {
            $histXml = "\n      <history>";
            $buildDate = function($d, $type) use ($e) {
                if (!$d['year']) return '';
                return "\n        <date date-type=\"{$type}\">"
                    . ($d['day']   ? "<day>{$e($d['day'])}</day>"     : '')
                    . ($d['month'] ? "<month>{$e($d['month'])}</month>" : '')
                    . "<year>{$e($d['year'])}</year></date>";
            };
            $histXml .= $buildDate($rec, 'received') . $buildDate($rev, 'rev-recd') . $buildDate($acc, 'accepted');
            $histXml .= "\n      </history>";
        }

        // Cuerpo
        $sectionMap = ['introducción'=>'intro','metodología'=>'methods','método'=>'methods',
                       'resultados y discusión'=>'results','resultados'=>'results',
                       'discusión'=>'discussion','conclusiones'=>'conclusions',
                       'consideraciones finales'=>'conclusions'];
        $bodySections = '';
        foreach (($meta['sections'] ?? []) as $s) {
            $lower = mb_strtolower(trim($s));
            $type  = '';
            foreach ($sectionMap as $kw => $t) {
                if (strpos($lower, $kw) === 0) { $type = $t; break; }
            }
            $bodySections .= "\n    <sec" . ($type ? " sec-type=\"{$type}\"" : '') . ">"
                . "\n      <title>{$e($s)}</title>"
                . "\n      <p>[Completar con el texto del manuscrito]</p>"
                . "\n    </sec>";
        }
        if (!$bodySections)
            $bodySections = "\n    <sec sec-type=\"intro\">\n      <title>Introducción</title>\n      <p>[Completar con el texto del manuscrito]</p>\n    </sec>";

        // Contribuciones autorales
        $contribXml = '';
        if (!empty($meta['contributions'])) {
            $contribXml = "\n    <fn-group content-type=\"author-contribution\">";
            foreach ($meta['contributions'] as $c)
                $contribXml .= "\n      <fn fn-type=\"con\"><p>{$e($c)}</p></fn>";
            $contribXml .= "\n    </fn-group>";
        }

        // Conflicto
        $conflictXml = $meta['conflict']
            ? "\n    <fn-group content-type=\"conflict\">\n      <fn fn-type=\"conflict\"><p>{$e($meta['conflict'])}</p></fn>\n    </fn-group>"
            : '';

        // ISSN y DOI
        $issnPpubXml = $meta['issn_ppub'] 
            ? "\n      <issn pub-type=\"ppub\">{$e($meta['issn_ppub'])}</issn>"
            : "\n      <!-- issn ppub no detectado -->";
        $issnEpubXml = $meta['issn_epub']
            ? "\n      <issn pub-type=\"epub\">{$e($meta['issn_epub'])}</issn>"
            : "\n      <!-- issn epub no detectado -->";
        $doiXml = $doiClean
            ? "\n      <article-id pub-id-type=\"doi\">{$e($doiClean)}</article-id>"
            : "\n      <!-- doi no detectado -->";
        $transTitleXml = $meta['articleTitleEn']
            ? "\n        <trans-title-group xml:lang=\"en\"><trans-title>{$e($meta['articleTitleEn'])}</trans-title></trans-title-group>"
            : '';
        $abstractEsXml = $meta['abstractEs']
            ? "\n      <abstract xml:lang=\"es\">\n        <p>{$e($meta['abstractEs'])}</p>\n      </abstract>"
            : '';
        $abstractEnXml = $meta['abstractEn']
            ? "\n      <trans-abstract xml:lang=\"en\">\n        <p>{$e($meta['abstractEn'])}</p>\n      </trans-abstract>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.3 20210610//EN"
  "https://jats.nlm.nih.gov/publishing/1.3/JATS-journalpublishing1-3.dtd">
<article xmlns:mml="http://www.w3.org/1998/Math/MathML"
         xmlns:xlink="http://www.w3.org/1999/xlink"
         dtd-version="1.3"
         article-type="research-article"
         xml:lang="{$e($lang)}"
         specific-use="{$e($sps)}">

  <front>
    <journal-meta>
            <journal-id journal-id-type="publisher-id">{$e($meta['journalAbbrev'] ?: 'journal-id')}</journal-id>
      <journal-title-group>
                <journal-title>{$e($meta['journalTitle'] ?: '[Journal Title]')}</journal-title>
                <abbrev-journal-title abbrev-type="publisher">{$e($meta['journalAbbrev'] ?: '[Journal Abbrev]')}</abbrev-journal-title>
      </journal-title-group>{$issnPpubXml}{$issnEpubXml}
      <publisher>
                <publisher-name>{$e($meta['publisher'] ?: '[Publisher]')}</publisher-name>
      </publisher>
    </journal-meta>

    <article-meta>{$doiXml}
      <article-id pub-id-type="publisher-id">{$e($pubId)}</article-id>

      <article-categories>
        <subj-group subj-group-type="heading">
          <subject>Artículo</subject>
        </subj-group>
      </article-categories>

      <title-group>
        <article-title xml:lang="es">{$e($meta['articleTitle'] ?: '[Título del artículo en español]')}</article-title>{$transTitleXml}
      </title-group>

      <contrib-group>{$authXml}
      </contrib-group>
{$affsXml}

      <author-notes>
        <corresp id="c1">Correspondencia al primer autor/a.</corresp>
      </author-notes>

      <pub-date date-type="pub" publication-format="electronic">
        <year>{$e($year)}</year>
      </pub-date>
{$histXml}

      <permissions>
        <license license-type="open-access"
                 xlink:href="https://creativecommons.org/licenses/by/4.0/"
                 xml:lang="es">
          <license-p>Este artículo se distribuye bajo una licencia Creative Commons Attribution 4.0 International.</license-p>
        </license>
      </permissions>{$abstractEsXml}{$abstractEnXml}
{$kwEsXml}
{$kwEnXml}
{$fundXml}
    </article-meta>
  </front>

  <body>
{$bodySections}
  </body>

  <back>
{$conflictXml}
{$contribXml}
    <ref-list>
      <title>Referencias bibliográficas</title>
      <ref id="B1">
        <label>1</label>
        <element-citation publication-type="journal">
          <comment>[Completar referencias en formato JATS element-citation]</comment>
        </element-citation>
      </ref>
    </ref-list>
  </back>

</article>
XML;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SciELO JATS Converter · SPS 1.9</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&family=Syne:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="noise"></div>

<header class="header">
  <div class="header-inner">
    <div class="logo">
      <div class="logo-badge">SC</div>
      <div class="logo-text">
        <span class="logo-name">SciELO JATS Converter</span>
        <span class="logo-ver">SPS 1.9 · JATS 1.3 · PHP</span>
      </div>
    </div>
    <div class="header-tag">
      <span class="dot dot--green"></span>
      Acceso abierto
    </div>
  </div>
</header>

<main class="main">

  <!-- STEPS -->
  <div class="steps-bar">
    <div class="step-item active" id="st1">
      <div class="step-circle">1</div>
      <span>Subir .docx</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item" id="st2">
      <div class="step-circle">2</div>
      <span>Extraer texto</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item" id="st3">
      <div class="step-circle">3</div>
      <span>Generar XML</span>
    </div>
    <div class="step-line"></div>
    <div class="step-item" id="st4">
      <div class="step-circle">4</div>
      <span>Descargar</span>
    </div>
  </div>

  <!-- UPLOAD -->
  <section class="panel upload-panel">
    <div class="drop-zone" id="dropZone">
      <input type="file" id="fileInput" accept=".docx" hidden>
      <div class="drop-icon">
        <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
          <rect x="8" y="4" width="26" height="36" rx="3" fill="var(--c-surface)" stroke="var(--c-border)" stroke-width="1.5"/>
          <rect x="28" y="4" width="6" height="6" rx="1" fill="var(--c-accent)" opacity="0.6"/>
          <line x1="14" y1="18" x2="34" y2="18" stroke="var(--c-border)" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="14" y1="24" x2="28" y2="24" stroke="var(--c-border)" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="14" y1="30" x2="30" y2="30" stroke="var(--c-border)" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
      </div>
      <p class="drop-title">Arrastrá tu archivo Word acá</p>
      <p class="drop-hint">o hacé clic para seleccionar &nbsp;·&nbsp; <code>.docx</code> &nbsp;·&nbsp; máx. <?= MAX_SIZE_MB ?>MB</p>
    </div>

    <div class="file-pill hidden" id="filePill">
      <span class="pill-icon">📝</span>
      <span class="pill-name" id="pillName"></span>
      <button class="pill-clear" id="clearBtn">✕</button>
    </div>

    <div class="progress-track hidden" id="progressTrack">
      <div class="progress-fill" id="progressFill"></div>
      <span class="progress-label" id="progressLabel">Subiendo...</span>
    </div>

    <div class="alert hidden" id="alertBox"></div>

    <button class="btn-convert" id="convertBtn" disabled>
      <span class="btn-icon">⚡</span>
      <span id="btnLabel">Convertir a XML JATS</span>
    </button>
  </section>

  <!-- METADATA PREVIEW -->
  <section class="panel meta-panel hidden" id="metaPanel">
    <h2 class="panel-title">
      <span class="panel-title-icon">🔍</span>
      Metadatos detectados
    </h2>
    <div class="meta-grid" id="metaGrid"></div>
  </section>

  <!-- XML RESULT -->
  <section class="panel result-panel hidden" id="resultPanel">
    <div class="result-header">
      <h2 class="panel-title">
        <span class="panel-title-icon">✅</span>
        XML JATS · SciELO SPS 1.9
      </h2>
      <div class="result-actions">
        <button class="btn-action" id="copyBtn">
          <span>Copiar</span>
        </button>
        <button class="btn-action btn-accent" id="downloadBtn">
          <span>Descargar .xml</span>
        </button>
      </div>
    </div>
    <div class="xml-wrap">
      <pre class="xml-output" id="xmlOutput"></pre>
    </div>
    <p class="xml-note">💡 El cuerpo del artículo y las referencias bibliográficas deben completarse manualmente en el XML generado.</p>
  </section>

</main>

<footer class="footer">
  <span>SciELO JATS Converter</span>
  <span class="footer-sep">·</span>
  <span>PHP + ZipArchive</span>
  <span class="footer-sep">·</span>
  <span>Sin dependencias externas</span>
</footer>

<script src="js/app.js"></script>
</body>
</html>
