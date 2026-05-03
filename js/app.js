/* SciELO JATS Converter · app.js */
'use strict';

// ── DOM refs ──────────────────────────────────────────────────────────────────
const dropZone    = document.getElementById('dropZone');
const fileInput   = document.getElementById('fileInput');
const filePill    = document.getElementById('filePill');
const pillName    = document.getElementById('pillName');
const clearBtn    = document.getElementById('clearBtn');
const progressTrack = document.getElementById('progressTrack');
const progressFill  = document.getElementById('progressFill');
const progressLabel = document.getElementById('progressLabel');
const alertBox    = document.getElementById('alertBox');
const convertBtn  = document.getElementById('convertBtn');
const btnLabel    = document.getElementById('btnLabel');
const metaPanel   = document.getElementById('metaPanel');
const metaGrid    = document.getElementById('metaGrid');
const resultPanel = document.getElementById('resultPanel');
const xmlOutput   = document.getElementById('xmlOutput');
const copyBtn     = document.getElementById('copyBtn');
const downloadBtn = document.getElementById('downloadBtn');

let currentFile = null;
let generatedXml = '';
let generatedFilename = '';

// ── Drag & drop ───────────────────────────────────────────────────────────────
dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (f) handleFile(f);
});
fileInput.addEventListener('change', e => {
  if (e.target.files[0]) handleFile(e.target.files[0]);
});

function handleFile(f) {
  if (!f.name.toLowerCase().endsWith('.docx')) {
    showAlert('Solo se aceptan archivos .docx', 'error');
    return;
  }
  currentFile = f;
  pillName.textContent = f.name;
  filePill.classList.remove('hidden');
  convertBtn.disabled = false;
  hideAlert();
  metaPanel.classList.add('hidden');
  resultPanel.classList.add('hidden');
  setStep(1);
}

clearBtn.addEventListener('click', () => {
  currentFile = null;
  fileInput.value = '';
  filePill.classList.add('hidden');
  convertBtn.disabled = true;
  hideAlert();
  metaPanel.classList.add('hidden');
  resultPanel.classList.add('hidden');
  setStep(1);
});

// ── Steps ─────────────────────────────────────────────────────────────────────
function setStep(n) {
  [1,2,3,4].forEach(i => {
    const el = document.getElementById('st' + i);
    el.classList.remove('active', 'done');
    if (i < n)  el.classList.add('done');
    if (i === n) el.classList.add('active');
  });
}

// ── Progress ──────────────────────────────────────────────────────────────────
function setProgress(pct, label) {
  progressTrack.classList.remove('hidden');
  progressFill.style.width = pct + '%';
  if (label) progressLabel.textContent = label;
  if (pct >= 100) {
    setTimeout(() => progressTrack.classList.add('hidden'), 700);
  }
}

// ── Alerts ────────────────────────────────────────────────────────────────────
function showAlert(msg, type = 'info') {
  alertBox.className = 'alert alert--' + type;
  alertBox.innerHTML = msg;
  alertBox.classList.remove('hidden');
}
function hideAlert() {
  alertBox.className = 'alert hidden';
  alertBox.innerHTML = '';
}

// ── Convert ───────────────────────────────────────────────────────────────────
convertBtn.addEventListener('click', convert);

async function convert() {
  if (!currentFile) return;

  convertBtn.disabled = true;
  btnLabel.innerHTML  = '<span class="spinner"></span> Procesando...';
  hideAlert();
  metaPanel.classList.add('hidden');
  resultPanel.classList.add('hidden');
  generatedXml = '';

  setStep(2);
  setProgress(15, 'Subiendo archivo...');

  const form = new FormData();
  form.append('docx', currentFile);

  try {
    setProgress(30, 'Extrayendo texto...');
    await delay(200);

    const resp = await fetch('index.php', { method: 'POST', body: form });
    setProgress(60, 'Parseando metadatos...');

    const raw = await resp.text();
    let data = null;
    try {
      data = raw ? JSON.parse(raw) : null;
    } catch (_) {
      data = null;
    }

    if (!resp.ok) {
      const serverMsg = data && data.error ? data.error : `HTTP ${resp.status}`;
      throw new Error(serverMsg);
    }

    if (!data) {
      throw new Error('La respuesta del servidor no es JSON válido.');
    }

    if (data.error) {
      showAlert('❌ ' + data.error, 'error');
      setStep(1);
      return;
    }

    setProgress(85, 'Generando XML JATS...');
    await delay(200);

    generatedXml      = data.xml;
    generatedFilename = (data.filename || 'articulo') + '_JATS_SPS19';

    // Renderizar metadata
    renderMetadata(data.metadata);
    metaPanel.classList.remove('hidden');

    setStep(3);
    setProgress(100, 'Listo');

    // Mostrar XML
    xmlOutput.textContent = generatedXml;
    resultPanel.classList.remove('hidden');

    setStep(4);
    showAlert('✅ XML generado correctamente. Revisá y completá el cuerpo y las referencias.', 'success');

    // Scroll suave al resultado
    setTimeout(() => resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);

  } catch (err) {
    showAlert('❌ Error de conexión: ' + err.message, 'error');
    setStep(1);
  } finally {
    convertBtn.disabled = false;
    btnLabel.innerHTML  = '<span class="btn-icon">⚡</span> Convertir a XML JATS';
  }
}

// ── Metadata rendering ────────────────────────────────────────────────────────
function renderMetadata(meta) {
  const fields = [
    { k: 'DOI',            v: meta.doi },
    { k: 'Idioma',         v: meta.lang },
    { k: 'SPS',            v: meta.sps },
    { k: 'ISSN impreso',   v: meta.issn_ppub },
    { k: 'ISSN digital',   v: meta.issn_epub },
    { k: 'Revista',        v: meta.journalTitle },
    { k: 'Publisher',      v: meta.publisher },
    { k: 'Autores',        v: meta.authors?.map(a => a.name).join(' · ') },
    { k: 'Recibido',       v: meta.received },
    { k: 'Versión final',  v: meta.revised },
    { k: 'Aprobado',       v: meta.accepted },
    { k: 'Keywords (es)',  v: meta.kwdsEs?.join(', ') },
    { k: 'Keywords (en)',  v: meta.kwdsEn?.join(', ') },
    { k: 'Financiamiento', v: meta.funding?.join('; ') },
    { k: 'Secciones',      v: meta.sections?.join(' · ') },
  ];

  metaGrid.innerHTML = fields.map(f => {
    const val = f.v || '';
    const cls = val ? '' : ' meta-val--empty';
    const display = val || 'No detectado';
    return `<div class="meta-card">
      <div class="meta-key">${esc(f.k)}</div>
      <div class="meta-val${cls}">${esc(display)}</div>
    </div>`;
  }).join('');
}

// ── Copy ──────────────────────────────────────────────────────────────────────
copyBtn.addEventListener('click', () => {
  if (!generatedXml) return;
  navigator.clipboard.writeText(generatedXml).then(() => {
    copyBtn.querySelector('span').textContent = 'Copiado ✓';
    setTimeout(() => copyBtn.querySelector('span').textContent = 'Copiar', 2000);
  });
});

// ── Download ──────────────────────────────────────────────────────────────────
downloadBtn.addEventListener('click', () => {
  if (!generatedXml) return;
  const blob = new Blob([generatedXml], { type: 'application/xml' });
  const url  = URL.createObjectURL(blob);
  const a    = Object.assign(document.createElement('a'), { href: url, download: generatedFilename + '.xml' });
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
});

// ── Helpers ───────────────────────────────────────────────────────────────────
function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
