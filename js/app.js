/* XML JATS · app.js */
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
const loadPatternBtn = document.getElementById('loadPatternBtn');
const patternXmlField = document.getElementById('patternXml');
const generateManualBtn = document.getElementById('generateManualBtn');
const manualDoi = document.getElementById('manualDoi');
const manualPubDay = document.getElementById('manualPubDay');
const manualPubMonth = document.getElementById('manualPubMonth');
const manualPubYear = document.getElementById('manualPubYear');
const manualVolume = document.getElementById('manualVolume');
const manualEloc = document.getElementById('manualEloc');
const manualFunding = document.getElementById('manualFunding');
const manualConflict = document.getElementById('manualConflict');
const manualContrib = document.getElementById('manualContrib');
const uploadPanel = document.querySelector('.upload-panel');
const manualMetaPanel = document.querySelector('.manual-meta-panel');

let currentFile = null;
let generatedXml = '';
let generatedFilename = '';

async function loadPatternXml() {
  const candidates = ['pattern.xml', patternXmlField ? null : null].filter(Boolean);
  for (const url of candidates) {
    try {
      const resp = await fetch(url, { cache: 'no-store' });
      if (!resp.ok) continue;
      const raw = await resp.text();
      if (raw && raw.trim()) return raw.trim();
    } catch (_) {
      // ignore and continue to the next source
    }
  }

  return patternXmlField ? patternXmlField.value.trim() : '';
}

async function showPatternFrontOnly() {
  const raw = await loadPatternXml();
  if (!raw) {
    showAlert('No hay XML patrón disponible', 'error');
    return;
  }

  generatedXml = raw;
  generatedFilename = 'patron_scielo_article';
  xmlOutput.textContent = generatedXml;
  resultPanel.classList.remove('hidden');
  metaPanel.classList.add('hidden');
  if (uploadPanel) uploadPanel.classList.remove('hidden');
  if (manualMetaPanel) manualMetaPanel.classList.remove('hidden');
  setStep(4);
  showAlert('XML patrón cargado. La vista quedó en modo front-only.', 'success');

  try {
    populateFormFromPattern(raw);
  } catch (err) {
    console.warn('No se pudo autopoblar desde el patrón:', err);
  }
}

// No auto-load pattern.xml; keep it as an optional manual action.

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

  try {
    const form = new FormData();
    form.append('file', currentFile);

    const resp = await fetch('convert.php', {
      method: 'POST',
      body: form
    });

    const data = await resp.json();
    if (!resp.ok || !data.success) {
      throw new Error(data.error || 'No se pudo convertir el archivo');
    }

    setProgress(70, 'Generando XML...');

    generatedXml = data.xml || '';
    generatedFilename = data.filename || 'documento';
    xmlOutput.textContent = generatedXml;
    resultPanel.classList.remove('hidden');

    if (data.metadata) {
      renderMetadata(data.metadata);
      metaPanel.classList.remove('hidden');
    }

    setStep(3);
    setProgress(100, 'Listo');
    setStep(4);
    showAlert('✅ XML generado desde el Word.', 'success');
    setTimeout(() => resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);

  } catch (err) {
    showAlert('❌ Error: ' + err.message, 'error');
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

  // ── Cargar patrón XML (front-only)
  if (loadPatternBtn && patternXmlField) {
    loadPatternBtn.addEventListener('click', () => {
      showPatternFrontOnly().then(() => {
        resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

// Autopoblar campos del formulario a partir del XML patrón
function populateFormFromPattern(xmlString) {
  const parser = new DOMParser();
  const parseableXml = /xmlns:xlink=/.test(xmlString)
    ? xmlString
    : xmlString.replace('<article ', '<article xmlns:xlink="http://www.w3.org/1999/xlink" ');
  const doc = parser.parseFromString(parseableXml, 'application/xml');
  if (doc.getElementsByTagName('parsererror').length) throw new Error('XML inválido');

  const q = sel => {
    const el = doc.querySelector(sel);
    return el ? (el.textContent || '').trim() : '';
  };

  // DOI
  const doi = q('article-id[pub-id-type="doi"]');
  if (manualDoi) manualDoi.value = doi;

  // Pub-date
  const pub = doc.querySelector('pub-date[date-type="pub"]');
  if (pub) {
    const day = pub.querySelector('day')?.textContent?.trim() || '';
    const month = pub.querySelector('month')?.textContent?.trim() || '';
    const year = pub.querySelector('year')?.textContent?.trim() || '';
    if (manualPubDay) manualPubDay.value = day;
    if (manualPubMonth) manualPubMonth.value = month;
    if (manualPubYear) manualPubYear.value = year;
  }

  // Volume / elocation-id
  const volume = q('volume');
  const eloc = q('elocation-id');
  if (manualVolume) manualVolume.value = volume;
  if (manualEloc) manualEloc.value = eloc;

  // Funding: join award-group sources and funding-statement
  const fundingSources = Array.from(doc.querySelectorAll('funding-group award-group funding-source')).map(n=>n.textContent.trim()).filter(Boolean);
  const fundingStatement = q('funding-statement');
  const fundingText = fundingSources.length ? fundingSources.join('; ') : fundingStatement;
  if (manualFunding) manualFunding.value = fundingText;

  // Conflict and contributions
  const conflict = q('fn[fn-type="conflict"] p') || q('fn[fn-type="conflict"]');
  const contrib = q('fn[fn-type="equal"] p') || q('fn[fn-type="equal"]');
  if (manualConflict) manualConflict.value = conflict;
  if (manualContrib) manualContrib.value = contrib;

  // Affiliations: populate first aff into manualAff
  const aff1 = q('aff[id="aff1"] institution[content-type="original"]') || q('aff institution[content-type="original"]');
  const mainAff = aff1 || q('aff[id="aff1"] institution') || q('aff institution');
  const manualAffField = document.getElementById('manualAff');
  if (manualAffField) manualAffField.value = mainAff;

  // Authors: rebuild the authors list in the UI
  const authors = Array.from(doc.querySelectorAll('contrib-group contrib[contrib-type="author"]'));
  const authorsList = document.querySelector('.manual-authors');
  if (authorsList && authors.length) {
    authorsList.innerHTML = '';
    authors.forEach((c, idx) => {
      const surname = c.querySelector('surname')?.textContent?.trim() || '';
      const given = c.querySelector('given-names')?.textContent?.trim() || '';
      const orcid = c.querySelector('contrib-id[contrib-id-type="orcid"]')?.textContent?.trim() || '';
      const sup = idx + 1;
      const li = document.createElement('li');
      const nameText = `${given} ${surname}`.trim() || (c.textContent||'').trim();
      if (orcid) {
        const a = document.createElement('a');
        a.href = orcid;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = orcid.replace('https://orcid.org/','');
        li.innerHTML = `${esc(nameText)}<sup>${sup}</sup> — `;
        li.appendChild(a);
      } else {
        li.innerHTML = `${esc(nameText)}<sup>${sup}</sup>`;
      }
      authorsList.appendChild(li);
    });
  }

  // Render a minimal metadata preview
  const kwdGroupsEs = Array.from(doc.querySelectorAll('kwd-group')).filter(n => (n.getAttribute('xml:lang') || '').toLowerCase() === 'es');
  const kwdsEs = kwdGroupsEs.flatMap(group => Array.from(group.querySelectorAll('kwd')).map(n => n.textContent.trim()).filter(Boolean));
  const metaPreview = { doi, authors: authors.map(a=>({ name: ((a.querySelector('given-names')?.textContent||'') + ' ' + (a.querySelector('surname')?.textContent||'')).trim() })), kwdsEs, funding: fundingSources };
  renderMetadata(metaPreview);
  metaPanel.classList.remove('hidden');
}

  // ── Generar XML desde metadatos manuales
  if (generateManualBtn) {
    generateManualBtn.addEventListener('click', async () => {
      const meta = collectManualMeta();

      // Generación completamente en el cliente
      setStep(2);
      setProgress(30, 'Generando XML en el navegador...');
      await delay(150);
      try {
        const xml = buildJatsFromMeta(meta);
        generatedXml = xml;
        generatedFilename = (meta.articleTitle || 'manual').replace(/[^a-z0-9]+/gi, '_').substring(0,80) + '_JATS_SPS19';
        xmlOutput.textContent = generatedXml;
        // Mostrar metadata
        renderMetadata(meta);
        metaPanel.classList.remove('hidden');
        resultPanel.classList.remove('hidden');
        setStep(4);
        showAlert('✅ XML generado localmente en el front-end.', 'success');
      } catch (err) {
        showAlert('❌ Error al generar XML: ' + err.message, 'error');
        setStep(1);
      } finally {
        setProgress(100, 'Listo');
      }
    });
  }

// Construye el objeto meta desde el formulario manual
function collectManualMeta() {
  const meta = {};
  meta['doi'] = manualDoi.value.trim();
  meta['received'] = '';
  meta['revised'] = '';
  meta['accepted'] = '';
  const day = manualPubDay.value.trim();
  const month = manualPubMonth.value.trim();
  const year = manualPubYear.value.trim();
  if (day || month || year) meta['pubdate'] = `${day} ${month} ${year}`.trim();
  meta['volume'] = manualVolume.value.trim();
  meta['elocation-id'] = manualEloc.value.trim();
  meta['funding'] = manualFunding.value ? manualFunding.value.split(';').map(s=>s.trim()).filter(Boolean) : [];
  meta['conflict'] = manualConflict.value.trim();
  meta['contributions'] = manualContrib.value ? [manualContrib.value.trim()] : [];
  meta['authors'] = collectManualAuthors();
  const aff = document.getElementById('manualAff')?.value?.trim() || '';
  meta['affiliations'] = aff ? [aff] : [];
  meta['articleTitle'] = document.title || 'Artículo desde metadatos manuales';
  meta['articleTitleEn'] = '';
  return meta;
}

function collectManualAuthors() {
  const list = document.querySelectorAll('.manual-authors li');
  const authors = [];
  list.forEach(li => {
    const a = li.querySelector('a');
    const orcid = a ? a.getAttribute('href') || '' : '';
    const text = (li.textContent || '').replace(/\s+—\s+.*/,'').trim();
    const name = text.replace(/\s+\d+$/, '').trim();
    if (name) authors.push({ name, orcid: orcid.replace('https://orcid.org/','') });
  });
  return authors;
}

// Refrescar XML al editar metadatos manuales (cuando ya hay salida visible)
const manualInputs = [
  manualDoi, manualPubDay, manualPubMonth, manualPubYear,
  manualVolume, manualEloc, manualFunding, manualConflict, manualContrib,
  document.getElementById('manualAff')
].filter(Boolean);

manualInputs.forEach(input => {
  input.addEventListener('input', () => {
    if (!resultPanel || resultPanel.classList.contains('hidden')) return;
    const meta = collectManualMeta();
    try {
      generatedXml = buildJatsFromMeta(meta);
      generatedFilename = (meta.articleTitle || 'manual').replace(/[^a-z0-9]+/gi, '_').substring(0,80) + '_JATS_SPS19';
      xmlOutput.textContent = generatedXml;
      renderMetadata(meta);
      metaPanel.classList.remove('hidden');
    } catch (_) {
      // no alert spam while typing
    }
  });
});

// Construye un JATS XML simple a partir del objeto meta (cliente)
function buildJatsFromMeta(meta) {
  const e = s => esc(String(s || ''));
  const doi = meta.doi || '';
  const pubId = doi ? doi.split('/').slice(1).join('/') : 'XXXX';
  const year = (meta.accepted && meta.accepted.match(/\d{4}/)) ? meta.accepted.match(/\d{4}/)[0] : (meta.pubdate && meta.pubdate.match(/\d{4}/) ? meta.pubdate.match(/\d{4}/)[0] : new Date().getFullYear());

  let journalMeta = `  <journal-meta>\n`;
  journalMeta += `      <journal-id journal-id-type="nlm-ta">${e(meta.journalAbbrev || meta.journalTitle || '')}</journal-id>\n`;
  journalMeta += `      <journal-title-group>\n        <journal-title>${e(meta.journalTitle || '')}</journal-title>\n        <abbrev-journal-title abbrev-type="publisher">${e(meta.journalAbbrev || '')}</abbrev-journal-title>\n      </journal-title-group>\n`;
  if (meta.issn_ppub) journalMeta += `      <issn pub-type="ppub">${e(meta.issn_ppub)}</issn>\n`;
  if (meta.issn_epub) journalMeta += `      <issn pub-type="epub">${e(meta.issn_epub)}</issn>\n`;
  journalMeta += `      <publisher>\n        <publisher-name>${e(meta.publisher || '')}</publisher-name>\n      </publisher>\n    </journal-meta>`;

  // Article-meta
  let articleMeta = `    <article-meta>\n      <article-id pub-id-type="publisher-id">${e(pubId)}</article-id>\n`;
  if (doi) articleMeta += `      <article-id pub-id-type="doi">${e(doi)}</article-id>\n`;
  articleMeta += `      <article-categories>\n        <subj-group subj-group-type="heading">\n          <subject>Artículo</subject>\n        </subj-group>\n      </article-categories>\n`;

  articleMeta += `      <title-group>\n        <article-title xml:lang="es">${e(meta.articleTitle || '')}</article-title>\n`;
  if (meta.articleTitleEn) articleMeta += `        <trans-title-group xml:lang="en">\n          <trans-title>${e(meta.articleTitleEn)}</trans-title>\n        </trans-title-group>\n`;
  articleMeta += `      </title-group>\n`;

  // Contributors
  articleMeta += `      <contrib-group>\n`;
  (meta.authors||[]).forEach((a,i)=>{
    const n=i+1;
    const parts = (a.name||'').split(/\s+/);
    const surname = parts.length?parts.pop():'';
    const given = parts.join(' ');
    articleMeta += `      <contrib contrib-type="author">\n        <name>\n          <surname>${e(surname)}</surname>\n          <given-names>${e(given)}</given-names>\n        </name>\n`;
    if (a.orcid) articleMeta += `        <contrib-id contrib-id-type="orcid">https://orcid.org/${e(a.orcid)}</contrib-id>\n`;
    articleMeta += `        <xref ref-type="aff" rid="aff${n}"><sup>${n}</sup></xref>\n      </contrib>\n`;
  });
  articleMeta += `      </contrib-group>\n`;

  // Affiliations
  (meta.affiliations||[]).forEach((aff,i)=>{
    const n=i+1;
    articleMeta += `      <aff id="aff${n}">\n        <label>${n}</label>\n        <institution content-type="original">${e(aff)}</institution>\n      </aff>\n`;
  });

  // Author notes (conflict & contributions)
  articleMeta += `      <author-notes>\n`;
  if (meta.conflict) {
    articleMeta += `        <fn fn-type="conflict" id="fn-conf">\n          <label>Conflicto de Intereses</label>\n          <p>${e(meta.conflict)}</p>\n        </fn>\n`;
  }
  if (meta.contributions && meta.contributions.length) {
    articleMeta += `        <fn fn-type="equal" id="fn-contrib">\n          <label>Contribución autoral</label>\n          <p>${e(meta.contributions.join(' '))}</p>\n        </fn>\n`;
  }
  articleMeta += `      </author-notes>\n`;

  // Pub date, history
  if (meta.pubdate || meta.volume || meta['elocation-id']) {
    articleMeta += `      <pub-date date-type="pub" publication-format="electronic">\n`;
    if (meta.pubdate) {
      const parts = meta.pubdate.split(/\s+/);
      if (parts[0]) articleMeta += `        <day>${e(parts[0])}</day>\n`;
      if (parts[1]) articleMeta += `        <month>${e(parts[1])}</month>\n`;
      if (parts[2]) articleMeta += `        <year>${e(parts[2])}</year>\n`;
    } else {
      articleMeta += `        <year>${e(year)}</year>\n`;
    }
    articleMeta += `      </pub-date>\n`;
  }
  if (meta.volume) articleMeta += `      <volume>${e(meta.volume)}</volume>\n`;
  if (meta['elocation-id']) articleMeta += `      <elocation-id>${e(meta['elocation-id'])}</elocation-id>\n`;

  // History (received/revised/accepted years if present)
  if (meta.received || meta.revised || meta.accepted) {
    articleMeta += `      <history>\n`;
    if (meta.received) articleMeta += `        <date date-type="received"><year>${e((meta.received.match(/\d{4}/)||[''])[0])}</year></date>\n`;
    if (meta.revised) articleMeta += `        <date date-type="rev-recd"><year>${e((meta.revised.match(/\d{4}/)||[''])[0])}</year></date>\n`;
    if (meta.accepted) articleMeta += `        <date date-type="accepted"><year>${e((meta.accepted.match(/\d{4}/)||[''])[0])}</year></date>\n`;
    articleMeta += `      </history>\n`;
  }

  // Permissions
  articleMeta += `      <permissions>\n        <license license-type="open-access" xlink:href="https://creativecommons.org/licenses/by/4.0/" xml:lang="es">\n          <license-p>Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons</license-p>\n        </license>\n      </permissions>\n`;

  // Abstracts
  if (meta.abstractEs) {
    articleMeta += `      <abstract xml:lang="es">\n        <title>Resumen</title>\n        <p>${e(meta.abstractEs)}</p>\n      </abstract>\n`;
  }
  if (meta.abstractEn) {
    articleMeta += `      <trans-abstract xml:lang="en">\n        <title>Abstract</title>\n        <p>${e(meta.abstractEn)}</p>\n      </trans-abstract>\n`;
  }

  // Keywords
  if (meta.kwdsEs && meta.kwdsEs.length) {
    articleMeta += `      <kwd-group xml:lang="es">\n        <title>Palabras claves:</title>\n`;
    meta.kwdsEs.forEach(k=>{ articleMeta += `        <kwd>${e(k)}</kwd>\n`; });
    articleMeta += `      </kwd-group>\n`;
  }
  if (meta.kwdsEn && meta.kwdsEn.length) {
    articleMeta += `      <kwd-group xml:lang="en">\n        <title>Keywords:</title>\n`;
    meta.kwdsEn.forEach(k=>{ articleMeta += `        <kwd>${e(k)}</kwd>\n`; });
    articleMeta += `      </kwd-group>\n`;
  }

  // Funding
  if (meta.funding && meta.funding.length) {
    articleMeta += `      <funding-group>\n`;
    meta.funding.forEach(f=>{ articleMeta += `        <award-group award-type="contract">\n          <funding-source>\n            ${e(f)}\n          </funding-source>\n        </award-group>\n`; });
    articleMeta += `        <funding-statement>${e((meta.funding||[]).join('; '))}</funding-statement>\n      </funding-group>\n`;
  }

  // Counts (allow override)
  const refCount = meta.refCount || 0;
  articleMeta += `      <counts>\n        <fig-count count="0"/>\n        <table-count count="0"/>\n        <equation-count count="0"/>\n        <ref-count count="${e(refCount)}"/>\n        <page-count count="1"/>\n      </counts>\n`;

  articleMeta += `    </article-meta>\n`;

  // Body placeholder
  let body = `  <body>\n    <sec sec-type="intro">\n      <title>Introducción</title>\n      <p>[Completar con el contenido del manuscrito]</p>\n    </sec>\n  </body>\n`;

  // Back matter with references and fn-group
  let back = `  <back>\n`;
  if (meta.conflict || (meta.contributions && meta.contributions.length)) {
    back += `    <fn-group>\n`;
    if (meta.conflict) back += `      <fn fn-type="conflict">\n        <label>Conflicto de Intereses</label>\n        <p>${e(meta.conflict)}</p>\n      </fn>\n`;
    if (meta.contributions && meta.contributions.length) meta.contributions.forEach((c,i)=>{ back += `      <fn fn-type="con">\n        <p>${e(c)}</p>\n      </fn>\n`; });
    back += `    </fn-group>\n`;
  }
  back += `    <ref-list>\n      <title>Referencias bibliográficas</title>\n      <ref id="B1">\n        <label>1</label>\n        <element-citation publication-type="journal">\n          <comment>[Completar referencias en formato JATS element-citation]</comment>\n        </element-citation>\n      </ref>\n    </ref-list>\n  </back>\n`;

  const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20121330//EN"\n  "https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1-1.dtd">\n<article dtd-version="1.1" article-type="research-article" specific-use="sps-1.9" xml:lang="${e(meta.lang||'es')}" xmlns:xlink="http://www.w3.org/1999/xlink">\n\n  <front>\n${journalMeta}\n\n${articleMeta}\n  </front>\n\n${body}\n${back}`;

  return xml;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

function esc(s) {
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
