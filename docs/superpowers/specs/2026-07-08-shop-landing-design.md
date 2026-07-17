# Landing configurable de la tienda — Diseño (SP1: render + datos + ruteo)

**Fecha:** 2026-07-08
**Estado:** Aprobado (diseño) — pendiente revisión de spec

## Problema / objetivo

Hoy `/tienda` cae directo en el catálogo de productos. Se quiere una **página de presentación
(landing)** antes del catálogo: héroe, acerca de/historia, horarios, qué vendemos, galería, contacto
y un botón "Entrar a la tienda". El contenido será **configurable por secciones** (SP2, editor en
Ajustes). Este spec cubre **SP1**: el modelo de datos, el motor de render por secciones, el ruteo, el
saneado de HTML y una plantilla por defecto sembrada — de modo que `/tienda` muestre un landing real
que luego el editor (SP2) manipula.

## Alcance

- **SP1 (este spec):** tabla + modelo de secciones, catálogo de 7 tipos de sección con su render, ruteo
  (landing en `/tienda`, catálogo en `/tienda/catalogo`), saneado de HTML, plantilla por defecto.
- **SP2 (spec aparte):** editor Livewire en Ajustes — reordenar/activar/agregar secciones, formularios
  por tipo, **WYSIWYG (Trix)** + saneado al guardar, subida de imágenes, vista previa en vivo,
  aplicar plantillas.
- **SP3 (opcional):** más tipos (mapa, redes), SEO/OG, pulido.

## Contexto existente (reutilizado)

- `app/Shop/` — módulo gateado por `ShopFeatureFlag` (`shop_enabled`). `ShopServiceProvider` carga
  rutas (`routes/shop.php`), el namespace de vistas `shop` (`resources/views/shop/`), etc.
- `routes/shop.php` — público, sin auth: `GET /tienda` → `ShopController@index` (`shop.index`),
  `/tienda/producto/{slug}` → `show`, `/tienda/api/search`, checkout/reservar.
- `resources/views/shop/layouts/app.blade.php` — shell de la tienda; inyecta variables CSS de tema
  (`--shop-primary/secondary/accent/text-on-primary`) desde `Setting::get`, logo, nombre.
- `resources/views/shop/index.blade.php` — catálogo actual (grilla + filtros).
- `App\Models\Setting` — key/value (cache-forever por clave). Ajustes `shop_*` ya existen (nombre,
  colores, logo, whatsapp, mensaje de bienvenida).
- Subida de imágenes: patrón `WithFileUploads` → disco `public` (usado por el logo de la tienda).
- `WhatsAppLinkBuilder` + `shop_whatsapp_number` para CTAs de WhatsApp.
- Existe `resources/views/shop/placeholder.blade.php` **huérfano** (página "próximamente" sin cablear)
  — no se reutiliza; el landing es una vista nueva que extiende el layout de la tienda.

## Decisiones (tomadas con el usuario)

- **Ruteo:** `/tienda` = landing; catálogo pasa a `/tienda/catalogo`. El botón "Entrar a la tienda"
  apunta al catálogo. Se actualizan los links internos que navegan al catálogo.
- **Default:** landing **activado** con una **plantilla por defecto** sembrada (se ve "algo" de una).
- **Tipos de sección:** Héroe, Acerca/Historia, Horarios, Qué vendemos (categorías), Galería,
  Contacto, CTA.
- **Texto rico (WYSIWYG)** en los cuerpos → **saneado de HTML server-side obligatorio** (el landing
  es público; el HTML guardado se renderiza crudo).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Tabla `landing_sections` + modelo `App\Shop\Models\LandingSection`: `type` (string), `sort_order` (int), `is_enabled` (bool), `data` (json), timestamps. Scopes `ordered()` y `enabled()`. |
| R2 | Catálogo de 7 tipos con esquema de datos y partial de render cada uno: `hero`, `about`, `hours`, `categories`, `gallery`, `contact`, `cta`. Un registry declara tipos + label + partial + esquema por defecto. |
| R3 | Saneado de HTML: servicio `App\Shop\Services\LandingHtmlSanitizer` (HTMLPurifier, allowlist: p, br, strong, em, u, ul, ol, li, a[href,title], h2-h4, blockquote). Los cuerpos ricos se guardan/renderizan saneados. |
| R4 | Ruteo: `GET /tienda` → `ShopController@index` que renderiza el **landing** si el landing está activado, o el **catálogo** si no. `GET /tienda/catalogo` → `ShopController@catalog` (nombre `shop.catalog`) = lógica actual del catálogo. `shop.index` conserva su nombre. |
| R5 | Ajuste `shop_landing_enabled` (default `'1'`). Si `'0'` → `/tienda` muestra el catálogo (comportamiento actual). |
| R6 | Vista `shop.landing` (extiende el layout de la tienda, hereda tema): itera secciones `enabled()->ordered()` e incluye el partial por tipo. Sección `cta` y héroe con botón → `route('shop.catalog')` (o WhatsApp/URL según data). |
| R7 | Links internos que llevan al catálogo (ej. "seguir comprando" del carrito, volver desde producto, filtros de categoría) apuntan a `shop.catalog`. El logo/header apunta a `shop.index` (landing, = home). |
| R8 | Migración sembradora **aditiva** (nunca `migrate:fresh`) que inserta una plantilla por defecto (héroe + acerca + horarios + qué vendemos + CTA) si no hay secciones, y setea `shop_landing_enabled='1'`. Idempotente. |
| R9 | Sección "Qué vendemos": `source='auto'` tira de las categorías públicas de la tienda; `source='manual'` usa items definidos (para SP2). SP1 soporta ambos; la plantilla por defecto usa `auto`. |

## Arquitectura

### Datos
- `landing_sections` (tabla nueva). `LandingSection` en `app/Shop/Models/` con `data` casteado a array.
- **Registry de tipos** `App\Shop\Landing\SectionTypes`: mapa `type => ['label', 'partial', 'default_data']`.
  Fuente única para validar tipos, render y sembrar defaults. (En SP2 el editor lee este registry para
  ofrecer los tipos y sus formularios.)

### Render
- `ShopController@index`: si `shop_landing_enabled==='1'` → `view('shop.landing', ['sections' => LandingSection::enabled()->ordered()->get()])`; si no → delega a `catalog()`.
- `ShopController@catalog`: la lógica actual de `index` (grilla + filtros + categorías) movida aquí,
  render `shop.index` (la vista de catálogo se conserva; solo cambia quién la invoca).
- `resources/views/shop/landing.blade.php` extiende `shop::layouts.app`; loop de secciones →
  `@include('shop.landing.sections.'.$section->type, ['data' => $section->data])`.
- Partials en `resources/views/shop/landing/sections/{hero,about,hours,categories,gallery,contact,cta}.blade.php`,
  cada uno estilizado con las variables de tema (`var(--shop-primary)`, etc.).
- Cuerpos ricos (`about`, etc.) se renderizan con `{!! $sanitized !!}` — el valor ya viene saneado
  (sembrado saneado en SP1; el editor sanea al guardar en SP2). Defensa en profundidad: el partial
  pasa el HTML por el sanitizer al renderizar también.

### Esquemas de sección (data JSON)
- `hero`: `{ heading, subheading, background_image_path?, cta_text?, cta_target? ('catalog'|'whatsapp'|url) }`
- `about`: `{ heading, body_html (rico), image_path? }` (sirve para Historia/Quiénes somos/Qué hacemos — se pueden tener varias secciones `about`)
- `hours`: `{ heading, rows: [{label, value}] }` (ej. "Lun–Vie" → "9:00–18:00")
- `categories`: `{ heading, source: 'auto'|'manual', items?: [{label, image_path?, link?}] }`
- `gallery`: `{ heading?, images: [path, ...] }`
- `contact`: `{ heading, whatsapp?, address?, email? }`
- `cta`: `{ heading?, text?, button_text, target: 'catalog'|'whatsapp'|url }`

### Seguridad
- HTML de secciones **saneado** (allowlist) antes de renderizar; nunca se confía en HTML crudo. El
  landing es público → sin auth, así que el saneo es la barrera clave contra XSS almacenado (relevante
  cuando SP2 permita pegar/editar HTML).
- Colores del tema ya se validan `^#[0-9A-F]{6}$` antes de interpolar en `<style>` (existente).
- Rutas de imagen se sirven vía disco `public` / `Storage::url`; no se interpola input crudo en `src`
  sin ser una ruta almacenada.

## Manejo de errores

- Tipo de sección desconocido en la tabla → el loop lo salta (log), no rompe la página.
- `data` incompleta (falta un campo) → el partial usa defaults del registry / condicionales `@isset`.
- Sin secciones y landing activado → se muestra un estado mínimo (héroe con nombre de la tienda + CTA),
  nunca una página en blanco.

## Testing

- Modelo/migración: `LandingSection` scopes `ordered`/`enabled`; la migración sembradora crea la
  plantilla por defecto (idempotente: correr dos veces no duplica).
- Sanitizer: `LandingHtmlSanitizer::sanitize('<script>…</script><p>ok<b>x</b></p>')` elimina el script,
  conserva `<p><strong>` (según allowlist), quita atributos peligrosos (`onclick`, `javascript:`).
- Ruteo/render (feature, con `shop_enabled='1'`):
  - `GET /tienda` con landing activado → 200 y contiene texto de la plantilla (ej. el héroe).
  - `GET /tienda` con `shop_landing_enabled='0'` → renderiza el catálogo (contiene marca del catálogo).
  - `GET /tienda/catalogo` → 200, catálogo.
  - Un cuerpo `about` con `<script>` sembrado/guardado no aparece en el HTML renderizado.
- Público: las rutas no exigen auth (siguen abiertas como el resto de `/tienda`).

## Fuera de alcance (SP2/SP3)

- Editor en Ajustes (Livewire), WYSIWYG (Trix), reordenar por drag, subir imágenes desde el editor,
  vista previa en vivo, aplicar plantillas — **SP2**.
- Mapa embebido, redes sociales, SEO/OG, más plantillas — **SP3**.
