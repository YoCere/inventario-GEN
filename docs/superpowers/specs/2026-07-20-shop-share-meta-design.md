# Compartir el enlace de la tienda (SEO / Open Graph) — Diseño

**Fecha:** 2026-07-20
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Depende de:** SP1 (landing, `da60342`) y SP2 (editor, `328ec67`), ambos en main y desplegados.

## Problema / objetivo

Cuando se comparte el enlace de la tienda por WhatsApp o redes, aparece pelado: sin título, sin
descripción y sin imagen. La landing y el catálogo **no tienen ninguna etiqueta Open Graph**; la ficha
de producto tiene tres escritas a mano dentro de la vista (`og:title`, `og:image`, `og:description`) y
le faltan `og:url`, `og:type` y las de Twitter.

Objetivo: que las tres páginas públicas se vean bien al compartirlas, con contenido que se arma solo a
partir de lo ya cargado y que se pueda sobrescribir cuando haga falta.

Contexto de negocio: el enlace de la tienda se difunde principalmente por WhatsApp — ya existen
`WhatsAppLinkBuilder` y el QR en Ajustes. Una vista previa pobre se nota en cada envío.

## Alcance

- **Este spec:** objeto de valor + constructor de metadatos, partial único en el layout, overrides
  editables con vista previa, favicon, `noindex` en checkout.
- **Fuera:** redimensionado/compresión de imágenes (requiere librería nueva), `sitemap.xml`, `robots.txt`,
  datos estructurados JSON-LD, y los sub-proyectos B–E de SP3 (mapa/redes, vista previa en vivo,
  arrastrar y soltar, plantillas).

## Decisiones (tomadas con el usuario)

| Decisión | Elegido |
|---|---|
| Páginas cubiertas | **Las tres**: landing, catálogo y ficha de producto |
| Origen del contenido | **Automático con override** — se arma con lo ya cargado, editable si no sirve |
| Imagen al compartir | **Subida dedicada, con cadena de respaldo**: override → fondo del héroe → logo |

## Contexto existente (reutilizado)

- `resources/views/shop/layouts/app.blade.php` — ya expone `@stack('head')` (línea 37) y
  `<title>@yield('title', $businessName)</title>`. No tiene `meta description`, ni OG, ni favicon.
- `resources/views/shop/product.blade.php:24-28` — `@push('head')` con OG parcial **escrito a mano**.
  Se elimina: pasa a salir del partial compartido, para no tener dos fuentes de verdad.
- `App\Shop\Http\Controllers\ShopController` — `index()` (landing), `catalog()`, `show()` (producto).
- `App\Shop\Http\Controllers\ReservationController@checkout`.
- `App\Models\Setting` (key/value, cache-forever) y los `shop_*` existentes (`shop_business_name`,
  `shop_logo_path`, `shop_welcome_message`).
- `App\Shop\Models\LandingSection` — la sección `hero` aporta `heading`, `subheading` y
  `background_image_path` como respaldos.
- `App\Shop\Landing\LandingImages` — `store()` / `delete()`, y `LandingUrl::safeStoragePath()`.
- Patrón de subida del logo en `SettingGroups` (validación `image|max:2048`, borrado del archivo previo).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | `App\Shop\Seo\ShareMeta` — objeto de valor inmutable: `title`, `description`, `imageUrl` (absoluta o null), `url`, `type` (`website`\|`product`), `noindex` (bool). |
| R2 | `App\Shop\Seo\ShareMetaBuilder` — `forLanding()`, `forCatalog()`, `forProduct(Product)`, `forCheckout()`. Aplica las cadenas de respaldo descritas abajo. |
| R3 | Partial único `resources/views/shop/partials/share-meta.blade.php`, incluido desde el layout, que emite: `meta description`, `canonical`, `og:type`, `og:site_name`, `og:title`, `og:description`, `og:url`, `og:image` (si hay), `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`, y `<meta name="robots" content="noindex">` cuando corresponde. |
| R4 | `ShopController@index/catalog/show` y `ReservationController@checkout` pasan `$shareMeta` a la vista. El layout usa el que reciba; si no recibe ninguno, cae a `forLanding()` (nunca queda sin etiquetas). |
| R5 | **`og:image` y `og:url` SIEMPRE absolutas.** `Storage::url()` devuelve ruta relativa; se envuelve con `url()`. Un test verifica que `og:image` empieza con `http`. |
| R6 | Overrides en tres ajustes nuevos: `shop_share_title`, `shop_share_description`, `shop_share_image_path`. Vacío = usar el automático. |
| R7 | Componente `App\Livewire\Settings\LandingShareSettings` en la página del editor de landing: los tres campos + subida de imagen + **vista previa de la tarjeta** simulando cómo la muestra WhatsApp. Guardado inmediato (como el panel del logo), no diferido. |
| R8 | La subida de la imagen de compartir borra la anterior del disco **en el momento** — es correcto acá porque el guardado es inmediato y no hay paso de "publicar" que pueda quedar sin confirmar, a diferencia de las imágenes de sección (ver la nota de diseño más abajo). |
| R9 | Se elimina el `@push('head')` de `product.blade.php`; la ficha de producto obtiene sus etiquetas del partial. |
| R10 | Favicon en el layout de la tienda, tomado de `shop_logo_path` si existe. |
| R11 | El acceso al panel se gatea con el permiso existente `shop.landing.manage`, y el componente re-chequea el permiso en cada acción (invariante establecida en SP2). |

## Cadenas de respaldo

**Landing** (`forLanding`)
- título: `shop_share_title` → `shop_business_name` → `config('app.name')`
- descripción: `shop_share_description` → `subheading` del primer `hero` habilitado → `shop_welcome_message` → `''`
- imagen: `shop_share_image_path` → `background_image_path` del primer `hero` habilitado → `shop_logo_path` → `null`

**Catálogo** (`forCatalog`)
- título: `"Catálogo · {nombre del negocio}"`
- descripción e imagen: igual que la landing.

**Producto** (`forProduct`)
- título: nombre del producto
- descripción: `strip_tags` de la descripción, recortada a 160 caracteres → descripción de la landing si está vacía
- imagen: foto principal del producto → la imagen de la landing como respaldo
- `type` = `product`

**Checkout** (`forCheckout`): título e imagen de la landing, `noindex = true`.

## Arquitectura

### Por qué un objeto de valor + un constructor
El partial no debe saber de dónde sale cada dato, y los controladores no deben armar strings de
metadatos. `ShareMeta` es un contenedor tonto; `ShareMetaBuilder` concentra toda la lógica de respaldo
y es lo único que hay que testear para verificar las cadenas. Agregar una página nueva es una fábrica
más, sin tocar el partial ni el layout.

### Nota de diseño: por qué acá el borrado SÍ es inmediato
En SP2 el borrado de imágenes de sección se difirió hasta después de guardar, porque el editor tiene un
botón "Guardar" separado y el usuario podía irse sin confirmar, dejando la fila apuntando a un archivo
inexistente. Este panel guarda al instante (igual que el logo): no existe el estado intermedio, así que
borrar el archivo viejo en el momento es correcto. **Queda escrito para que nadie lo "corrija" después.**

### Absolutas, no relativas
`Storage::url('shop/x.jpg')` → `/storage/shop/x.jpg`. WhatsApp y Facebook descartan las imágenes con
ruta relativa. Todo lo que salga como `og:image`/`og:url`/`canonical` pasa por `url()`.
**Requisito de despliegue:** `APP_URL` debe ser el dominio público con https (en desarrollo figura
`http://inventory-management-system.test`).

## Seguridad

- Los tres overrides son texto plano y se emiten dentro de atributos `content="…"` con escapado normal
  de Blade (`{{ }}`) — nunca `{!! !!}`. Un título con comillas no puede romper el atributo ni inyectar
  etiquetas.
- `shop_share_image_path` pasa por `LandingUrl::safeStoragePath()` al guardar, igual que el resto de
  rutas de imagen.
- Panel gateado por permiso, con re-chequeo por acción (R11).

## Manejo de errores

- Sin imagen en ningún eslabón de la cadena: se omite `og:image` por completo (mejor que apuntar a un
  archivo inexistente, que muestra un hueco roto en la vista previa).
- Sin sección `hero` o landing deshabilitada: los respaldos siguen funcionando (nombre del negocio y
  mensaje de bienvenida).
- Descripciones con HTML (la del producto puede tenerlo): `strip_tags` + recorte antes de emitir.

## Testing

- **Constructor:** cada cadena de respaldo, eslabón por eslabón — con override, sin override y con héroe,
  sin override y sin héroe. Producto con y sin descripción. Checkout marcado `noindex`.
- **Absolutas (R5):** `GET /tienda` → `og:image` empieza con `http`; `og:url` es absoluta. Es la
  regresión más importante del spec.
- **Render:** las tres páginas emiten `og:title`, `og:description`, `og:url`, `twitter:card`.
  El producto NO emite etiquetas duplicadas (se quitó el `@push`).
- **Overrides:** setear `shop_share_title` cambia `og:title` en `/tienda`.
- **Escapado:** un título con `"` y `<` sale escapado, sin romper el atributo.
- **Checkout:** emite `noindex`.
- **Panel:** guarda los tres ajustes; subir imagen guarda archivo y borra el anterior; sin permiso → 403
  al invocar acciones.
