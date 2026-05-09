<?php
require "DocxParser.php";
$p=new DocxParser("/var/www/html/sge-tags-xml/ID-5939_Eich et al_XML-es.docx");
$meta=$p->parseMetadata($p->extractLines());
$parts = array_map(function($s){ return preg_replace('/\s+/u',' ', trim($s)); }, $meta['contributions']);
$contribText = trim(implode(' ', $parts));
if ($contribText !== '' && !preg_match('/Todos los autores/i', $contribText)) {
    $contribText = rtrim($contribText, '. ') . '. Todos los autores revisaron y aprobaron la versión final del manuscrito.';
}
echo $contribText . "\n";
echo "\n--META CONTRIBUTIONS--\n";
print_r($meta['contributions']);
