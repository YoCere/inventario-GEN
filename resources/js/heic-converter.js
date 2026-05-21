/**
 * HEIC → JPEG converter para el form de productos.
 *
 * Por qué: iPhone fotografía en HEIC por default. Sin Imagick en el server,
 * el upload falla. Sin conversión en cliente, el usuario no técnico tendría
 * que cambiar la config de su iPhone — UX inaceptable.
 *
 * Estrategia: interceptar el evento change del input file en fase capture
 * (antes que Livewire), detectar HEIC, convertir a JPEG via heic2any, y
 * re-dispatch del evento con archivos ya convertidos para que Livewire
 * suba los JPEG limpios.
 *
 * heic2any se carga dinámicamente (~1.2MB) — solo cuando hay HEIC, así
 * usuarios que no usen iPhone no pagan el costo del bundle.
 */

const HEIC_EXT_REGEX = /\.(heic|heif)$/i;
const HEIC_MIME_REGEX = /^image\/heic|^image\/heif/i;

function isHeic(file) {
    return HEIC_EXT_REGEX.test(file.name) || HEIC_MIME_REGEX.test(file.type);
}

function toast(message, type = 'info') {
    if (typeof Livewire !== 'undefined' && Livewire.dispatch) {
        Livewire.dispatch('toast', { message, type });
    } else {
        console.log(`[${type}] ${message}`);
    }
}

async function convertFiles(files) {
    const heic2any = (await import('heic2any')).default;

    return Promise.all(
        files.map(async (file) => {
            if (!isHeic(file)) return file;

            try {
                const blob = await heic2any({
                    blob: file,
                    toType: 'image/jpeg',
                    quality: 0.85,
                });
                const realBlob = Array.isArray(blob) ? blob[0] : blob;
                const newName = file.name.replace(HEIC_EXT_REGEX, '.jpg');
                return new File([realBlob], newName, { type: 'image/jpeg' });
            } catch (err) {
                console.error(`HEIC convert failed for ${file.name}:`, err);
                toast(`No se pudo convertir ${file.name}. Pídele al cliente que la convierta a JPG.`, 'error');
                return null;
            }
        }),
    ).then((arr) => arr.filter(Boolean));
}

/**
 * Engancha un input file con conversión HEIC transparente.
 * Idempotente: marca el input con _heicHooked para no engancharlo dos veces.
 */
export function attachHeicConverter(input) {
    if (!input || input._heicHooked) return;
    input._heicHooked = true;

    input.addEventListener(
        'change',
        async function (e) {
            // Si la conversión ya pasó, no re-procesar (este evento es el re-dispatch).
            if (this._heicConverted) {
                this._heicConverted = false;
                return;
            }

            const files = Array.from(this.files);
            if (files.length === 0) return;

            const heicFiles = files.filter(isHeic);
            if (heicFiles.length === 0) return; // ningún HEIC, dejar pasar a Livewire

            // Frenar la propagación a Livewire — vamos a re-disparar con archivos convertidos.
            e.stopImmediatePropagation();
            e.preventDefault();

            toast(`Convirtiendo ${heicFiles.length} imagen(es) HEIC a JPG…`, 'info');

            try {
                const converted = await convertFiles(files);

                if (converted.length === 0) {
                    this.value = '';
                    return;
                }

                // Reemplazar files del input + re-disparar change para que Livewire suba.
                const dt = new DataTransfer();
                converted.forEach((f) => dt.items.add(f));
                this.files = dt.files;
                this._heicConverted = true;
                this.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (err) {
                console.error('HEIC conversion error:', err);
                toast('Error convirtiendo HEIC. Intenta otra imagen.', 'error');
                this.value = '';
            }
        },
        true, // capture phase: corremos ANTES que Livewire
    );
}

// Auto-hook: observa el DOM para cualquier input.heic-aware que aparezca
// (Livewire renderiza modales on-demand, no podemos depender de DOMContentLoaded).
function autoAttach() {
    document.querySelectorAll('input[data-heic-aware]').forEach(attachHeicConverter);
}

// Run on initial load + cada vez que Livewire renderiza.
document.addEventListener('DOMContentLoaded', autoAttach);
document.addEventListener('livewire:initialized', autoAttach);
document.addEventListener('livewire:navigated', autoAttach);
document.addEventListener('livewire:morph.updated', autoAttach);

// MutationObserver como red de seguridad para modales que aparecen sin disparar
// los hooks de Livewire (ej. dispatch open-modal).
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver(() => autoAttach());
    document.addEventListener('DOMContentLoaded', () => {
        observer.observe(document.body, { childList: true, subtree: true });
    });
}
