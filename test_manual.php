<?php
// Cargar index.php pero silenciar su salida HTML
ob_start();
require_once __DIR__ . '/index.php';
ob_end_clean();

$meta = [
    'sps' => 'sps-1.9',
    'lang' => 'es',
    'journalTitle' => 'Salud Colectiva',
    'journalAbbrev' => 'Salud Colect',
    'issn_ppub' => '1669-2381',
    'issn_epub' => '1851-8265',
    'publisher' => 'Universidad Nacional de Lanús',
    'doi' => '10.18294/sc.2026.5939',
    'articleTitle' => 'Prácticas de fin de vida y directivas anticipadas de voluntad: un análisis bioético',
    'articleTitleEn' => 'End-of-life practices and advance directives: a bioethical analysis',
    'authors' => [
        ['name' => 'Melisse Eich', 'orcid' => '0000-0001-8382-1354'],
        ['name' => 'Marta Verdi', 'orcid' => '0000-0001-7090-9541'],
        ['name' => 'Pedro Paulo Scremin Martins', 'orcid' => '0000-0003-2641-8563'],
        ['name' => 'Mirelle Finkler', 'orcid' => '0000-0001-5764-9183']
    ],
    'affiliations' => [
        'Posdoctoranda, Programa de Pós-graduação em Saúde Coletiva, Universidade Federal de Santa Catarina. meliseeich@hotmail.com',
        'Doctora en Enfermería, Programa de Pós-Graduação em Saúde Coletiva, Universidade Federal de Santa Catarina. verdiufsc@gmail.com',
        'Estudiante, Doctorado em Saúde Coletiva, Universidade Federal de Santa Catarina. ppsm29@hotmail.com',
        'Doctora en Odontología, Departamento de Odontologia, Universidade Federal de Santa Catarina. mirellefinkler@yahoo.com.br'
    ],
    'abstractEs' => 'El debate legislativo brasileño sobre prácticas de fin de vida... (resumen de ejemplo).',
    'abstractEn' => 'The Brazilian legislative debate on end-of-life practices... (example abstract).',
    'kwdsEs' => ['Cuidados Paliativos','Directivas Anticipadas','Autonomía Personal','Brasil'],
    'kwdsEn' => ['Palliative Care','Advance Directives','Personal Autonomy','Brazil'],
    'funding' => ['Coordenação de Aperfeiçoamento de Pessoal de Nível Superior (CAPES) - 001/2021','Fundação de Amparo à Pesquisa e Inovação do Estado de Santa Catarina - 20/2024'],
    'received' => '06 09 2025',
    'revised' => '23 12 2025',
    'accepted' => '25 02 2026',
    'volume' => '22',
    'elocation-id' => 'e5939',
    'sections' => ['Introducción','Metodología','Resultados y discusión','Conclusiones'],
    'conflict' => 'Los autores declaran no tener vínculos que condicionen lo expresado en el texto.',
    'contributions' => ['Melisse Eich: Conceptualización; investigación; análisis.','Marta Verdi: Revisión crítica; metodología.']
];

$parser = new DocxParser('');
$xml = $parser->buildJatsXml($meta);
echo $xml;
