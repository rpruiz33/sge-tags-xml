# SGE Tags XML

Aplicación para convertir archivos DOCX a XML JATS (SPS 1.9), con frontend en React/Vite y backend en PHP.

## Estructura del proyecto

```
sge-tags-xml/
├── frontend/
│   ├── index.html          # Entrada de Vite
│   ├── package.json
│   ├── package-lock.json
│   ├── vite.config.js
│   ├── src/
│   ├── css/
│   └── dist/               # Build generado
└── backend/
	├── index.php           # Estado simple del backend
	├── convert.php         # Endpoint DOCX -> XML JATS
	├── DocxParser.php      # Parser y generación JATS
	└── JatsRichParser.php
```

## Punto de entrada

- Frontend: `frontend/index.html` en Vite o `frontend/dist/` después del build
- Endpoint principal backend: `backend/convert.php`

## Requisitos

- PHP (entorno tipo XAMPP)
- Node.js 18+
- npm

## Desarrollo frontend

Desde `frontend`:

```bash
npm install
npm run dev
```

## Build frontend

Desde `frontend`:

```bash
npm run build
npm run preview
```

## Flujo de uso

1. Abrir el frontend con Vite en desarrollo o servir `frontend/dist/` en producción.
2. Subir un `.docx` desde la interfaz.
3. El frontend envía el archivo a `backend/convert.php`.
4. El backend parsea metadatos y devuelve XML JATS.
5. Se puede ajustar metadata manualmente y descargar el `.xml`.

## Nota

El flujo actual está centrado en generación de XML JATS.
