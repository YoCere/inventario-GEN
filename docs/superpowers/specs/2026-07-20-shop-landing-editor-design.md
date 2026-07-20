# Editor de la landing de la tienda — Diseño (SP2)

**Fecha:** 2026-07-20
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Depende de:** SP1 (`docs/superpowers/specs/2026-07-08-shop-landing-design.md`, mergeado en main `da60342`)

## Problema / objetivo

SP1 dejó la landing de `/tienda` funcionando y renderizando secciones desde la tabla
`landing_sections`, pero **el contenido solo se puede editar por base de datos**. SP2 construye el
editor: una pantalla en Ajustes donde el dueño del negocio arma su landing — reordenar secciones,
escribir texto con formato, subir imágenes, activar/desactivar secciones y publicar.

**Restricción explícita del usuario: que sea escalable.** Agregar un tipo de sección nuevo no debe
obligar a tocar el editor.

## Alcance

- **SP2 (este spec):** pantalla dedicada, CRUD de secciones, reordenar, WYSIWYG (Trix) + saneo al
  guardar, subida de imágenes (incluida galería simple), permiso propio, toggle de publicación.
- **Fuera (SP3 / futuro):** arrastrar y soltar para reordenar, vista previa en vivo embebida,
  reordenar imágenes de la galería, tipos nuevos (mapa, redes), SEO/OG, plantillas alternativas.

## Decisiones (tomadas con el usuario)

| Decisión | Elegido | Por qué |
|---|---|---|
| Dónde vive el editor | **Página dedicada** `settings/tienda/landing` (lista + formulario, 2 columnas) | El acordeón de Ajustes no da espacio y `SettingGroups` ya pesa 743 líneas; sumar el editor ahí escala mal. |
| Reordenar | **Flechas ↑↓** | Cero dependencias, inmune al re-render de Livewire. Arrastrar queda como mejora posterior. |
| Imágenes | **Completo con galería simple** | Héroe + acerca + galería (agregar/quitar, sin reordenar) → las 7 secciones quedan usables. |
| Permisos | **Permiso propio `shop.landing.manage`** | Ajustable por rol desde la pantalla de Roles, igual que se hizo con Finanzas. Delegable sin regalar todo Ajustes. |
| Vista previa | **Link "Ver tienda ↗"**, no iframe | El preview en vivo se puede sumar después sin rehacer nada. |

## Contexto existente (reutilizado)

- **SP1:** `App\Shop\Models\LandingSection`, `App\Shop\Landing\SectionTypes` (registry de 7 tipos),
  `App\Shop\Services\LandingHtmlSanitizer`, `App\Shop\Landing\LandingUrl`, partials de render en
  `resources/views/shop/landing/sections/`, ajuste `shop_landing_enabled`.
- `App\Livewire\Settings\SettingGroups` + su blade — precedente de subida de imagen
  (`WithFileUploads` → `store('shop','public')`, validación `image|max:2048`) y del panel "Tienda".
  **No se extiende este componente**; solo se le agrega un botón que enlaza al editor.
- `routes/web.php:158` — grupo de Ajustes (`settings.index`). El editor va acá, **no** en
  `routes/shop-admin.php`: esas rutas solo se cargan con `shop_enabled='1'` y gatean por rol
  (`middleware('admin')`), y se quiere poder preparar la landing antes de publicar la tienda.
- `RolesAndPermissionsSeeder` + patrón de migración aditiva de permisos (`givePermissionTo`, nunca
  `syncPermissions`) establecido en el trabajo de Finanzas.
- Front: Alpine 3, Tailwind, Vite con entries separados admin (`resources/js/app.js`) y tienda
  (`resources/js/shop/shop.js`). No hay librería de WYSIWYG ni de drag&drop instalada.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | `SectionTypes` gana dos claves por tipo: `form` (ruta del partial de formulario) y `rules` (reglas de validación del `data`). Sigue siendo la fuente única. |
| R2 | Ruta `GET settings/tienda/landing` (nombre `settings.shop-landing`) en `routes/web.php`, con `middleware('can:shop.landing.manage')`. |
| R3 | Permiso `shop.landing.manage` agregado al catálogo del seeder + migración aditiva idempotente que lo crea y lo asigna a `developer` y `admin` (sin re-sincronizar el resto). |
| R4 | Componente `App\Livewire\Settings\LandingEditor`: lista de secciones ordenadas, mover ↑/↓, agregar sección (elige tipo del registry, siembra `defaultData`), eliminar, activar/desactivar, y switch de `shop_landing_enabled`. |
| R5 | Componente `App\Livewire\Settings\LandingSectionForm`: edita la sección seleccionada renderizando el partial de formulario de su tipo; valida con las `rules` del registry; guarda explícitamente (botón, sin auto-save). |
| R6 | Los dos componentes se comunican por eventos Livewire: `landing-section-selected` (editor → formulario) y `landing-sections-changed` (formulario → editor, para refrescar la lista tras guardar). |
| R7 | Un partial de formulario por tipo en `resources/views/settings/landing/forms/{tipo}.blade.php` para los 7 tipos existentes. |
| R8 | Texto rico: **Trix** en el bundle admin, montado solo en campos `body_html`, dentro de `wire:ignore` con input oculto sincronizado al componente. |
| R9 | **Saneo al guardar**: todo `body_html` pasa por `LandingHtmlSanitizer` antes de persistir. El render sigue saneando (defensa en profundidad, invariante de SP1). |
| R10 | **URLs validadas al guardar**: `cta_target`, `target` y `link` pasan por `LandingUrl` al persistir, no solo al renderizar. |
| R11 | Imágenes: subida con `WithFileUploads`, `image\|max:2048`, `store('shop/landing','public')`; se guarda la ruta relativa en `data`. Las rutas pasan por `LandingUrl::safeStoragePath()` al guardar. |
| R12 | Galería: agregar y quitar imágenes (append al array `images`). Sin reordenar. |
| R13 | Al eliminar una imagen o una sección con imágenes, se borran los archivos del disco (no acumular huérfanos). |
| R14 | Botón "Editar landing →" en el panel Tienda de `SettingGroups`, visible con `@can('shop.landing.manage')`. |
| R15 | `DefaultLandingTemplateSeeder` deja de hardcodear su copy y usa `SectionTypes::defaultData()` (cierra la divergencia detectada en el review final de SP1). |

## Arquitectura

### El contrato de escalabilidad

Agregar un tipo de sección nuevo son **3 archivos y ningún cambio en el editor**:

1. Entrada en `SectionTypes::map()` → `label`, `partial` (render), `form` (formulario), `default`, `rules`.
2. Partial de render `resources/views/shop/landing/sections/{tipo}.blade.php`.
3. Partial de formulario `resources/views/settings/landing/forms/{tipo}.blade.php`.

El editor **itera el registry y nunca ramifica por tipo** (`@include(SectionTypes::form($type))`).
Un test recorre `SectionTypes::keys()` y afirma que existen ambos partials de cada tipo, de modo que
un tipo declarado a medias falla en CI en vez de romper en producción.

### Componentes y responsabilidades

- `LandingEditor` — **estructura**: qué secciones hay, en qué orden, cuáles están activas, si la
  landing se publica. No sabe editar el contenido de ninguna.
- `LandingSectionForm` — **contenido**: los campos de UNA sección, su validación, su saneo, sus
  imágenes. No sabe nada del orden ni del resto de la lista.

Se parten desde el inicio (no "cuando crezca") justamente por el antecedente de `SettingGroups`.

### Flujo de datos

1. `LandingEditor` lista `LandingSection::ordered()`; al hacer clic en una fila emite
   `landing-section-selected` con el id.
2. `LandingSectionForm` la carga en un array `$form`, renderiza el partial de su tipo.
3. Al guardar: valida con `rules` → sanea `body_html` → valida URLs → persiste `data` →
   emite `landing-sections-changed`.
4. `LandingEditor` recarga la lista (los títulos de las filas salen del `data`).

Mover ↑/↓ intercambia el `sort_order` con el vecino y persiste al instante (acción estructural,
no necesita el botón guardar).

## Seguridad

- El editor es la puerta por la que entra contenido que se publica **sin autenticación** en `/tienda`.
  Las dos barreras de SP1 (`LandingHtmlSanitizer`, `LandingUrl`) se aplican **al guardar**, además de
  al renderizar. Los code-reviews de SP1 encontraron XSS almacenado y CSS-injection reales en este
  mismo terreno; el editor no puede aflojar esa guardia.
- Acceso por permiso (`can:shop.landing.manage`), enforced en el servidor, no solo escondiendo el
  botón. `developer` pasa por `Gate::before`.
- Subidas restringidas a imágenes con tope de tamaño; la ruta guardada se valida antes de persistir.

## Manejo de errores

- Validación por tipo con las `rules` del registry; los errores se muestran junto al campo.
- Sección sin seleccionar → el panel derecho muestra un estado vacío ("Elegí una sección").
- Si no queda ninguna sección activa, el editor avisa que `/tienda` mostrará el estado mínimo
  (definido en SP1), pero no lo impide.
- Fallo al borrar un archivo del disco no aborta la operación (se registra y sigue): el registro en
  DB manda.

## Testing

- **Registry:** cada tipo de `SectionTypes::keys()` tiene `partial` y `form` existentes (`View::exists`)
  y `rules` declaradas. Este test es el que blinda el contrato de escalabilidad.
- **Editor (Livewire):** agregar sección de cada tipo crea la fila con `defaultData`; mover ↑/↓
  reordena y persiste; activar/desactivar cambia `is_enabled`; eliminar borra la fila; el switch
  escribe `shop_landing_enabled`.
- **Formulario (Livewire):** guardar persiste `data`; un `body_html` con `<script>` se guarda saneado;
  un `cta_target` con `javascript:` no se persiste como tal; validación rechaza campos requeridos vacíos.
- **Imágenes:** subir guarda archivo + ruta; quitar borra el archivo del disco; galería agrega al array.
- **Permisos:** usuario sin `shop.landing.manage` → 403 en la ruta; admin y developer → 200.
- **Regresión SP1:** la landing pública sigue renderizando lo que el editor guarda (test de ida y vuelta:
  guardar por el editor → `GET /tienda` muestra el contenido).
