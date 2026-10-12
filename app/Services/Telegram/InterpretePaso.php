<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Services\Agent\AiTextClient;
use Illuminate\Support\Facades\Log;

/**
 * Pensar un segundo antes de decir "no entendí".
 *
 * Lo determinista resuelve la mayoría: sacar muletillas, singular y plural,
 * parecido de letras. Pero "las cosas para tomar mate" no se parece en letras a
 * "Mates" y cualquier persona sabe que es eso. Para ese hueco está esto.
 *
 * Tres reglas que lo hacen sostenible:
 *   1. Se consulta SOLO cuando lo determinista ya falló. Cada llamada cuesta
 *      plata y segundos de espera de alguien que está mirando el chat.
 *   2. El modelo ELIGE de una lista cerrada; no inventa valores nuevos. Lo que
 *      conteste se verifica contra la lista antes de usarse.
 *   3. Si no hay IA configurada, falla o contesta cualquier cosa, devuelve null
 *      y el flujo sigue como si esto no existiera.
 */
class InterpretePaso
{
    /** Más opciones que esto no entran en un prompt corto ni le sirven al modelo. */
    private const MAX_OPCIONES = 40;

    public function __construct(private AiTextClient $ia) {}

    /**
     * Elige una de las opciones según lo que la persona quiso decir.
     *
     * @param  array<int, string>  $opciones  valores exactos entre los que puede elegir
     * @return string|null  una de las opciones, tal cual vino, o null
     */
    public function elegirOpcion(string $mensaje, string $pregunta, array $opciones): ?string
    {
        $mensaje = trim($mensaje);
        $opciones = array_values(array_unique(array_filter(array_map('trim', $opciones))));

        if ($mensaje === '' || $opciones === []) {
            return null;
        }

        if (! $this->habilitado()) {
            return null;
        }

        $lista = array_slice($opciones, 0, self::MAX_OPCIONES);

        $respuesta = $this->ia->preguntar($this->prompt($mensaje, $pregunta, $lista), 30);

        if ($respuesta === '') {
            return null;
        }

        return $this->opcionQueCoincide($respuesta, $lista);
    }

    /** @param array<int, string> $opciones */
    private function prompt(string $mensaje, string $pregunta, array $opciones): string
    {
        $listado = implode("\n", array_map(fn (string $o) => "- {$o}", $opciones));

        return <<<PROMPT
        Un negocio está cargando un producto nuevo por chat. El sistema preguntó:
        "{$pregunta}"

        La persona respondió:
        "{$mensaje}"

        Estas son las únicas opciones válidas:
        {$listado}

        ¿A cuál de esas opciones se refiere? Respondé SOLO con el texto exacto de
        la opción, sin comillas ni explicaciones. Si ninguna corresponde con
        claridad, respondé exactamente: NINGUNA
        PROMPT;
    }

    /**
     * El modelo puede contestar con otra capitalización, con punto final o con
     * una opción que no estaba. Solo vale lo que coincide de verdad.
     *
     * @param array<int, string> $opciones
     */
    private function opcionQueCoincide(string $respuesta, array $opciones): ?string
    {
        $limpia = mb_strtolower(trim($respuesta, " \t\n\r\0\x0B.\"'«»"));

        if ($limpia === '' || $limpia === 'ninguna') {
            return null;
        }

        foreach ($opciones as $opcion) {
            if (mb_strtolower($opcion) === $limpia) {
                return $opcion;
            }
        }

        Log::info('La IA propuso algo que no estaba en la lista; se ignora', [
            'respuesta' => mb_substr($respuesta, 0, 80),
        ]);

        return null;
    }

    /**
     * Misma llave que el resto del asistente: si la dueña apagó la IA, acá
     * tampoco se gasta.
     */
    private function habilitado(): bool
    {
        return Setting::get('ai_chatbot_enabled') === '1' && $this->ia->disponible();
    }
}
