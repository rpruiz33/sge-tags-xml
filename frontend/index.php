<?php
/**
 * Cargador del frontend:
 * - Si existe el manifest de Vite, sirve los assets compilados (modo produccion).
 * - Si no existe, apunta al servidor de desarrollo de Vite para trabajar en caliente.
 */

$manifestPath = __DIR__ . '/dist/.vite/manifest.json';
$isDev = !file_exists($manifestPath);

if (!$isDev) {
    // En produccion lee el manifest generado por Vite para conocer CSS y JS finales.
    $manifest = json_decode(file_get_contents($manifestPath), true);
    
    // Recorre el manifest y junta todos los archivos de estilos y scripts a inyectar.
    $cssFiles = [];
    $jsFiles = [];
    
    foreach ($manifest as $entry => $details) {
        if (isset($details['css']) && is_array($details['css'])) {
            foreach ($details['css'] as $css) {
                $cssFiles[] = $css;
            }
        }
        if (isset($details['file'])) {
            $jsFiles[] = $details['file'];
        }
    }
    
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XML JATS - SGEtags-xml</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&family=Syne:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php foreach (array_unique($cssFiles) as $css): ?>
    <link rel="stylesheet" href="/frontend/dist/<?php echo htmlspecialchars($css); ?>">
    <?php endforeach; ?>
</head>
<body>
    <div id="root"></div>
    <?php foreach (array_unique($jsFiles) as $js): ?>
    <script type="module" src="/frontend/dist/<?php echo htmlspecialchars($js); ?>"></script>
    <?php endforeach; ?>
</body>
</html>
    <?php
} else {
    // En desarrollo renderiza el HTML base y carga React desde Vite (HMR).
    ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>XML JATS - SGEtags-xml</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&family=Syne:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div id="root"></div>
    <script type="module" src="http://localhost:5173/src/main.jsx"></script>
</body>
</html>
    <?php
}
