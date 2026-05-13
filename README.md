# SGE Tags XML

Aplicación para convertir archivos DOCX a XML JATS (SPS 1.9), con frontend en React/Vite y backend en PHP.

## Estructura del proyecto

```
sge-tags-xml/
├── frontend/
│   ├── index.php           # Loader web (producción/dev)
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
	└── debug.php           # Herramientas de depuración
```

## Punto de entrada

- Frontend: `frontend/index.php`
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

1. Abrir `frontend/index.php` en el entorno web.
2. Subir un `.docx` desde la interfaz.
3. El frontend envía el archivo a `backend/convert.php`.
4. El backend parsea metadatos y devuelve XML JATS.
5. Se puede ajustar metadata manualmente y descargar el `.xml`.

## Nota

El flujo actual está centrado en generación de XML JATS. No incluye exportación a PDF.
