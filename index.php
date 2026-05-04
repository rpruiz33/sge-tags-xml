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
        <span class="logo-ver">SPS 1.9 · JATS 1.3 · Front-only</span>
      </div>
    </div>
    <div class="header-tag">
      <span class="dot dot--green"></span>
      Acceso abierto
    </div>
  </div>
</header>

<main class="main">
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
      <p class="drop-hint">o hacé clic para seleccionar · <code>.docx</code> · máx. 10MB</p>
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

  <section class="panel manual-meta-panel">
    <h2 class="panel-title">
      <span class="panel-title-icon">✍️</span>
      Metadatos manuales (edición rápida)
    </h2>
    <div class="manual-grid">
      <div class="manual-row">
        <label>Afiliación principal:</label>
        <input type="text" id="manualAff" value="Salud Colectiva" />
      </div>
      <div class="manual-row">
        <label>Autores (predefinidos):</label>
        <ul class="manual-authors">
          <li>Melisse Eich<sup>1</sup> — <a href="https://orcid.org/0000-0001-8382-1354" target="_blank" rel="noopener">0000-0001-8382-1354</a></li>
          <li>Marta Verdi<sup>2</sup> — <a href="https://orcid.org/0000-0001-7090-9541" target="_blank" rel="noopener">0000-0001-7090-9541</a></li>
          <li>Pedro Paulo Scremin Martins<sup>3</sup> — <a href="https://orcid.org/0000-0003-2641-8563" target="_blank" rel="noopener">0000-0003-2641-8563</a></li>
          <li>Mirelle Finkler<sup>4</sup> — <a href="https://orcid.org/0000-0001-5764-9183" target="_blank" rel="noopener">0000-0001-5764-9183</a></li>
        </ul>
      </div>
      <div class="manual-row">
        <button class="btn-action" id="loadPatternBtn">Cargar XML patrón</button>
        <small>El front cargará el XML desde <code>pattern.xml</code>.</small>
      </div>
      <div class="manual-row">
        <label>DOI:</label>
        <input type="text" id="manualDoi" placeholder="10.18294/sc.2026.5939" />
      </div>
      <div class="manual-row">
        <label>Pub-date (DD MM YYYY):</label>
        <input type="text" id="manualPubDay" size="2" placeholder="11" />
        <input type="text" id="manualPubMonth" size="2" placeholder="03" />
        <input type="text" id="manualPubYear" size="4" placeholder="2026" />
      </div>
      <div class="manual-row">
        <label>Volume:</label>
        <input type="text" id="manualVolume" placeholder="22" />
        <label style="margin-left:12px">Elocation-id:</label>
        <input type="text" id="manualEloc" placeholder="e5939" />
      </div>
      <div class="manual-row">
        <label>Funding (texto):</label>
        <input type="text" id="manualFunding" placeholder="CAPES; FAPESC" />
      </div>
      <div class="manual-row">
        <label>Conflicto de intereses:</label>
        <input type="text" id="manualConflict" placeholder="Los autores declaran..." />
      </div>
      <div class="manual-row">
        <label>Contribuciones (texto):</label>
        <textarea id="manualContrib" rows="3" placeholder="Melisse Eich: Conceptualización..."></textarea>
      </div>
      <div class="manual-row">
        <button class="btn-action btn-accent" id="generateManualBtn">Generar XML desde metadatos</button>
      </div>
      <textarea id="patternXml" hidden data-src="pattern.xml"></textarea>
      <div class="manual-row">
        <small>Estos metadatos son sólo para completar el front-end; la extracción automática sigue disponible.</small>
      </div>
    </div>
  </section>

  <section class="panel meta-panel hidden" id="metaPanel">
    <h2 class="panel-title">
      <span class="panel-title-icon">🔍</span>
      Metadatos detectados
    </h2>
    <div class="meta-grid" id="metaGrid"></div>
  </section>

  <section class="panel result-panel hidden" id="resultPanel">
    <div class="result-header">
      <h2 class="panel-title">
        <span class="panel-title-icon">✅</span>
        XML JATS · SciELO SPS 1.9
      </h2>
      <div class="result-actions">
        <button class="btn-action" id="copyBtn"><span>Copiar</span></button>
        <button class="btn-action btn-accent" id="downloadBtn"><span>Descargar .xml</span></button>
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
  <span>Front-only</span>
  <span class="footer-sep">·</span>
  <span>Sin dependencias externas</span>
</footer>

<script src="js/app.js?v=20260504"></script>
</body>
</html>
