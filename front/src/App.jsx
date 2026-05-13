import React, { useEffect, useMemo, useState } from 'react';
import '../css/style.css';

const initialManual = {
  affiliations: [{ id: 'aff1', original: 'Salud Colectiva', email: '' }],
  funding: [],
  authors: [
    { name: 'Melisse Eich', orcid: '0000-0001-8382-1354' },
    { name: 'Marta Verdi', orcid: '0000-0001-7090-9541' },
    { name: 'Pedro Paulo Scremin Martins', orcid: '0000-0003-2641-8563' },
    { name: 'Mirelle Finkler', orcid: '0000-0001-5764-9183' }
  ],
  refCount: 0,
  journalTitle: 'XML JATS',
  journalAbbrev: 'XML JATS',
  publisher: 'SciELO',
  lang: 'es',
  articleTitle: 'XML JATS',
  articleTitleEn: '',
  abstractEs: '',
  abstractEn: '',
  kwdsEs: [],
  kwdsEn: [],
  received: '',
  revised: '',
  accepted: ''
};

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
  const [manual, setManual] = useState(initialManual);
  const [pdfLoading, setPdfLoading] = useState(false);

  const showAlert = (message, type) => {
    setAlert({ type, message, visible: true });
  };

  const metaFields = useMemo(() => {
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
      { k: 'Recibido', v: metadata.received },
      { k: 'Versión final', v: metadata.revised },
      { k: 'Aprobado', v: metadata.accepted },
      { k: 'Keywords (es)', v: metadata.kwdsEs?.join(', ') },
      { k: 'Keywords (en)', v: metadata.kwdsEn?.join(', ') },
      { k: 'Financiamiento', v: metadata.funding?.join('; ') },
      { k: 'Secciones', v: metadata.sections?.join(' · ') }
    ];

    return fields.map(field => ({
      ...field,
      display: field.v || 'No detectado',
      empty: !field.v
    }));
  }, [metadata]);

  useEffect(() => {
    if (progress.pct < 100) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      setProgress(current => ({ ...current, visible: false }));
    }, 700);

    return () => window.clearTimeout(timer);
  }, [progress.pct]);

  useEffect(() => {
    if (generatedMode !== 'manual' || !resultVisible) {
      return;
    }

    const meta = collectManualMeta(manual);
    setGeneratedXml(buildJatsFromMeta(meta));
    setGeneratedFilename(buildManualFilename(meta.articleTitle));
    setMetadata(meta);
    setMetaVisible(true);
  }, [generatedMode, manual, resultVisible]);

  const resetPanels = () => {
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

    setCurrentFile(file);
    resetPanels();
    setAlert({ type: 'info', message: '', visible: false });
    setStep(1);
  };

  const handleConvert = async () => {
    if (!currentFile) {
      return;
    }

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

      const response = await fetch('/backend/convert.php', {
        method: 'POST',
        body: form
      });

      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.error || 'No se pudo convertir el archivo');
      }

      setProgress({ visible: true, pct: 70, label: 'Generando XML...' });
      setGeneratedXml(data.xml || '');
      setGeneratedFilename(data.filename || 'documento');
      setMetadata(data.metadata || null);
      setMetaVisible(Boolean(data.metadata));
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

  const handleManualGenerate = async () => {
    const meta = collectManualMeta(manual);

    setGeneratedMode('manual');
    setStep(2);
    setProgress({ visible: true, pct: 30, label: 'Generando XML en el navegador...' });
    await delay(150);

    try {
      const xml = buildJatsFromMeta(meta);
      setGeneratedXml(xml);
      setGeneratedFilename(buildManualFilename(meta.articleTitle));
      setMetadata(meta);
      setMetaVisible(true);
      setResultVisible(true);
      setStep(4);
      showAlert('XML generado localmente en el front-end.', 'success');
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

  const handleGeneratePdf = async () => {
    if (!generatedXml) {
      return;
    }

    setPdfLoading(true);
    setAlert({ type: 'info', message: '', visible: false });

    try {
      const response = await fetch('/backend/generate_xml_pdf.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          xml: generatedXml,
          filename: generatedFilename || 'documento'
        })
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.error || 'No se pudo generar el PDF');
      }

      let dataUrl;
      let filename;

      if (result.pdf) {
        dataUrl = `data:application/pdf;base64,${result.pdf}`;
        filename = result.filename;
      } else if (result.html) {
        dataUrl = `data:text/html;base64,${result.html}`;
        filename = result.filename;
      } else {
        throw new Error('Respuesta inválida del servidor');
      }

      const link = document.createElement('a');
      link.href = dataUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      showAlert('PDF generado correctamente', 'success');
    } catch (error) {
      showAlert(`Error al generar PDF: ${error.message}`, 'error');
    } finally {
      setPdfLoading(false);
    }
  };

  const handleManualFieldChange = (key, value) => {
    setManual(current => ({ ...current, [key]: value }));
  };

  const handleManualAuthorChange = (index, key, value) => {
    setManual(current => ({
      ...current,
      authors: current.authors.map((author, authorIndex) => (
        authorIndex === index ? { ...author, [key]: value } : author
      ))
    }));
  };

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
            Metadatos manuales (edición rápida)
          </h2>
          <div className="manual-grid">
            <div className="manual-row">
              <label>Afiliación principal:</label>
              <input
                type="text"
                value={manual.affiliations[0]?.original || ''}
                onChange={event => handleManualFieldChange('affiliations', [{ ...manual.affiliations[0], original: event.target.value }])}
              />
            </div>
            <div className="manual-row">
              <label>Autores (predefinidos):</label>
              <ul className="manual-authors">
                {manual.authors.map((author, index) => (
                  <li key={`${author.name}-${index}`}>
                    <span className="author-index">{index + 1}.</span>
                    <input
                      type="text"
                      className="author-name"
                      value={author.name}
                      onChange={event => handleManualAuthorChange(index, 'name', event.target.value)}
                      aria-label={`Nombre del autor ${index + 1}`}
                    />
                    <input
                      type="text"
                      className="author-orcid"
                      value={author.orcid}
                      onChange={event => handleManualAuthorChange(index, 'orcid', event.target.value)}
                      aria-label={`ORCID del autor ${index + 1}`}
                      placeholder="0000-0000-0000-0000"
                    />
                  </li>
                ))}
              </ul>
            </div>
            <div className="manual-row">
              <label>DOI:</label>
              <input type="text" value={manual.doi || ''} onChange={event => handleManualFieldChange('doi', event.target.value)} placeholder="10.18294/sc.2026.5939" />
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
              <label>Conflicto de intereses:</label>
              <input type="text" value={manual.conflict || ''} onChange={event => handleManualFieldChange('conflict', event.target.value)} placeholder="Los autores declaran..." />
            </div>
            <div className="manual-row">
              <label>Contribuciones (texto):</label>
              <textarea rows="3" value={manual.contributionsText || ''} onChange={event => handleManualFieldChange('contributionsText', event.target.value)} placeholder="Melisse Eich: Conceptualización..." />
            </div>
            <div className="manual-row">
              <button className="btn-action btn-accent" type="button" onClick={handleManualGenerate}>Generar XML desde metadatos</button>
            </div>
            <div className="manual-row">
              <small>Estos metadatos son sólo para completar el front-end; la extracción automática sigue disponible.</small>
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
                <button className="btn-action" type="button" onClick={handleGeneratePdf} disabled={pdfLoading}>
                  <span>{pdfLoading ? 'Generando PDF...' : 'Generar PDF'}</span>
                </button>
              </div>
            </div>
            <div className="xml-wrap">
              <pre className="xml-output" id="xmlOutput">{generatedXml}</pre>
            </div>
            <p className="xml-note">El cuerpo del artículo y las referencias bibliográficas deben completarse manualmente en el XML generado.</p>
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

function buildManualFilename(title) {
  return `${String(title || 'manual').replace(/[^a-z0-9]+/gi, '_').substring(0, 80)}_JATS_SPS19`;
}

function collectManualMeta(manual) {
  const authors = (manual.authors || [])
    .map(author => ({
      name: String(author.name || '').trim(),
      orcid: String(author.orcid || '').trim().replace(/^https?:\/\/orcid\.org\//i, '')
    }))
    .filter(author => author.name);

  const aff = String(manual.affiliations?.[0]?.original || '').trim();
  const contributions = String(manual.contributionsText || '').trim();
  const funding = String(manual.fundingText || '').trim();
  const pubDay = String(manual.pubDay || '').trim();
  const pubMonth = String(manual.pubMonth || '').trim();
  const pubYear = String(manual.pubYear || '').trim();
  const pubdate = [pubDay, pubMonth, pubYear].filter(Boolean).join(' ');

  return {
    ...manual,
    doi: String(manual.doi || '').trim(),
    pubdate,
    volume: String(manual.volume || '').trim(),
    'elocation-id': String(manual.elocationId || '').trim(),
    funding: funding ? funding.split(';').map(entry => entry.trim()).filter(Boolean) : (manual.funding || []),
    conflict: String(manual.conflict || '').trim(),
    contributions: contributions ? [contributions] : [],
    authors,
    affiliations: aff ? [{ id: 'aff1', original: aff, email: '' }] : [],
    articleTitle: manual.articleTitle || document.title || 'Artículo desde metadatos manuales',
    articleTitleEn: manual.articleTitleEn || '',
    abstractEs: String(manual.abstractEs || '').replace(/\u2013/g, '-'),
    abstractEn: String(manual.abstractEn || '').replace(/\u2013/g, '-'),
    refCount: Number(manual.refCount || 0) || 0
  };
}

function buildJatsFromMeta(meta) {
  const e = value => esc(String(value || ''));
  const doi = meta.doi || '';
  const pubId = doi ? doi.split('/').slice(1).join('/') : 'XXXX';
  const year = meta.accepted?.match(/\d{4}/)?.[0] || meta.pubdate?.match(/\d{4}/)?.[0] || new Date().getFullYear();

  let journalMeta = '  <journal-meta>\n';
  journalMeta += `      <journal-id journal-id-type="nlm-ta">${e(meta.journalAbbrev || meta.journalTitle || '')}</journal-id>\n`;
  journalMeta += '      <journal-title-group>\n';
  journalMeta += `        <journal-title>${e(meta.journalTitle || '')}</journal-title>\n`;
  journalMeta += `        <abbrev-journal-title abbrev-type="publisher">${e(meta.journalAbbrev || '')}</abbrev-journal-title>\n`;
  journalMeta += '      </journal-title-group>\n';
  if (meta.issn_ppub) {
    journalMeta += `      <issn pub-type="ppub">${e(meta.issn_ppub)}</issn>\n`;
  }
  if (meta.issn_epub) {
    journalMeta += `      <issn pub-type="epub">${e(meta.issn_epub)}</issn>\n`;
  }
  journalMeta += '      <publisher>\n';
  journalMeta += `        <publisher-name>${e(meta.publisher || '')}</publisher-name>\n`;
  journalMeta += '      </publisher>\n';
  journalMeta += '    </journal-meta>';

  let articleMeta = '    <article-meta>\n';
  articleMeta += `      <article-id pub-id-type="publisher-id">${e(pubId)}</article-id>\n`;
  if (doi) {
    articleMeta += `      <article-id pub-id-type="doi">${e(doi)}</article-id>\n`;
  }
  articleMeta += '      <article-categories>\n';
  articleMeta += '        <subj-group subj-group-type="heading">\n';
  articleMeta += '          <subject>Artículo</subject>\n';
  articleMeta += '        </subj-group>\n';
  articleMeta += '      </article-categories>\n';

  articleMeta += '      <title-group>\n';
  articleMeta += `        <article-title xml:lang="es">${e(meta.articleTitle || '')}</article-title>\n`;
  if (meta.articleTitleEn) {
    articleMeta += '        <trans-title-group xml:lang="en">\n';
    articleMeta += `          <trans-title>${e(meta.articleTitleEn)}</trans-title>\n`;
    articleMeta += '        </trans-title-group>\n';
  }
  articleMeta += '      </title-group>\n';

  articleMeta += '      <contrib-group>\n';
  (meta.authors || []).forEach((author, index) => {
    const position = index + 1;
    const parts = String(author.name || '').split(/\s+/).filter(Boolean);
    const surname = parts.length ? parts.pop() : '';
    const given = parts.join(' ');

    articleMeta += '      <contrib contrib-type="author">\n';
    articleMeta += '        <name>\n';
    articleMeta += `          <surname>${e(surname)}</surname>\n`;
    articleMeta += `          <given-names>${e(given)}</given-names>\n`;
    articleMeta += '        </name>\n';
    if (author.orcid) {
      articleMeta += `        <contrib-id contrib-id-type="orcid">https://orcid.org/${e(author.orcid)}</contrib-id>\n`;
    }
    articleMeta += `        <xref ref-type="aff" rid="aff${position}"><sup>${position}</sup></xref>\n`;
    articleMeta += '      </contrib>\n';
  });
  articleMeta += '      </contrib-group>\n';

  (meta.affiliations || []).forEach((affiliation, index) => {
    const position = index + 1;
    const affOriginal = typeof affiliation === 'string' ? affiliation : (affiliation.original || '');
    const affEmail = typeof affiliation === 'string' ? '' : (affiliation.email || '');

    articleMeta += `      <aff id="aff${position}">\n`;
    articleMeta += `        <label>${position}</label>\n`;
    articleMeta += `        <institution content-type="original">${e(affOriginal)}</institution>\n`;
    if (affEmail) {
      articleMeta += `        <email>${e(affEmail)}</email>\n`;
    }
    articleMeta += '      </aff>\n';
  });

  articleMeta += '      <author-notes>\n';
  if (meta.conflict) {
    articleMeta += '        <fn fn-type="conflict" id="fn2">\n';
    articleMeta += '          <label>Conflicto de Intereses</label>\n';
    articleMeta += `          <p>${e(meta.conflict)}</p>\n`;
    articleMeta += '        </fn>\n';
  }
  if (meta.contributions && meta.contributions.length) {
    articleMeta += '        <fn fn-type="equal" id="fn3">\n';
    articleMeta += '          <label>Contribución autoral</label>\n';
    articleMeta += `          <p>${e(meta.contributions.join(' '))}</p>\n`;
    articleMeta += '        </fn>\n';
  }
  articleMeta += '      </author-notes>\n';

  if (meta.pubdate || meta.volume || meta['elocation-id']) {
    articleMeta += '      <pub-date date-type="pub" publication-format="electronic">\n';
    if (meta.pubdate) {
      const parts = String(meta.pubdate).split(/\s+/);
      if (parts[0]) articleMeta += `        <day>${e(parts[0])}</day>\n`;
      if (parts[1]) articleMeta += `        <month>${e(parts[1])}</month>\n`;
      if (parts[2]) articleMeta += `        <year>${e(parts[2])}</year>\n`;
    } else {
      articleMeta += `        <year>${e(year)}</year>\n`;
    }
    articleMeta += '      </pub-date>\n';
  }
  if (meta.volume) articleMeta += `      <volume>${e(meta.volume)}</volume>\n`;
  if (meta['elocation-id']) articleMeta += `      <elocation-id>${e(meta['elocation-id'])}</elocation-id>\n`;

  if (meta.received || meta.revised || meta.accepted) {
    const addHistoryDate = (type, value) => {
      if (!value) {
        return '';
      }

      const parts = String(value).trim().split(/\s+/);
      const day = parts[0] || '';
      const month = parts[1] || '';
      const yearPart = parts[2] || String(value).match(/\d{4}/)?.[0] || '';
      let dateXml = `        <date date-type="${type}">\n`;
      if (day) dateXml += `          <day>${e(day)}</day>\n`;
      if (month) dateXml += `          <month>${e(month)}</month>\n`;
      if (yearPart) dateXml += `          <year>${e(yearPart)}</year>\n`;
      dateXml += '        </date>\n';
      return dateXml;
    };

    articleMeta += '      <history>\n';
    articleMeta += addHistoryDate('received', meta.received);
    articleMeta += addHistoryDate('rev-recd', meta.revised);
    articleMeta += addHistoryDate('accepted', meta.accepted);
    articleMeta += '      </history>\n';
  }

  articleMeta += '      <permissions>\n';
  articleMeta += '        <license license-type="open-access" xlink:href="https://creativecommons.org/licenses/by/4.0/" xml:lang="es">\n';
  articleMeta += '          <license-p>Este es un artículo publicado en acceso abierto bajo una licencia Creative Commons</license-p>\n';
  articleMeta += '        </license>\n';
  articleMeta += '      </permissions>\n';

  if (meta.abstractEs) {
    articleMeta += '      <abstract>\n';
    articleMeta += '        <title>Resumen</title>\n';
    articleMeta += `        <p>${escapeXmlWithItalic(meta.abstractEs)}</p>\n`;
    articleMeta += '      </abstract>\n';
  }
  if (meta.abstractEn) {
    articleMeta += '      <trans-abstract xml:lang="en">\n';
    articleMeta += '        <title>Abstract</title>\n';
    articleMeta += `        <p>${e(meta.abstractEn)}</p>\n`;
    articleMeta += '      </trans-abstract>\n';
  }

  if (meta.kwdsEs && meta.kwdsEs.length) {
    articleMeta += '      <kwd-group xml:lang="es">\n';
    articleMeta += '        <title>Palabras claves:</title>\n';
    meta.kwdsEs.forEach(keyword => {
      articleMeta += `        <kwd>${e(keyword)}</kwd>\n`;
    });
    articleMeta += '      </kwd-group>\n';
  }
  if (meta.kwdsEn && meta.kwdsEn.length) {
    articleMeta += '      <kwd-group xml:lang="en">\n';
    articleMeta += '        <title>Keywords:</title>\n';
    meta.kwdsEn.forEach(keyword => {
      articleMeta += `        <kwd>${e(keyword)}</kwd>\n`;
    });
    articleMeta += '      </kwd-group>\n';
  }

  if (meta.funding && meta.funding.length) {
    articleMeta += '      <funding-group>\n';
    meta.funding.forEach(fundingSource => {
      articleMeta += '        <award-group award-type="contract">\n';
      articleMeta += '          <funding-source>\n';
      articleMeta += `            ${e(fundingSource)}\n`;
      articleMeta += '          </funding-source>\n';
      articleMeta += '        </award-group>\n';
    });
    articleMeta += `        <funding-statement>${e((meta.funding || []).join('; '))}</funding-statement>\n`;
    articleMeta += '      </funding-group>\n';
  }

  const refCount = meta.refCount || 0;
  articleMeta += '      <counts>\n';
  articleMeta += '        <fig-count count="0"/>\n';
  articleMeta += '        <table-count count="0"/>\n';
  articleMeta += '        <equation-count count="0"/>\n';
  articleMeta += `        <ref-count count="${e(refCount)}"/>\n`;
  articleMeta += '        <page-count count="1"/>\n';
  articleMeta += '      </counts>\n';
  articleMeta += '    </article-meta>\n';

  const body = '  <body>\n    <sec sec-type="intro">\n      <title>Introducción</title>\n      <p>[Completar con el contenido del manuscrito]</p>\n    </sec>\n  </body>\n';

  let back = '  <back>\n';
  back += '    <ref-list>\n';
  back += '      <title>Referencias bibliográficas</title>\n';
  back += '      <ref id="B1">\n';
  back += '        <label>1</label>\n';
  back += '        <element-citation publication-type="journal">\n';
  back += '          <comment>[Completar referencias en formato JATS element-citation]</comment>\n';
  back += '        </element-citation>\n';
  back += '      </ref>\n';
  back += '    </ref-list>\n';
  back += '  </back>\n';

  return `<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE article PUBLIC "-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.1 20121330//EN"\n  "https://jats.nlm.nih.gov/publishing/1.1/JATS-journalpublishing1-1.dtd">\n<article dtd-version="1.1" article-type="research-article" specific-use="sps-1.9" xml:lang="${e(meta.lang || 'es')}" xmlns:xlink="http://www.w3.org/1999/xlink">\n\n  <front>\n${journalMeta}\n\n${articleMeta}  </front>\n\n${body}${back}</article>`;
}

function escapeXmlWithItalic(text) {
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
