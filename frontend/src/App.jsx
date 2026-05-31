import React, { useEffect, useMemo, useState } from 'react';
import '../css/style.css';

const createEmptyManual = () => ({
  // Estado base del formulario manual: siempre parte limpio para evitar arrastre entre cargas.
  affiliations: [{ id: 'aff1', original: '', email: '' }],
  funding: [],
  authors: [{ name: '', orcid: '' }],
  figCount: 0,
  tableCount: 0,
  equationCount: 0,
  pageCount: 1,
  references: [],
  referencesText: '',
  issn_ppub: '',
  issn_epub: '',
  sps: '1.9',
  doi: '',
  pubDay: '',
  pubMonth: '',
  pubYear: '',
  pubdate: '',
  volume: '',
  elocationId: '',
  fundingText: '',
  conflict: '',
  contributionsText: '',
  refCount: 0,
  publisherId: '',
  articleCategory: 'research-article',
  articleCategoryLabel: 'Artículo de investigación',
  licenseHref: 'https://creativecommons.org/licenses/by/4.0/',
  licenseText: 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons',
  journalTitle: '',
  journalAbbrev: '',
  publisher: '',
  lang: 'es',
  articleTitle: '',
  articleTitleEn: '',
  abstractEs: '',
  abstractEn: '',
  kwdsEs: [],
  kwdsEn: [],
  kwdsEsText: '',
  kwdsEnText: '',
  sectionsText: '',
  received: '',
  revised: '',
  accepted: ''
});

function splitPubDate(pubdate) {
  // Convierte una fecha libre ("DD MM YYYY") en campos separados para inputs controlados.
  const parts = String(pubdate || '').trim().split(/\s+/).filter(Boolean);
  return {
    pubDay: parts[0] || '',
    pubMonth: parts[1] || '',
    pubYear: parts[2] || ''
  };
}

function formatFundingItem(item) {
  if (!item) {
    return '';
  }

  if (typeof item === 'string') {
    return item.trim();
  }

  const source = String(item.source || '').trim();
  const awardId = String(item.awardId || '').trim();

  if (source && awardId) {
    return `${source} ${awardId}`;
  }

  return source || awardId || String(item).trim();
}

function formatListValue(value, separator = ' · ') {
  if (!Array.isArray(value) || !value.length) {
    return '';
  }

  return value
    .map(item => String(item || '').trim())
    .filter(Boolean)
    .join(separator);
}

function normalizeOrcid(value) {
  const text = String(value || '').trim();
  const match = text.match(/(?:https?:\/\/orcid\.org\/)?(\d{4}-\d{4}-\d{4}-[\dX]{4})/i);

  return match ? match[1] : text.replace(/^https?:\/\/orcid\.org\//i, '');
}

function extractOrcidsFromXml(xml) {
  const matches = String(xml || '').match(/https?:\/\/orcid\.org\/\d{4}-\d{4}-\d{4}-[\dX]{4}/gi) || [];

  return matches.map(normalizeOrcid);
}

function buildManualFromMetadata(metadata) {
  const base = createEmptyManual();
  if (!metadata) {
    return base;
  }

  const orcidsFromXml = extractOrcidsFromXml(metadata.xml || metadata.generatedXml || '');

  // Normaliza autores detectados y limpia prefijo URL en ORCID para editar solo el identificador.
  const authors = Array.isArray(metadata.authors)
    ? metadata.authors
      .map((author, index) => ({
        name: String(author?.name || '').trim(),
        orcid: normalizeOrcid(author?.orcid || author?.contribId || author?.['contrib-id'] || author?.orcidUrl || orcidsFromXml[index] || '')
      }))
      .filter(author => author.name || author.orcid)
    : [];

  // Mapea afiliaciones detectadas, conservando email cuando viene en arrays paralelos del backend.
  const affiliations = Array.isArray(metadata.affiliations)
    ? metadata.affiliations
      .map((affiliation, index) => ({
        id: `aff${index + 1}`,
        original: String(typeof affiliation === 'string' ? affiliation : (affiliation?.original || '')).trim(),
        email: String(
          typeof affiliation === 'string'
            ? (metadata.affiliations_email?.[index] || '')
            : (affiliation?.email || metadata.affiliations_email?.[index] || '')
        ).trim()
      }))
      .filter(affiliation => affiliation.original || affiliation.email)
    : [];

  const fundingList = Array.isArray(metadata.funding)
    ? metadata.funding.map(formatFundingItem).filter(Boolean)
    : [];
  const fundingStatement = String(metadata.fundingStatement || '').trim();
  const sectionTitles = Array.isArray(metadata.bodySections)
    ? metadata.bodySections.map(section => String(section?.title || '').trim()).filter(Boolean)
    : [];

  const pub = splitPubDate(metadata.pubdate);
  const derivedPublisherId = String(metadata.publisherId || metadata['publisher-id'] || '').trim() || (
    String(metadata.doi || '').includes('/')
      ? String(metadata.doi || '').split('/').slice(1).join('/')
      : ''
  );

  // Lleva los metadatos detectados al formulario manual para permitir correccion inmediata.
  return {
    ...base,
    issn_ppub: String(metadata.issn_ppub || '').trim(),
    issn_epub: String(metadata.issn_epub || '').trim(),
    sps: String(metadata.sps || '1.9').trim() || '1.9',
    doi: String(metadata.doi || '').trim(),
    pubDay: pub.pubDay,
    pubMonth: pub.pubMonth,
    pubYear: pub.pubYear,
    pubdate: String(metadata.pubdate || '').trim(),
    volume: String(metadata.volume || '').trim(),
    elocationId: String(metadata['elocation-id'] || metadata.elocationId || '').trim(),
    fundingText: fundingStatement || fundingList.join('; '),
    conflict: String(metadata.conflict || '').trim(),
    contributionsText: Array.isArray(metadata.contributions)
      ? metadata.contributions.map(entry => String(entry || '').trim()).filter(Boolean).join(' ')
      : String(metadata.contributionsText || '').trim(),
    refCount: Number(metadata.refCount || 0) || 0,
    journalTitle: String(metadata.journalTitle || '').trim(),
    journalAbbrev: String(metadata.journalAbbrev || metadata.journalTitle || '').trim(),
    publisher: String(metadata.publisher || '').trim(),
    lang: String(metadata.lang || 'es').trim() || 'es',
    articleTitle: String(metadata.articleTitle || '').trim(),
    articleTitleEn: String(metadata.articleTitleEn || '').trim(),
    abstractEs: String(metadata.abstractEs || '').trim(),
    abstractEn: String(metadata.abstractEn || '').trim(),
    kwdsEs: Array.isArray(metadata.kwdsEs) ? metadata.kwdsEs : [],
    kwdsEn: Array.isArray(metadata.kwdsEn) ? metadata.kwdsEn : [],
    kwdsEsText: Array.isArray(metadata.kwdsEs) ? metadata.kwdsEs.join('; ') : '',
    kwdsEnText: Array.isArray(metadata.kwdsEn) ? metadata.kwdsEn.join('; ') : '',
    sectionsText: Array.isArray(metadata.sections) ? metadata.sections.join(' | ') : sectionTitles.join(' | '),
    bodySections: Array.isArray(metadata.bodySections) ? metadata.bodySections : [],
    received: String(metadata.received || '').trim(),
    revised: String(metadata.revised || '').trim(),
    accepted: String(metadata.accepted || '').trim(),
    funding: fundingList,
    fundingStatement,
    references: Array.isArray(metadata.references) ? metadata.references : [],
    sections: Array.isArray(metadata.sections) ? metadata.sections : [],
    affiliations_norm: Array.isArray(metadata.affiliations_norm) ? metadata.affiliations_norm : [],
    affiliations_orgdiv1: Array.isArray(metadata.affiliations_orgdiv1) ? metadata.affiliations_orgdiv1 : [],
    affiliations_orgdiv2: Array.isArray(metadata.affiliations_orgdiv2) ? metadata.affiliations_orgdiv2 : [],
    affiliations_orgname: Array.isArray(metadata.affiliations_orgname) ? metadata.affiliations_orgname : [],
    affiliations_state: Array.isArray(metadata.affiliations_state) ? metadata.affiliations_state : [],
    affiliations_country: Array.isArray(metadata.affiliations_country) ? metadata.affiliations_country : [],
    affiliations_country_name: Array.isArray(metadata.affiliations_country_name) ? metadata.affiliations_country_name : [],
    publisherId: derivedPublisherId,
    articleCategory: String(metadata.articleCategory || 'research-article').trim() || 'research-article',
    articleCategoryLabel: String(metadata.articleCategoryLabel || metadata.articleCategoryText || 'Artículo de investigación').trim() || 'Artículo de investigación',
    licenseHref: String(metadata.licenseHref || 'https://creativecommons.org/licenses/by/4.0/').trim() || 'https://creativecommons.org/licenses/by/4.0/',
    licenseText: String(metadata.licenseText || 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons').trim() || 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons',
    authors: authors.length ? authors : base.authors,
    affiliations: affiliations.length ? affiliations : base.affiliations
  };
}

function App() {
  const [currentFile, setCurrentFile] = useState(null);
  const [dragOver, setDragOver] = useState(false);
  const [alert, setAlert] = useState({ type: 'info', message: '', visible: false });
  const [step, setStep] = useState(1);
  const [progress, setProgress] = useState({ visible: false, pct: 0, label: 'Subiendo...' });
  const [generatedXml, setGeneratedXml] = useState('');
  const [generatedFilename, setGeneratedFilename] = useState('');
  const [generatedMode, setGeneratedMode] = useState('');
  const [metadata, setMetadata] = useState(null);
  const [metaVisible, setMetaVisible] = useState(false);
  const [resultVisible, setResultVisible] = useState(false);
  const [copyLabel, setCopyLabel] = useState('Copiar');
  const [manual, setManual] = useState(() => createEmptyManual());

  const showAlert = (message, type) => {
    setAlert({ type, message, visible: true });
  };

  const metaFields = useMemo(() => {
    // Tarjetas de inspeccion de metadatos: se recalculan solo cuando cambia "metadata".
    if (!metadata) {
      return [];
    }

    const fields = [
      { k: 'DOI', v: metadata.doi },
      { k: 'Idioma', v: metadata.lang },
      { k: 'SPS', v: metadata.sps },
      { k: 'ISSN impreso', v: metadata.issn_ppub },
      { k: 'ISSN digital', v: metadata.issn_epub },
      { k: 'Revista', v: metadata.journalTitle },
      { k: 'Publisher', v: metadata.publisher },
      { k: 'Autores', v: metadata.authors?.map(author => author.name).join(' · ') },
      { k: 'ORCID', v: metadata.authors?.map(author => author.orcid).filter(Boolean).join(' · ') },
      { k: 'Título', v: metadata.articleTitle },
      { k: 'Título (en)', v: metadata.articleTitleEn },
      { k: 'Recibido', v: metadata.received },
      { k: 'Versión final', v: metadata.revised },
      { k: 'Aprobado', v: metadata.accepted },
      { k: 'Keywords (es)', v: metadata.kwdsEs?.join(', ') },
      { k: 'Keywords (en)', v: metadata.kwdsEn?.join(', ') },
      { k: 'Financiamiento', v: formatListValue(Array.isArray(metadata.funding) ? metadata.funding.map(formatFundingItem) : [], '; ') || metadata.fundingStatement },
      { k: 'Secciones', v: formatListValue(Array.isArray(metadata.sections) ? metadata.sections : (metadata.bodySections || []).map(section => section?.title), ' · ') }
    ];

    return fields.map(field => ({
      ...field,
      display: field.v || 'No detectado',
      empty: !field.v
    }));
  }, [metadata]);

  useEffect(() => {
    // Oculta la barra de progreso un instante despues de llegar a 100 para evitar parpadeo.
    if (progress.pct < 100) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      setProgress(current => ({ ...current, visible: false }));
    }, 700);

    return () => window.clearTimeout(timer);
  }, [progress.pct]);

  // Se eliminó la carga automática de un XML de referencia de producción.

  useEffect(() => {
    // Solo el modo local usa el generador simplificado del navegador.
    if (generatedMode !== 'local-manual' || !resultVisible) {
      return;
    }

    const meta = collectManualMeta(manual);
    setGeneratedXml(buildJatsFromMeta(meta));
    setMetadata(meta);
    setMetaVisible(true);
  }, [generatedMode, manual, resultVisible]);

  const resetPanels = () => {
    // Limpia paneles de salida para que la UI no mezcle resultados de archivos distintos.
    setMetaVisible(false);
    setResultVisible(false);
    setGeneratedXml('');
    setGeneratedFilename('');
    setMetadata(null);
  };

  const handleFile = file => {
    if (!file.name.toLowerCase().endsWith('.docx')) {
      showAlert('Solo se aceptan archivos .docx', 'error');
      return;
    }

    // Al cargar un nuevo archivo se resetea metadata manual y paneles para empezar de cero.
    setCurrentFile(file);
    setManual(createEmptyManual());
    resetPanels();
    setAlert({ type: 'info', message: '', visible: false });
    setStep(1);
  };

  const handleConvert = async () => {
    if (!currentFile) {
      return;
    }

    // Flujo de conversion server-side: subida DOCX, parseo en backend y render de XML detectado.
    setGeneratedMode('upload');
    setStep(2);
    setProgress({ visible: true, pct: 15, label: 'Subiendo archivo...' });
    setAlert({ type: 'info', message: '', visible: false });
    setMetaVisible(false);
    setResultVisible(false);
    setGeneratedXml('');

    try {
      const form = new FormData();
      form.append('file', currentFile);

      const response = await fetch('../backend/convert.php', {
        method: 'POST',
        body: form
      });

      const raw = await response.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error('Respuesta invalida del servidor. Verifica el endpoint /backend/convert.php');
      }
      if (!response.ok || !data.success) {
        throw new Error(data.error || 'No se pudo convertir el archivo');
      }

      setProgress({ visible: true, pct: 70, label: 'Generando XML...' });
      setGeneratedXml(data.xml || '');
      setGeneratedFilename(data.filename || 'documento');
      const responseMetadata = data.metadata ? { ...data.metadata, xml: data.xml || '' } : null;
      setMetadata(responseMetadata);
      // Sincroniza el formulario manual con lo extraido para permitir ajustes finos sin recargar.
      setManual(buildManualFromMetadata(responseMetadata));
      setMetaVisible(Boolean(responseMetadata));
      setResultVisible(true);
      setStep(3);
      setProgress({ visible: true, pct: 100, label: 'Listo' });
      setStep(4);
      showAlert('XML generado desde el Word.', 'success');

      window.setTimeout(() => {
        document.getElementById('resultPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 150);
    } catch (error) {
      showAlert(`Error: ${error.message}`, 'error');
      setStep(1);
    }
  };

  const rebuildXmlOnServer = async meta => {
    const response = await fetch('../backend/convert.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'rebuild',
        metadata: normalizeMetaForServer(meta),
        filename: generatedFilename || 'documento'
      })
    });

    const raw = await response.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch {
      throw new Error('Respuesta invalida del servidor al regenerar el XML');
    }
    if (!response.ok || !data.success) {
      throw new Error(data.error || 'No se pudo regenerar el XML');
    }

    return data;
  };

  const handleManualGenerate = async () => {
    const meta = mergeMetadataForQuickEdit(metadata, collectManualMeta(manual));

    setGeneratedMode(metadata ? 'server-manual' : 'local-manual');
    setStep(2);
    setProgress({ visible: true, pct: 30, label: metadata ? 'Regenerando XML completo...' : 'Generando XML en el navegador...' });
    await delay(150);

    try {
      if (metadata) {
        const data = await rebuildXmlOnServer(meta);
        setGeneratedXml(data.xml || '');
        setGeneratedFilename(data.filename || generatedFilename || buildManualFilename());
        setMetadata({ ...(data.metadata || meta), xml: data.xml || '' });
      } else {
        const xml = buildJatsFromMeta(meta);
        setGeneratedXml(xml);
        setGeneratedFilename(buildManualFilename());
        setMetadata(meta);
      }
      setMetaVisible(true);
      setResultVisible(true);
      setStep(4);
      showAlert(metadata ? 'XML regenerado completo con los metadatos editados.' : 'XML generado localmente en el front-end.', 'success');
    } catch (error) {
      showAlert(`Error al generar XML: ${error.message}`, 'error');
      setStep(1);
    } finally {
      setProgress({ visible: true, pct: 100, label: 'Listo' });
    }
  };

  const handleCopy = async () => {
    if (!generatedXml) {
      return;
    }

    await navigator.clipboard.writeText(generatedXml);
    setCopyLabel('Copiado ✓');
    window.setTimeout(() => setCopyLabel('Copiar'), 2000);
  };

  const handleDownload = () => {
    if (!generatedXml) {
      return;
    }

    // Descarga local del XML generado usando Blob para evitar roundtrip al servidor.
    const blob = new Blob([generatedXml], { type: 'application/xml' });
    const url = URL.createObjectURL(blob);
    const anchor = Object.assign(document.createElement('a'), {
      href: url,
      download: `${generatedFilename || 'documento'}.xml`
    });

    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  };

  const handleManualFieldChange = (key, value) => {
    setManual(current => ({ ...current, [key]: value }));
  };

  const handleSyncPublisherId = () => {
    setManual(current => ({
      ...current,
      publisherId: String(current.doi || '').trim().includes('/')
        ? String(current.doi || '').trim().split('/').slice(1).join('/')
        : String(current.publisherId || '').trim()
    }));
  };

  const handleManualAuthorChange = (index, key, value) => {
    setManual(current => ({
      ...current,
      authors: current.authors.map((author, authorIndex) => (
        authorIndex === index ? { ...author, [key]: value } : author
      ))
    }));
  };

  const handleAddManualAuthor = () => {
    setManual(current => ({
      ...current,
      authors: [...(current.authors || []), { name: '', orcid: '' }]
    }));
  };

  const handleRemoveManualAuthor = indexToRemove => {
    setManual(current => {
      const filtered = (current.authors || []).filter((_, index) => index !== indexToRemove);
      return {
        ...current,
        authors: filtered.length ? filtered : [{ name: '', orcid: '' }]
      };
    });
  };

  const handleManualAffiliationChange = (index, key, value) => {
    setManual(current => ({
      ...current,
      affiliations: (current.affiliations || []).map((affiliation, affIndex) => (
        affIndex === index ? { ...affiliation, [key]: value } : affiliation
      ))
    }));
  };

  const handleAddManualAffiliation = () => {
    setManual(current => ({
      ...current,
      affiliations: [...(current.affiliations || []), { id: `aff${(current.affiliations || []).length + 1}`, original: '', email: '' }]
    }));
  };

  const handleRemoveManualAffiliation = indexToRemove => {
    setManual(current => {
      const filtered = (current.affiliations || []).filter((_, index) => index !== indexToRemove);
      return {
        ...current,
        affiliations: filtered.length ? filtered : [{ id: 'aff1', original: '', email: '' }]
      };
    });
  };

  const pairedManualRows = useMemo(() => {
    const rowCount = Math.max(manual.authors.length, manual.affiliations.length);

    return Array.from({ length: rowCount }, (_, index) => ({
      index,
      author: manual.authors[index] || { name: '', orcid: '' },
      affiliation: manual.affiliations[index] || { id: `aff${index + 1}`, original: '', email: '' }
    }));
  }, [manual.authors, manual.affiliations]);

  const fileInputId = 'fileInput';

  return (
    <>
      <div className="noise" />

      <header className="header">
        <div className="header-inner">
          <div className="logo">
            <div className="logo-badge">XML</div>
            <div className="logo-text">
              <span className="logo-name">XML JATS</span>
              <span className="logo-ver">Automático</span>
            </div>
          </div>
          <div className="header-tag">
            <span className="dot dot--green" />
            Acceso abierto
          </div>
        </div>
      </header>

      <main className="main">
        <div className="steps-bar">
          <StepItem active={step === 1} done={step > 1} number={1} label="Subir .docx" />
          <div className="step-line" />
          <StepItem active={step === 2} done={step > 2} number={2} label="Extraer texto" />
          <div className="step-line" />
          <StepItem active={step === 3} done={step > 3} number={3} label="Generar XML" />
          <div className="step-line" />
          <StepItem active={step === 4} done={step > 4} number={4} label="Descargar" />
        </div>

        <section className="panel upload-panel">
          <div
            className={`drop-zone${dragOver ? ' drag-over' : ''}`}
            onClick={() => document.getElementById(fileInputId)?.click()}
            onDragOver={event => {
              event.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={event => {
              event.preventDefault();
              setDragOver(false);
              const file = event.dataTransfer.files?.[0];
              if (file) {
                handleFile(file);
              }
            }}
          >
            <input
              type="file"
              id={fileInputId}
              accept=".docx"
              hidden
              onChange={event => {
                const file = event.target.files?.[0];
                if (file) {
                  handleFile(file);
                }
              }}
            />
            <div className="drop-icon">
              <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                <rect x="8" y="4" width="26" height="36" rx="3" fill="var(--c-surface)" stroke="var(--c-border)" strokeWidth="1.5" />
                <rect x="28" y="4" width="6" height="6" rx="1" fill="var(--c-accent)" opacity="0.6" />
                <line x1="14" y1="18" x2="34" y2="18" stroke="var(--c-border)" strokeWidth="1.5" strokeLinecap="round" />
                <line x1="14" y1="24" x2="28" y2="24" stroke="var(--c-border)" strokeWidth="1.5" strokeLinecap="round" />
                <line x1="14" y1="30" x2="30" y2="30" stroke="var(--c-border)" strokeWidth="1.5" strokeLinecap="round" />
              </svg>
            </div>
            <p className="drop-title">Arrastrá tu archivo Word acá</p>
            <p className="drop-hint">o hacé clic para seleccionar · <code>.docx</code> · máx. 10MB</p>
          </div>

          {currentFile ? (
            <div className="file-pill">
              <span className="pill-icon">📝</span>
              <span className="pill-name">{currentFile.name}</span>
              <button
                className="pill-clear"
                type="button"
                onClick={() => {
                  setCurrentFile(null);
                  setManual(createEmptyManual());
                  resetPanels();
                  setAlert({ type: 'info', message: '', visible: false });
                  setStep(1);
                }}
              >
                ✕
              </button>
            </div>
          ) : null}

          {progress.visible ? (
            <div className="progress-track">
              <div className="progress-fill" style={{ width: `${progress.pct}%` }} />
              <span className="progress-label">{progress.label}</span>
            </div>
          ) : null}

          {alert.visible ? (
            <div className={`alert alert--${alert.type}`}>{alert.message}</div>
          ) : null}

          <button className="btn-convert" type="button" disabled={!currentFile} onClick={handleConvert}>
            <span className="btn-icon">⚡</span>
            <span id="btnLabel">Convertir a XML JATS</span>
          </button>
        </section>

        <section className="panel manual-meta-panel">
          <h2 className="panel-title">
            <span className="panel-title-icon">✍️</span>
            Metadatos manuales del front (edición rápida)
          </h2>
          <div className="manual-author-actions manual-author-actions--top">
            <button className="btn-action" type="button" onClick={handleAddManualAuthor}>+ Autor</button>
            <button className="btn-action" type="button" onClick={handleAddManualAffiliation}>+ Afiliación</button>
          </div>
          <div className="manual-grid">
            <div className="manual-row manual-row--table">
              <label>Autores y afiliaciones:</label>
              <table className="manual-paired-table">
                <thead>
                  <tr>
                    <th>Autor</th>
                    <th>Afiliación</th>
                  </tr>
                </thead>
                <tbody>
                  {pairedManualRows.map(row => (
                    <tr key={`manual-row-${row.index}`}>
                      <td>
                        <div className="manual-paired-cell">
                          <span className="author-index">{row.index + 1}.</span>
                          <input
                            type="text"
                            className="author-name"
                            value={row.author.name}
                            onChange={event => handleManualAuthorChange(row.index, 'name', event.target.value)}
                            aria-label={`Nombre del autor ${row.index + 1}`}
                          />
                          <label className="orcid-label" style={{ marginLeft: '8px', fontSize: '0.9em' }}>ORCID:</label>
                          <input
                            type="text"
                            className="author-orcid"
                            value={row.author.orcid}
                            onChange={event => handleManualAuthorChange(row.index, 'orcid', event.target.value)}
                            aria-label={`ORCID del autor ${row.index + 1}`}
                            placeholder="0000-0000-0000-0000"
                          />
                          <button
                            className="btn-action author-remove"
                            type="button"
                            onClick={() => handleRemoveManualAuthor(row.index)}
                            aria-label={`Quitar autor ${row.index + 1}`}
                          >
                            Quitar
                          </button>
                        </div>
                      </td>
                      <td>
                        <div className="manual-paired-cell">
                          <span className="author-index">{row.index + 1}.</span>
                          <input
                            type="text"
                            className="author-name"
                            value={row.affiliation.original}
                            onChange={event => handleManualAffiliationChange(row.index, 'original', event.target.value)}
                            aria-label={`Afiliación ${row.index + 1}`}
                            placeholder="Universidad, departamento, ciudad, país"
                          />
                          <input
                            type="text"
                            className="author-orcid"
                            value={row.affiliation.email}
                            onChange={event => handleManualAffiliationChange(row.index, 'email', event.target.value)}
                            aria-label={`Email de afiliación ${row.index + 1}`}
                            placeholder="correo@institucion.edu"
                          />
                          <button
                            className="btn-action author-remove"
                            type="button"
                            onClick={() => handleRemoveManualAffiliation(row.index)}
                            aria-label={`Quitar afiliación ${row.index + 1}`}
                          >
                            Quitar
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="manual-row">
              <label>DOI:</label>
              <input type="text" value={manual.doi || ''} onChange={event => handleManualFieldChange('doi', event.target.value)} placeholder="10.18294/sc.2026.5939" />
            </div>
            <div className="manual-row">
              <label>ISSN impreso/digital:</label>
              <input type="text" value={manual.issn_ppub || ''} onChange={event => handleManualFieldChange('issn_ppub', event.target.value)} placeholder="1414-9089" />
              <input type="text" value={manual.issn_epub || ''} onChange={event => handleManualFieldChange('issn_epub', event.target.value)} placeholder="1851-8265" />
            </div>
            <div className="manual-row">
              <label>Revista / Abreviatura:</label>
              <input type="text" value={manual.journalTitle || ''} onChange={event => handleManualFieldChange('journalTitle', event.target.value)} placeholder="Nombre de revista" />
              <input type="text" value={manual.journalAbbrev || ''} onChange={event => handleManualFieldChange('journalAbbrev', event.target.value)} placeholder="Abreviatura" />
            </div>
            <div className="manual-row">
              <label>Publisher / Idioma / SPS:</label>
              <input type="text" value={manual.publisher || ''} onChange={event => handleManualFieldChange('publisher', event.target.value)} placeholder="SciELO" />
              <input type="text" value={manual.lang || ''} onChange={event => handleManualFieldChange('lang', event.target.value)} placeholder="es" />
              <input type="text" value={manual.sps || ''} onChange={event => handleManualFieldChange('sps', event.target.value)} placeholder="1.9" />
            </div>
            <div className="manual-row">
              <label>Título (es/en):</label>
              <input type="text" value={manual.articleTitle || ''} onChange={event => handleManualFieldChange('articleTitle', event.target.value)} placeholder="Título en español" />
              <input type="text" value={manual.articleTitleEn || ''} onChange={event => handleManualFieldChange('articleTitleEn', event.target.value)} placeholder="Title in English" />
            </div>
            <div className="manual-row">
              <label>Resumen (es):</label>
              <textarea rows="3" value={manual.abstractEs || ''} onChange={event => handleManualFieldChange('abstractEs', event.target.value)} placeholder="Resumen" />
            </div>
            <div className="manual-row">
              <label>Abstract (en):</label>
              <textarea rows="3" value={manual.abstractEn || ''} onChange={event => handleManualFieldChange('abstractEn', event.target.value)} placeholder="Abstract" />
            </div>
            <div className="manual-row">
              <label>Keywords ES/EN:</label>
              <input type="text" value={manual.kwdsEsText || ''} onChange={event => handleManualFieldChange('kwdsEsText', event.target.value)} placeholder="salud; política; cuidado" />
              <input type="text" value={manual.kwdsEnText || ''} onChange={event => handleManualFieldChange('kwdsEnText', event.target.value)} placeholder="health; policy; care" />
            </div>
            <div className="manual-row">
              <label>Pub-date (DD MM YYYY):</label>
              <input type="text" size="2" value={manual.pubDay || ''} onChange={event => handleManualFieldChange('pubDay', event.target.value)} placeholder="11" />
              <input type="text" size="2" value={manual.pubMonth || ''} onChange={event => handleManualFieldChange('pubMonth', event.target.value)} placeholder="03" />
              <input type="text" size="4" value={manual.pubYear || ''} onChange={event => handleManualFieldChange('pubYear', event.target.value)} placeholder="2026" />
            </div>
            <div className="manual-row">
              <label>Volume:</label>
              <input type="text" value={manual.volume || ''} onChange={event => handleManualFieldChange('volume', event.target.value)} placeholder="22" />
              <label style={{ marginLeft: '12px' }}>Elocation-id:</label>
              <input type="text" value={manual.elocationId || ''} onChange={event => handleManualFieldChange('elocationId', event.target.value)} placeholder="e5939" />
            </div>
            <div className="manual-row">
              <label>Funding (texto):</label>
              <input type="text" value={manual.fundingText || ''} onChange={event => handleManualFieldChange('fundingText', event.target.value)} placeholder="CAPES; FAPESC" />
            </div>
            <div className="manual-row">
              <label>Front fijo / derivado:</label>
              <input type="text" value={manual.publisherId || ''} onChange={event => handleManualFieldChange('publisherId', event.target.value)} placeholder="publisher-id derivado del DOI" />
              <button className="btn-action" type="button" onClick={handleSyncPublisherId}>Derivar desde DOI</button>
            </div>
            <div className="manual-row">
              <label>Categoría del artículo:</label>
              <input type="text" value={manual.articleCategory || ''} onChange={event => handleManualFieldChange('articleCategory', event.target.value)} placeholder="research-article" />
              <input type="text" value={manual.articleCategoryLabel || ''} onChange={event => handleManualFieldChange('articleCategoryLabel', event.target.value)} placeholder="Etiqueta visible en el XML" />
            </div>
            <div className="manual-row">
              <label>Licencia:</label>
              <input type="text" value={manual.licenseHref || ''} onChange={event => handleManualFieldChange('licenseHref', event.target.value)} placeholder="https://creativecommons.org/licenses/by/4.0/" />
            </div>
            <div className="manual-row">
              <label>Texto de licencia:</label>
              <textarea rows="2" value={manual.licenseText || ''} onChange={event => handleManualFieldChange('licenseText', event.target.value)} placeholder="Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons" />
            </div>
            <div className="manual-row manual-row--dates">
              <label>Fechas (recibido/revisado/aceptado):</label>
              <div className="manual-dates">
                <div className="date-field">
                  <span className="small-label">Recibido</span>
                  <input type="text" value={manual.received || ''} onChange={event => handleManualFieldChange('received', event.target.value)} placeholder="11 03 2026" />
                </div>
                <div className="date-field">
                  <span className="small-label">Versión final</span>
                  <input type="text" value={manual.revised || ''} onChange={event => handleManualFieldChange('revised', event.target.value)} placeholder="20 03 2026" />
                </div>
                <div className="date-field">
                  <span className="small-label">Aprobado</span>
                  <input type="text" value={manual.accepted || ''} onChange={event => handleManualFieldChange('accepted', event.target.value)} placeholder="25 03 2026" />
                </div>
              </div>
            </div>
            <div className="manual-row">
              <label>Conflicto de intereses:</label>
              <input type="text" value={manual.conflict || ''} onChange={event => handleManualFieldChange('conflict', event.target.value)} placeholder="Los autores declaran..." />
            </div>
            <div className="manual-row">
              <label>Contribuciones (texto):</label>
              <textarea rows="3" value={manual.contributionsText || ''} onChange={event => handleManualFieldChange('contributionsText', event.target.value)} placeholder="Melisse Eich: Conceptualización..." />
            </div>
            <div className="manual-row">
              <label>Secciones (opcional):</label>
              <input type="text" value={manual.sectionsText || ''} onChange={event => handleManualFieldChange('sectionsText', event.target.value)} placeholder="Introducción | Método | Resultados" />
            </div>
            <div className="manual-row">
              <label>Conteos (fig/tab/ecu/pág):</label>
              <input type="number" min="0" value={manual.figCount ?? 0} onChange={event => handleManualFieldChange('figCount', event.target.value)} placeholder="Figuras" style={{ width: '80px' }} />
              <input type="number" min="0" value={manual.tableCount ?? 0} onChange={event => handleManualFieldChange('tableCount', event.target.value)} placeholder="Tablas" style={{ width: '80px', marginLeft: '8px' }} />
              <input type="number" min="0" value={manual.equationCount ?? 0} onChange={event => handleManualFieldChange('equationCount', event.target.value)} placeholder="Ecuaciones" style={{ width: '100px', marginLeft: '8px' }} />
              <input type="number" min="1" value={manual.pageCount ?? 1} onChange={event => handleManualFieldChange('pageCount', event.target.value)} placeholder="Páginas" style={{ width: '80px', marginLeft: '8px' }} />
            </div>
            <div className="manual-row">
              <label>Cantidad de refs:</label>
              <input type="number" min="0" value={manual.refCount || 0} onChange={event => handleManualFieldChange('refCount', event.target.value)} placeholder="25" style={{ width: '80px' }} />
            </div>
            <div className="manual-row">
              <label>Referencias (una por línea):</label>
              <textarea rows="6" value={manual.referencesText || ''} onChange={event => handleManualFieldChange('referencesText', event.target.value)} placeholder="Autor A. Título. Revista. Año; ..." />
            </div>
            <div className="manual-row">
              <button className="btn-action btn-accent" type="button" onClick={handleManualGenerate}>Generar XML desde metadatos</button>
              <button
                className="btn-action"
                type="button"
                onClick={handleDownload}
                disabled={!generatedXml}
                title={generatedXml ? 'Descargar el XML ya generado' : 'Primero generá el XML para poder descargarlo'}
              >
                Descargar XML
              </button>
            </div>
          </div>
        </section>

        {metaVisible ? (
          <section className="panel meta-panel" id="metaPanel">
            <h2 className="panel-title">
              <span className="panel-title-icon">🔍</span>
              Metadatos detectados
            </h2>
            <div className="meta-grid" id="metaGrid">
              {metaFields.map(field => (
                <div className="meta-card" key={field.k}>
                  <div className="meta-key">{field.k}</div>
                  <div className={`meta-val${field.empty ? ' meta-val--empty' : ''}`}>{field.display}</div>
                </div>
              ))}
            </div>
          </section>
        ) : null}

        {resultVisible ? (
          <section className="panel result-panel" id="resultPanel">
            <div className="result-header">
              <h2 className="panel-title">
                <span className="panel-title-icon">✅</span>
                XML JATS
              </h2>
              <div className="result-actions">
                <button className="btn-action" type="button" onClick={handleCopy}>
                  <span>{copyLabel}</span>
                </button>
                <button className="btn-action btn-accent" type="button" onClick={handleDownload}>
                  <span>Descargar .xml</span>
                </button>
              </div>
            </div>
            <div className="xml-wrap">
              <pre className="xml-output" id="xmlOutput">{generatedXml}</pre>
            </div>
          </section>
        ) : null}
      </main>

      <footer className="footer">
        <span>XML JATS</span>
        <span className="footer-sep">·</span>
        <span>Front-end + Back-end</span>
        <span className="footer-sep">·</span>
        <span>Integrado</span>
      </footer>
    </>
  );
}

function StepItem({ active, done, number, label }) {
  return (
    <div className={`step-item${active ? ' active' : ''}${done ? ' done' : ''}`}>
      <div className="step-circle">{number}</div>
      <span>{label}</span>
    </div>
  );
}

function buildManualFilename() {
  return 'ID-5939_Eichetal_XML-es';
}

function mergeMetadataForQuickEdit(baseMetadata, manualMeta) {
  if (!baseMetadata) {
    return manualMeta;
  }

  const funding = resolveFundingForQuickEdit(baseMetadata, manualMeta);

  return {
    ...baseMetadata,
    ...manualMeta,
    bodySections: manualMeta.bodySections || baseMetadata.bodySections || [],
    references: manualMeta.references || baseMetadata.references || [],
    funding,
    fundingStatement: manualMeta.fundingStatement || manualMeta.fundingText || baseMetadata.fundingStatement || '',
    affiliations_norm: baseMetadata.affiliations_norm || [],
    affiliations_orgdiv1: baseMetadata.affiliations_orgdiv1 || [],
    affiliations_orgdiv2: baseMetadata.affiliations_orgdiv2 || [],
    affiliations_orgname: baseMetadata.affiliations_orgname || [],
    affiliations_state: baseMetadata.affiliations_state || [],
    affiliations_country: baseMetadata.affiliations_country || [],
    affiliations_country_name: baseMetadata.affiliations_country_name || []
  };
}

function resolveFundingForQuickEdit(baseMetadata, manualMeta) {
  const baseFunding = Array.isArray(baseMetadata?.funding)
    ? baseMetadata.funding.map(normalizeFundingItem).filter(Boolean)
    : [];
  const manualText = String(manualMeta?.fundingStatement || manualMeta?.fundingText || '').trim();
  const baseStatement = String(baseMetadata?.fundingStatement || '').trim();
  const baseFormatted = baseFunding.map(formatFundingItem).filter(Boolean).join('; ');

  if (baseFunding.length && (!manualText || manualText === baseStatement || manualText === baseFormatted)) {
    return baseFunding;
  }

  if (!manualText) {
    return [];
  }

  return manualText.split(';').map(normalizeFundingItem).filter(Boolean);
}

function normalizeFundingItem(item) {
  if (!item) {
    return null;
  }

  if (typeof item === 'object') {
    const source = String(item.source || '').trim();
    const awardId = String(item.awardId || '').trim();

    return awardId ? { source, awardId } : source;
  }

  const text = String(item).trim();
  const awardMatch = text.match(/(?:No\.?|N[º°]|c[oó]digo de financiamiento No\.?)\s*([A-Za-z0-9./-]+)/i)
    || text.match(/\b([0-9]{2,4}\/[0-9]{4})\b/);
  const awardId = awardMatch?.[1] || '';
  const source = awardId
    ? text.replace(awardMatch[0], '').replace(/[,\s.]+$/u, '').trim()
    : text;

  return awardId ? { source, awardId } : source;
}

function normalizeMetaForServer(meta) {
  const affiliations = Array.isArray(meta.affiliations) ? meta.affiliations : [];
  const affiliationEmails = affiliations.map((affiliation, index) => {
    if (typeof affiliation === 'string') {
      return meta.affiliations_email?.[index] || '';
    }

    return String(affiliation?.email || '').trim();
  });
  const affiliationTexts = affiliations
    .map(affiliation => (
      typeof affiliation === 'string'
        ? affiliation
        : String(affiliation?.original || '').trim()
    ))
    .filter(Boolean);

  return {
    ...meta,
    affiliations: affiliationTexts,
    affiliations_email: affiliationEmails,
    fundingStatement: String(meta.fundingStatement || meta.fundingText || '').trim(),
    'elocation-id': String(meta['elocation-id'] || meta.elocationId || '').trim(),
    refCount: String(meta.refCount || (Array.isArray(meta.references) ? meta.references.length : 0))
  };
}

function collectManualMeta(manual) {
  // Acepta multiples separadores para pegar listas desde Word/Excel sin formateo previo.
  const parseList = value => String(value || '')
    .split(/[;,|]/)
    .map(entry => entry.trim())
    .filter(Boolean);

  // Solo conserva autores con nombre; ORCID puede quedar vacio.
  const authors = (manual.authors || [])
    .map(author => ({
      name: String(author.name || '').trim(),
      orcid: normalizeOrcid(author.orcid)
    }))
    .filter(author => author.name);

  // Recorta afiliaciones vacias para no generar nodos <aff> sin contenido.
  const affiliations = (manual.affiliations || [])
    .map((affiliation, index) => ({
      id: `aff${index + 1}`,
      original: String(affiliation?.original || '').trim(),
      email: String(affiliation?.email || '').trim()
    }))
    .filter(affiliation => affiliation.original || affiliation.email);
  const contributions = String(manual.contributionsText || '').trim();
  const funding = String(manual.fundingText || '').trim();
  const pubDay = String(manual.pubDay || '').trim();
  const pubMonth = String(manual.pubMonth || '').trim();
  const pubYear = String(manual.pubYear || '').trim();
  const pubdate = [pubDay, pubMonth, pubYear].filter(Boolean).join(' ');
  const kwdsEs = (manual.kwdsEsText || '').trim() ? parseList(manual.kwdsEsText) : (manual.kwdsEs || []);
  const kwdsEn = (manual.kwdsEnText || '').trim() ? parseList(manual.kwdsEnText) : (manual.kwdsEn || []);
  const sections = (manual.sectionsText || '').trim() ? parseList(manual.sectionsText) : (manual.sections || []);
  const references = (manual.referencesText || '').trim()
    ? String(manual.referencesText).split(/\r?\n/).map(r => r.trim()).filter(Boolean)
    : (manual.references || []);

  return {
    ...manual,
    issn_ppub: String(manual.issn_ppub || '').trim(),
    issn_epub: String(manual.issn_epub || '').trim(),
    sps: String(manual.sps || '1.9').trim() || '1.9',
    doi: String(manual.doi || '').trim(),
    licenseHref: String(manual.licenseHref || 'https://creativecommons.org/licenses/by/4.0/').trim() || 'https://creativecommons.org/licenses/by/4.0/',
    licenseText: String(manual.licenseText || 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons').trim() || 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons',
    pubdate,
    volume: String(manual.volume || '').trim(),
    'elocation-id': String(manual.elocationId || '').trim(),
    funding: funding ? funding.split(';').map(entry => entry.trim()).filter(Boolean) : (manual.funding || []),
    fundingStatement: funding,
    conflict: String(manual.conflict || '').trim(),
    contributions: contributions ? [contributions] : [],
    authors,
    affiliations,
    articleTitle: manual.articleTitle || document.title || 'Artículo desde metadatos manuales',
    articleTitleEn: manual.articleTitleEn || '',
    abstractEs: String(manual.abstractEs || '').replace(/\u2013/g, '-'),
    abstractEn: String(manual.abstractEn || '').replace(/\u2013/g, '-'),
    kwdsEs,
    kwdsEn,
    sections,
    publisherId: String(manual.publisherId || '').trim(),
    articleCategory: String(manual.articleCategory || 'research-article').trim() || 'research-article',
    articleCategoryLabel: String(manual.articleCategoryLabel || 'Artículo de investigación').trim() || 'Artículo de investigación',
    received: String(manual.received || '').trim(),
    revised: String(manual.revised || '').trim(),
    accepted: String(manual.accepted || '').trim(),
    refCount: Number(manual.refCount || 0) || 0,
    references,
    figCount: Number(manual.figCount || 0) || 0,
    tableCount: Number(manual.tableCount || 0) || 0,
    equationCount: Number(manual.equationCount || 0) || 0,
    pageCount: Number(manual.pageCount || 1) || 1
  };
}

function buildJatsFromMeta(meta) {
  const e = value => esc(String(value || ''));
  const doi = meta.doi || '';

  const pubId = String(meta.publisherId || '').trim() || (doi ? doi.split('/').slice(1).join('/') : 'XXXX');
  const year = meta.accepted?.match(/\d{4}/)?.[0] || meta.pubdate?.match(/\d{4}/)?.[0] || new Date().getFullYear();

  let journalMeta = '\t<journal-meta>\r\n';
  journalMeta += `\t\t<journal-id journal-id-type="nlm-ta">${e(meta.journalAbbrev || meta.journalTitle || '')}</journal-id>\r\n`;
  journalMeta += '\t\t<journal-title-group>\r\n';
  journalMeta += `\t\t\t<journal-title>${e(meta.journalTitle || '')}</journal-title>\r\n`;
  journalMeta += `\t\t\t<abbrev-journal-title abbrev-type="publisher">${e(meta.journalAbbrev || '')}</abbrev-journal-title>\r\n`;
  journalMeta += '\t\t</journal-title-group>\r\n';
  if (meta.issn_ppub) {
    journalMeta += `\t\t<issn pub-type="ppub">${e(meta.issn_ppub)}</issn>\r\n`;
  }
  if (meta.issn_epub) {
    journalMeta += `\t\t<issn pub-type="epub">${e(meta.issn_epub)}</issn>\r\n`;
  }
  journalMeta += '\t\t<publisher>\r\n';
  journalMeta += `\t\t\t<publisher-name>${e(meta.publisher || '')}</publisher-name>\r\n`;
  journalMeta += '\t\t</publisher>\r\n';
  journalMeta += '\t</journal-meta>';

  let articleMeta = '\t<article-meta>\r\n';
  articleMeta += `\t\t<article-id pub-id-type="publisher-id">${e(pubId)}</article-id>\r\n`;
  if (doi) {
    articleMeta += `\t\t<article-id pub-id-type="doi">${e(doi)}</article-id>\r\n`;
  }
  articleMeta += '\t\t<article-categories>\r\n';
  articleMeta += '\t\t\t<subj-group subj-group-type="heading">\r\n';
  articleMeta += `\t\t\t\t<subject>${e(meta.articleCategoryLabel || 'Artículo de investigación')}</subject>\r\n`;
  articleMeta += '\t\t\t</subj-group>\r\n';
  articleMeta += '\t\t</article-categories>\r\n';

  articleMeta += '\t\t<title-group>\r\n';
  articleMeta += `\t\t\t<article-title xml:lang="es">${e(meta.articleTitle || '')}</article-title>\r\n`;
  if (meta.articleTitleEn) {
    articleMeta += '\t\t\t<trans-title-group xml:lang="en">\r\n';
    articleMeta += `\t\t\t\t<trans-title>${e(meta.articleTitleEn)}</trans-title>\r\n`;
    articleMeta += '\t\t\t</trans-title-group>\r\n';
  }
  articleMeta += '\t\t</title-group>\r\n';

  articleMeta += '\t\t<contrib-group>\r\n';
  // Si hay mas autores que afiliaciones, reutiliza la ultima afiliacion valida para evitar rid rotos.
  const availableAffCount = Math.max((meta.affiliations || []).length, 1);
  (meta.authors || []).forEach((author, index) => {
    const position = index + 1;
    const ridAff = Math.min(position, availableAffCount);
    const parts = String(author.name || '').split(/\s+/).filter(Boolean);
    const surname = parts.length ? parts.pop() : '';
    const given = parts.join(' ');

    articleMeta += '\t\t\t<contrib contrib-type="author">\r\n';
    articleMeta += '\t\t\t\t<name>\r\n';
    articleMeta += `\t\t\t\t\t<surname>${e(surname)}</surname>\r\n`;
    articleMeta += `\t\t\t\t\t<given-names>${e(given)}</given-names>\r\n`;
    articleMeta += '\t\t\t\t</name>\r\n';
    if (author.orcid) {
      articleMeta += `\t\t\t\t<contrib-id contrib-id-type="orcid">https://orcid.org/${e(author.orcid)}</contrib-id>\r\n`;
    }
    articleMeta += `\t\t\t\t<xref ref-type="aff" rid="aff${ridAff}"><sup>${ridAff}</sup></xref>\r\n`;
    articleMeta += '\t\t\t</contrib>\r\n';
  });
  articleMeta += '\t\t</contrib-group>\r\n';

  (meta.affiliations || []).forEach((affiliation, index) => {
    const position = index + 1;
    const affOriginal = typeof affiliation === 'string' ? affiliation : (affiliation.original || '');
    const affEmail = typeof affiliation === 'string' ? '' : (affiliation.email || '');

    articleMeta += `\t\t\t<aff id="aff${position}">\r\n`;
    articleMeta += `\t\t\t\t<label>${position}</label>\r\n`;
    articleMeta += `\t\t\t\t<institution content-type="original">${e(affOriginal)}</institution>\r\n`;
    if (affEmail) {
      articleMeta += `\t\t\t\t<email>${e(affEmail)}</email>\r\n`;
    }
    articleMeta += '\t\t\t</aff>\r\n';
  });

  articleMeta += '\t\t<author-notes>\r\n';
  if (meta.conflict) {
    articleMeta += '\t\t\t<fn fn-type="conflict" id="fn2">\r\n';
    articleMeta += '\t\t\t\t<label>Conflicto de Intereses</label>\r\n';
    articleMeta += `\t\t\t\t<p>${e(meta.conflict)}</p>\r\n`;
    articleMeta += '\t\t\t</fn>\r\n';
  }
  if (meta.contributions && meta.contributions.length) {
    articleMeta += '\t\t\t<fn fn-type="equal" id="fn3">\r\n';
    articleMeta += '\t\t\t\t<label>Contribución autoral</label>\r\n';
    articleMeta += `\t\t\t\t<p>${e(meta.contributions.join(' '))}</p>\r\n`;
    articleMeta += '\t\t\t</fn>\r\n';
  }
  articleMeta += '\t\t</author-notes>\r\n';

  if (meta.pubdate || meta.volume || meta['elocation-id']) {
    articleMeta += '\t\t<pub-date date-type="pub" publication-format="electronic">\r\n';
    if (meta.pubdate) {
      const parts = String(meta.pubdate).split(/\s+/);
      if (parts[0]) articleMeta += `\t\t\t<day>${e(parts[0])}</day>\r\n`;
      if (parts[1]) articleMeta += `\t\t\t<month>${e(parts[1])}</month>\r\n`;
      if (parts[2]) articleMeta += `\t\t\t<year>${e(parts[2])}</year>\r\n`;
    } else {
      articleMeta += `\t\t\t<year>${e(year)}</year>\r\n`;
    }
    articleMeta += '\t\t</pub-date>\r\n';
  }
  if (meta.volume) articleMeta += `\t\t<volume>${e(meta.volume)}</volume>\r\n`;
  if (meta['elocation-id']) articleMeta += `\t\t<elocation-id>${e(meta['elocation-id'])}</elocation-id>\r\n`;

  if (meta.received || meta.revised || meta.accepted) {
    const addHistoryDate = (type, value) => {
      if (!value) {
        return '';
      }

      const parts = String(value).trim().split(/\s+/);
      const day = parts[0] || '';
      const month = parts[1] || '';
      const yearPart = parts[2] || String(value).match(/\d{4}/)?.[0] || '';
      let dateXml = `\t\t\t<date date-type="${type}">\r\n`;
      if (day) dateXml += `\t\t\t\t<day>${e(day)}</day>\r\n`;
      if (month) dateXml += `\t\t\t\t<month>${e(month)}</month>\r\n`;
      if (yearPart) dateXml += `\t\t\t\t<year>${e(yearPart)}</year>\r\n`;
      dateXml += '\t\t\t</date>\r\n';
      return dateXml;
    };

    articleMeta += '\t\t<history>\r\n';
    articleMeta += addHistoryDate('received', meta.received);
    articleMeta += addHistoryDate('rev-recd', meta.revised);
    articleMeta += addHistoryDate('accepted', meta.accepted);
    articleMeta += '\t\t</history>\r\n';
  }

  articleMeta += '\t\t<permissions>\r\n';
  articleMeta += `\t\t\t<license license-type="open-access" xlink:href="${e(meta.licenseHref || 'https://creativecommons.org/licenses/by/4.0/')}" xml:lang="es">\r\n`;
  articleMeta += `\t\t\t\t<license-p>${e(meta.licenseText || 'Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons')}</license-p>\r\n`;
  articleMeta += '\t\t\t</license>\r\n';
  articleMeta += '\t\t</permissions>\r\n';

  if (meta.abstractEs) {
    articleMeta += '\t\t<abstract>\r\n';
    articleMeta += '\t\t\t<title>Resumen</title>\r\n';
    articleMeta += `\t\t\t<p>${escapeXmlWithItalic(meta.abstractEs)}</p>\r\n`;
    articleMeta += '\t\t</abstract>\r\n';
  }
  if (meta.abstractEn) {
    articleMeta += '\t\t<trans-abstract xml:lang="en">\r\n';
    articleMeta += '\t\t\t<title>Abstract</title>\r\n';
    articleMeta += `\t\t\t<p>${e(meta.abstractEn)}</p>\r\n`;
    articleMeta += '\t\t</trans-abstract>\r\n';
  }

  if (meta.kwdsEs && meta.kwdsEs.length) {
    articleMeta += '\t\t<kwd-group xml:lang="es">\r\n';
    articleMeta += '\t\t\t<title>Palabras claves:</title>\r\n';
    meta.kwdsEs.forEach(keyword => {
      articleMeta += `\t\t\t\t<kwd>${e(keyword)}</kwd>\r\n`;
    });
    articleMeta += '\t\t</kwd-group>\r\n';
  }
  if (meta.kwdsEn && meta.kwdsEn.length) {
    articleMeta += '\t\t<kwd-group xml:lang="en">\r\n';
    articleMeta += '\t\t\t<title>Keywords:</title>\r\n';
    meta.kwdsEn.forEach(keyword => {
      articleMeta += `\t\t\t\t<kwd>${e(keyword)}</kwd>\r\n`;
    });
    articleMeta += '\t\t</kwd-group>\r\n';
  }

  if (meta.funding && meta.funding.length) {
    articleMeta += '\t\t<funding-group>\r\n';
    meta.funding.forEach(fundingSource => {
      articleMeta += '\t\t\t<award-group award-type="contract">\r\n';
      articleMeta += '\t\t\t\t<funding-source>\r\n';
      articleMeta += `\t\t\t\t\t${e(fundingSource)}\r\n`;
      articleMeta += '\t\t\t\t</funding-source>\r\n';
      articleMeta += '\t\t\t</award-group>\r\n';
    });
    articleMeta += `\t\t<funding-statement>${e((meta.funding || []).join('; '))}</funding-statement>\r\n`;
    articleMeta += '\t\t</funding-group>\r\n';
  }

  const refCount = meta.refCount || 0;
  articleMeta += '\t\t<counts>\r\n';
  articleMeta += `\t\t\t<fig-count count="${e(meta.figCount ?? 0)}"/>\r\n`;
  articleMeta += `\t\t\t<table-count count="${e(meta.tableCount ?? 0)}"/>\r\n`;
  articleMeta += `\t\t\t<equation-count count="${e(meta.equationCount ?? 0)}"/>\r\n`;
  articleMeta += `\t\t\t<ref-count count="${e(refCount)}"/>\r\n`;
  articleMeta += `\t\t\t<page-count count="${e(meta.pageCount ?? 1)}"/>\r\n`;
  articleMeta += '\t\t</counts>\r\n';
  articleMeta += '\t</article-meta>\r\n';

  const body = buildBodyXml(meta);
  const back = buildBackXml(meta);

  return `<?xml version="1.0" encoding="utf-8"?>\r\n<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20151215//EN"\r\n  "https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1.dtd">\r\n<article article-type="research-article" dtd-version="1.1" specific-use="sps-1.9" xml:lang="${e(meta.lang || 'es')}" xmlns:mml="http://www.w3.org/1998/Math/MathML" xmlns:xlink="http://www.w3.org/1999/xlink">\r\n\r\n\t<front>\r\n${journalMeta}\r\n\r\n${articleMeta}\t</front>\r\n\r\n${body}${back}</article>`;
}

function buildBackXml(meta) {
  const e = value => esc(String(value || ''));
  let back = '\t<back>\r\n';
  back += '\t\t<ref-list>\r\n';
  back += '\t\t\t<title>Referencias bibliográficas</title>\r\n';

  const references = meta.references || [];
  if (references.length > 0) {
    references.forEach((refText, i) => {
      const num = i + 1;

      const cleanRef = esc(String(refText || '').trim().replace(/<[^>]*>/g, '')); // Remove HTML tags
      let year = '';
      const yearMatch = cleanRef.match(/\b(19|20)\d{2}\b/);
      if (yearMatch) {
        year = yearMatch[0];
      }

      // Determine publication type (heuristic)
      let pubType = 'journal'; // Default
      if (/(editora|ed\.|São Paulo|Madrid|Firenze|book|livro)/iu.test(cleanRef)) {
        pubType = 'book';
      }

      const parts = cleanRef.split('.').map(part => part.trim()).filter(Boolean);

      const authorPart = parts[0] || '';
      const titlePart = parts[1] || '';

      back += `\t\t\t<ref id="B${num}">\r\n`;
      back += `\t\t\t\t<label>${num}</label>\r\n`;
      back += `\t\t\t\t<mixed-citation>${escapeXmlWithItalic(refText)}</mixed-citation>\r\n`;
      back += `\t\t\t\t<element-citation publication-type="${pubType}">\r\n`;

      // Author parsing
      if (authorPart) {
        back += '\t\t\t\t\t<person-group person-group-type="author">\r\n';
        // Split by comma or 'and'/'y' for multiple authors
        const individualAuthors = authorPart.split(/,\s*(?=[A-Za-z])|\s+and\s+|\s+y\s+/).filter(Boolean);
        individualAuthors.forEach(authorName => {
          authorName = authorName.trim();
          if (!authorName) return;

          const nameParts = authorName.split(' ');
          const surname = nameParts.shift(); // First word as surname
          const given = nameParts.join(' '); // Rest as given names

          back += '\t\t\t\t\t\t<name>\r\n';
          back += `\t\t\t\t\t\t\t<surname>${e(surname)}</surname>\r\n`;
          if (given) {
            back += `\t\t\t\t\t\t\t<given-names>${e(given)}</given-names>\r\n`;
          }
          back += '\t\t\t\t\t\t</name>\r\n';
        });
        back += '\t\t\t\t\t</person-group>\r\n';
      }

      // Title/Source
      if (titlePart) {
        const tag = (pubType === 'journal') ? 'article-title' : 'source';
        back += `\t\t\t\t\t<${tag}>${e(titlePart)}</${tag}>\r\n`;
      }

      // Nombre de la revista (si es revista) o información del editor (si es libro)
      if (pubType === 'journal' && parts[2]) { // parts[2] podría ser el nombre de la revista
          back += `\t\t\t\t\t<source>${e(parts[2])}</source>\r\n`;
      } else if (pubType === 'book') {
          // Try to find publisher info in parts[1], parts[2] or later
          for (let k = 1; k < parts.length; k++) {
              const part = parts[k];
              const pm = part.match(/(?<loc>[^:]+):\s*(?<name>[^;]+);/u);
              if (pm && pm.groups) {
                  back += `\t\t\t\t\t<publisher-loc>${e(pm.groups.loc.trim())}</publisher-loc>\r\n`;
                  back += `\t\t\t\t\t<publisher-name>${e(pm.groups.name.trim())}</publisher-name>\r\n`;
                  break; // Found it, stop searching
              }
          }
      }

      // Year
      if (year) {
        back += `\t\t\t\t\t<year>${year}</year>\r\n`;
      }

      back += '\t\t\t\t</element-citation>\r\n';
      back += '\t\t\t</ref>\r\n';
    });
  }
  back += '\t\t</ref-list>\r\n';

  const fundingItems = Array.isArray(meta?.funding)
    ? meta.funding.map(item => String(item || '').trim()).filter(Boolean)
    : [];
  const fundingStatement = String(meta?.fundingStatement || '').trim();

  if (fundingItems.length || fundingStatement) {
    back += '\t\t<fn-group>\r\n';
    back += '\t\t\t<fn fn-type="financial-disclosure" id="fn1">\r\n';
    back += '\t\t\t\t<label>Financiamiento</label>\r\n';
    back += `\t\t\t\t<p> ${esc(fundingStatement || fundingItems.join('; '))}</p>\r\n`;
    back += '\t\t\t</fn>\r\n';
    back += '\t\t</fn-group>\r\n';
  }

  back += '\t</back>\r\n';
  return back;
}

function inferBodySecType(title) {
  const normalized = String(title || '').trim().toLowerCase();

  if (/introducci[oó]n|introduction/.test(normalized)) {
    return 'intro';
  }

  if (/metodolog[ií]a|m[eé]todos|methods/.test(normalized)) {
    return 'methods';
  }

  if (/resultado|results?|discusi[oó]n|discussion/.test(normalized)) {
    return 'results|discussion';
  }

  if (/conclus/.test(normalized)) {
    return 'conclusions';
  }

  return normalized.replace(/[^a-z0-9]+/g, '-') || 'sec';
}

function normalizeBodySections(sections) {
  if (!Array.isArray(sections) || !sections.length) {
    return [];
  }

  return sections
    .map(section => {
      if (typeof section === 'string') {
        const title = section.trim();
        if (!title) {
          return null;
        }

        return {
          title,
          secType: inferBodySecType(title),
          paragraphs: [],
          subsections: []
        };
      }

      const title = String(section?.title || section?.heading || '').trim();
      const paragraphs = Array.isArray(section?.paragraphs)
        ? section.paragraphs.map(paragraph => String(paragraph || '').trim()).filter(Boolean)
        : [];
      const subsections = normalizeBodySections(section?.subsections || section?.children || []);

      if (!title && !paragraphs.length && !subsections.length) {
        return null;
      }

      return {
        title,
        secType: String(section?.secType || inferBodySecType(title)).trim() || inferBodySecType(title),
        paragraphs,
        subsections
      };
    })
    .filter(Boolean);
}

function renderBodySection(section, depth = 1) {
  const indent = '\t'.repeat(depth);
  const title = String(section?.title || '').trim();
  const secType = String(section?.secType || inferBodySecType(title)).trim() || inferBodySecType(title);
  const paragraphs = Array.isArray(section?.paragraphs) ? section.paragraphs : [];
  const subsections = Array.isArray(section?.subsections) ? section.subsections : [];

  let xml = `${indent}<sec sec-type="${esc(secType)}">\r\n`;
  xml += `${indent}\t<title>${esc(title)}</title>\r\n`;

  paragraphs.forEach(paragraph => {
    xml += `${indent}\t<p>${escapeXmlWithItalic(paragraph)}</p>\r\n`;
  });

  subsections.forEach(subsection => {
    xml += renderBodySection(subsection, depth + 1);
  });

  xml += `${indent}</sec>\r\n`;
  return xml;
}

function buildBodyXml(meta) {
  const sections = normalizeBodySections(meta?.bodySections || meta?.sections || []);

  if (!sections.length) {
    return '\t<body>\r\n\t</body>\r\n';
  }

  let xml = '\t<body>\r\n';
  sections.forEach(section => {
    xml += renderBodySection(section, 2);
  });
  xml += '\t</body>\r\n';
  return xml;
}

function escapeXmlWithItalic(text) {
  // Preserva marcas <italic> permitidas y escapa el resto para mantener XML bien formado.
  const value = String(text || '');
  let result = '';
  let offset = 0;

  while (true) {
    const match = value.slice(offset).match(/<italic>(.*?)<\/italic>/s);
    if (!match) {
      break;
    }

    const matchIndex = value.indexOf(match[0], offset);
    result += esc(value.slice(offset, matchIndex));
    result += `<italic>${esc(match[1])}</italic>`;
    offset = matchIndex + match[0].length;
  }

  result += esc(value.slice(offset));
  return result;
}

function esc(value) {
  // Escape minimo de caracteres reservados en XML.
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function delay(ms) {
  return new Promise(resolve => window.setTimeout(resolve, ms));
}

export default App;
