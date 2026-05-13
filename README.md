# SGE Tags XML

Aplicacion para convertir archivos DOCX a XML JATS (SPS 1.9), con frontend en React/Vite y backend en PHP.

## Estructura actual

```
frontend/
├── index.php             # Entrada web del frontend (dev/prod)
├── index.html            # Entrada de Vite
├── package.json          # Dependencias React/Vite
├── package-lock.json
├── vite.config.js
├── src/
├── css/
└── dist/                 # Build generado

backend/
├── convert.php           # Endpoint DOCX -> XML
├── pdf.php               # Endpoint XML -> PDF/HTML
├── DocxParser.php
├── debug.php
└── meta_debug.json
```

## Punto de entrada

- Frontend: `frontend/index.php`
- Backend: `backend/convert.php` y `backend/pdf.php`

## Scripts

Desde `frontend`:

```bash
npm run dev
npm run build
npm run preview
```
