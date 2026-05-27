<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Memoria persistente del bot — lo que la IA aprende con el uso.
 *
 * Tipos actuales:
 *  - 'vision': descripción de imagen (normalizada) → producto
 *
 * Flujo de aprendizaje:
 *  1. IA reranquea resultados de visión y llega a un único producto → auto-save.
 *  2. Usuario elige/confirma un producto desde búsqueda visual → source='user_confirmed'.
 *  3. Próxima búsqueda visual similar: se consulta aquí PRIMERO, evitando la llamada API.
 */
class BotKnowledge extends Model
{
    protected $table = 'bot_knowledge';

    protected $fillable = [
        'type', 'key', 'product_id', 'source', 'hits', 'meta', 'last_used_at',
    ];

    protected $casts = [
        'meta'         => 'array',
        'last_used_at' => 'datetime',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Vision helpers ───────────────────────────────────────────────────────

    /**
     * Busca en memoria un producto previamente aprendido para esta descripción visual.
     * Registra el hit y actualiza last_used_at.
     *
     * @return int|null product_id o null si no hay entrada o el producto fue eliminado
     */
    public static function findProductForVision(string $key): ?int
    {
        $entry = self::where('type', 'vision')
            ->where('key', static::normalizeKey($key))
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $entry) {
            return null;
        }

        // Registrar uso
        $entry->increment('hits');
        $entry->update(['last_used_at' => now()]);

        Log::info('BotKnowledge: vision cache hit', [
            'key'        => $key,
            'product_id' => $entry->product_id,
            'hits'       => $entry->hits + 1,
            'source'     => $entry->source,
        ]);

        return $entry->product_id;
    }

    /**
     * Guarda o actualiza un aprendizaje de búsqueda visual.
     * Si ya existe la clave y la nueva fuente es más confiable (user_confirmed > auto),
     * actualiza la fuente sin reducirla.
     */
    public static function rememberVision(
        string $key,
        int $productId,
        bool $confirmed = false,
        ?string $fullDescription = null
    ): void {
        $normalized = static::normalizeKey($key);
        $source     = $confirmed ? 'user_confirmed' : 'auto';

        $existing = self::where('type', 'vision')->where('key', $normalized)->first();

        if ($existing) {
            // No bajar la confianza: confirmed no puede retroceder a auto
            $newSource = ($existing->source === 'user_confirmed') ? 'user_confirmed' : $source;
            $existing->update([
                'product_id'   => $productId,
                'source'       => $newSource,
                'last_used_at' => now(),
                'meta'         => array_merge($existing->meta ?? [], array_filter([
                    'description' => $fullDescription,
                ])),
            ]);
            return;
        }

        self::create([
            'type'         => 'vision',
            'key'          => $normalized,
            'product_id'   => $productId,
            'source'       => $source,
            'hits'         => 0,
            'last_used_at' => now(),
            'meta'         => $fullDescription ? ['description' => $fullDescription] : null,
        ]);

        Log::info('BotKnowledge: vision entry saved', [
            'key'        => $normalized,
            'product_id' => $productId,
            'source'     => $source,
        ]);
    }

    // ─── Normalization ────────────────────────────────────────────────────────

    /**
     * Normaliza la clave para búsqueda: minúsculas, sin acentos, sin puntuación.
     */
    public static function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key));
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;
        $key = preg_replace('/[^a-z0-9\s]/i', '', $key);
        return trim(preg_replace('/\s+/', ' ', $key));
    }
}
