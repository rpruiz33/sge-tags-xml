<?php
require_once 'DocxParser.php';

$lines = [
    "sps-1.9", "es", "Salud Colect", "scol", "Salud Colectiva", "Salud Colect",
    "1669-2381", "1851-8265", "Universidad Nacional de Lanús",
    "10.18294/sc.2026.5939", "Artículo",
    "Prácticas de fin de vida y directivas anticipadas de voluntad: un análisis bioético a partir de las discusiones en el Poder Legislativo Federal brasileño",
    "End-of-life practices and advance directives: a bioethical analysis based on discussions in the Brazilian Federal Legislative Branch",
    "Melisse Eich1 https://orcid.org/0000-0001-8382-1354",
    "Marta Verdi2 https://orcid.org/0000-0001-7090-9541",
    "Pedro Paulo Scremin Martins3 https://orcid.org/0000-0003-2641-8563",
    "Mirelle Finkler4 https://orcid.org/0000-0001-5764-9183",
    "1Posdoctoranda, Programa de Pós-graduação em Saúde Coletiva, Universidade Federal de Santa Catarina. Professora voluntáriado, Departamento de Saúde Pública, Universidade Federal de Santa Catarina. Membro e pesquisadora, Núcleo de Pesquisa e Extensión em Bioética e Saúde Coletiva, Santa Catarina, Brasil. meliseeich@hotmail.com",
    "2Doctora en Enfermería, Professora adjunta, Departamento de Saúde Pública; professora permanente, Programa de Pós-Graduação em Saúde Coletiva, Universidade Federal de Santa Catarina. Líder, Núcleo de Pesquisa e Extensión em Bioética e Saúde Coletiva, Santa Catarina, Brasil. verdiufsc@gmail.com",
    "3Magíster en Filosofía. Estudiante, Doctorado em Saúde Coletiva, Universidade Federal de Santa Catarina, Santa Catarina, Brasil. ppsm29@hotmail.com",
    "4Doctora en Odontología. Profesora asociada, Departamento de Odontologia; profesora permanente, Programa de Pós-Graduación em Saúde Coletiva, Universidade Federal de Santa Catarina. Vicelíder, Núcleo de Pesquisa e Extensión em Bioética e Saúde Coletiva, Santa Catarina, Brasil. mirellefinkler@yahoo.com.br",
    "Resumen: El debate legislativo brasileño sobre prácticas de fin de vida involucra tensiones ético-axiológicas relacionadas con la eutanasia, el suicidio asistido, los cuidados paliativos y las directivas anticipadas de voluntad, en el que se confrontan concepciones divergentes de estas prácticas y el valor de la vida, influyendo en la formulación normativa del proceso de morir. Este estudio analiza cómo tales tensiones son construidas y justificadas en los discursos del Poder Legislativo Federal, comprendiendo los sentidos normativos y la jerarquía de valores atribuidos a los cuidados paliativos y a las directivas anticipadas de voluntad, así como sus implicaciones ético-morales. Se realizó una investigación documental cualitativa, orientada por la hermenéutica-dialéctica y fundamentada en referentes contemporáneos de la bioética de la responsabilidad y de la bioética cotidiana. Se examinaron 193 documentos legislativos federales (1981–2020).",
    "Palabras claves: Cuidados Paliativos; Directivas Anticipadas; Autonomía Personal; Derecho a Morir; Eutanasia; Brasil.",
    "Abstract: The Brazilian legislative debate on end-of-life practices involves ethical-axiological tensions related to euthanasia, assisted suicide, palliative care, and advance directives, in which divergent conceptions of these practices and of the value of life confront one another, influencing the normative formulation of the dying process.",
    "Keywords: Palliative Care; Advance Directives; Personal Autonomy; Right to Die; Euthanasia; Brazil.",
    "Volumen: 22", "Elocation-id: e5939", "Recibido: 6 sep 2025", "Revisado: 23 dic 2025", "Aceptado: 25 feb 2026",
    "Conflicto de Intereses: Los autores declaran no tener vínculos que condicionen lo expresado en el texto.",
    "Contribución autoral: Melisse Eich: Conceptualización del estudio; investigación; análisis formal; elaboración del borrador original del manuscrito. Marta Verdi: Conceptualización; contribución al diseño metodológico; revisión crítica del manuscrito."
];

$p = new DocxParser("");
$meta = $p->parseMetadata($lines);
$xml = $p->buildJatsXml($meta);

file_put_contents('v12_generated.xml', $xml);
echo "✓ Generado: v12_generated.xml\n";
?>
