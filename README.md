# SciELO JATS Converter · SPS 1.9

Aplicación web PHP para convertir artículos académicos en formato Word (.docx)
a XML JATS según el estándar SciELO Publishing Schema (SPS) 1.9.

---

## Requisitos

- PHP 8.1 o superior
- Extensión **ZipArchive** habilitada (viene incluida en PHP por defecto)
- Extensión **SimpleXML** habilitada (viene incluida en PHP por defecto)
- Servidor web: Apache, Nginx, o servidor built-in de PHP

---

## Instalación rápida

```bash
# Clonar / copiar la carpeta del proyecto
cd scielo-jats/

# Crear carpeta de uploads con permisos
mkdir -p uploads
chmod 775 uploads

# Iniciar servidor de desarrollo (PHP built-in)
php -S localhost:8080
```

Luego abrí el navegador en: **http://localhost:8080**

---

## Estructura del proyecto

```
scielo-jats/
├── index.php        ← Aplicación principal (parser PHP + HTML)
├── css/
│   └── style.css    ← Estilos
├── js/
│   └── app.js       ← Lógica frontend (fetch + drag & drop)
├── uploads/         ← Carpeta temporal (se vacía automáticamente)
└── README.md
```

---

## Qué detecta automáticamente

| Campo | Ejemplo |
|---|---|
| DOI | `10.18294/sc.2026.5939` |
| Idioma | `es` |
| Versión SPS | `sps-1.9` |
| ISSNs | `1669-2381` / `1851-8265` |
| Título del artículo (ES + EN) | ✅ |
| Autores con ORCID | ✅ |
| Afiliaciones | ✅ |
| Resumen en español e inglés | ✅ |
| Palabras clave (ES + EN) | ✅ |
| Fechas (recibido / revisado / aprobado) | ✅ |
| Financiamiento | ✅ |
| Conflicto de intereses | ✅ |
| Contribuciones autorales | ✅ |
| Secciones del cuerpo | ✅ (estructura) |

---

## Qué requiere completar manualmente

- **Cuerpo del artículo**: el texto íntegro de cada sección.
- **Referencias bibliográficas**: deben formatearse como `<element-citation>` JATS.
- **Paginación**: `<volume>`, `<issue>`, `<fpage>`, `<lpage>` o `<elocation-id>`.

---

## Sin dependencias externas

- No requiere Composer
- No usa librerías PHP de terceros
- El frontend usa solo JS vanilla (sin frameworks)
- Los archivos subidos se eliminan automáticamente después del procesamiento

---

## Estándar generado

- JATS 1.3 (Journal Publishing DTD)
- SciELO Publishing Schema (SPS) 1.9
- Licencia CC BY 4.0 por defecto
