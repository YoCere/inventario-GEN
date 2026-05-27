<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use RuntimeException;

class AccountingPeriod extends Model
{
    use HasFactory;

    protected $table = 'accounting_periods';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'planned_end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'start_date'       => 'date',
        'end_date'         => 'date',
        'planned_end_date' => 'date',
        'status'           => AccountingPeriodStatus::class,
        'closed_at'        => 'datetime',
    ];

    /**
     * True si el periodo fue cerrado antes de su fecha fin planificada.
     */
    public function wasClosedEarly(): bool
    {
        return $this->planned_end_date !== null;
    }

    // ─── Lógica de auto-creación ─────────────────────────────────────────────

    /**
     * Calcula la fecha fin según el tipo de periodo dado un inicio.
     */
    public static function calculateEndDate(string $startDate, string $type): string
    {
        $start = Carbon::parse($startDate);
        return match ($type) {
            'monthly'   => $start->copy()->endOfMonth()->format('Y-m-d'),
            'quarterly' => $start->copy()->addMonths(3)->subDay()->format('Y-m-d'),
            'biannual'  => $start->copy()->addMonths(6)->subDay()->format('Y-m-d'),
            'annual'    => $start->copy()->addYear()->subDay()->format('Y-m-d'),
            default     => $start->copy()->endOfMonth()->format('Y-m-d'),
        };
    }

    /**
     * Genera el nombre automático de un periodo dado inicio y tipo.
     */
    public static function generateName(string $startDate, string $type): string
    {
        $start  = Carbon::parse($startDate);
        $months = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        return match ($type) {
            'monthly'   => $months[$start->month] . ' ' . $start->year,
            'quarterly' => 'T' . (int) ceil($start->month / 3) . ' ' . $start->year,
            'biannual'  => ($start->month <= 6 ? 'S1' : 'S2') . ' ' . $start->year,
            'annual'    => (string) $start->year,
            default     => $start->format('d/m/Y'),
        };
    }

    /**
     * Crea automáticamente el siguiente periodo basándose en el último existente.
     * Usa default_accounting_period_type de ajustes. Garantiza nombre único.
     */
    public static function autoCreateNext(?self $afterPeriod = null): static
    {
        $type  = Setting::get('default_accounting_period_type', 'monthly');
        $start = $afterPeriod
            ? $afterPeriod->end_date->addDay()->format('Y-m-d')
            : now()->startOfMonth()->format('Y-m-d');

        $end      = static::calculateEndDate($start, $type);
        $baseName = static::generateName($start, $type);
        $name     = $baseName;
        $suffix   = 2;

        while (static::where('name', $name)->exists()) {
            $name = $baseName . ' (' . $suffix++ . ')';
        }

        return static::create([
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
            'status'     => AccountingPeriodStatus::Open->value,
        ]);
    }

    // ─── Resolución de periodo activo ─────────────────────────────────────────

    /**
     * Resuelve el periodo contable abierto para una fecha dada.
     *
     * Caso normal: existe un periodo abierto que cubre la fecha → lo retorna.
     *
     * Caso de olvido (auto_create_next_period = true): el periodo abierto ya
     * venció → se cierra automáticamente (cierre de sistema) y se crea el
     * siguiente periodo basado en default_accounting_period_type.
     *
     * Caso de olvido (auto_create = false): se extiende el periodo vencido
     * hasta la fecha solicitada (comportamiento de seguridad mínimo).
     *
     * @throws RuntimeException si no existe ningún periodo abierto.
     */
    public static function resolveOpenForDate(string $date): static
    {
        // Caso normal: periodo abierto que cubre exactamente la fecha
        /** @var static|null $period */
        $period = static::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where('status', AccountingPeriodStatus::Open->value)
            ->orderBy('start_date')
            ->first();

        if ($period) {
            return $period;
        }

        // Fallback: periodo abierto vencido (end_date < fecha solicitada).
        /** @var static|null $expiredOpen */
        $expiredOpen = static::query()
            ->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('end_date', '<', $date)
            ->orderByDesc('end_date')
            ->first();

        if ($expiredOpen) {
            /** @var \App\Services\Accounting\AccountingPeriodAutoCloser $autoCloser */
            $autoCloser = app(\App\Services\Accounting\AccountingPeriodAutoCloser::class);
            return $autoCloser->handleExpired($expiredOpen, $date);
        }

        throw new RuntimeException("No existe un periodo contable abierto para la fecha {$date}.");
    }

    /**
     * Devuelve el estado de alerta del periodo activo para el dashboard.
     * null  → todo OK (o no hay periodo activo, no hay que alertar de "expiry")
     * array → ['level' => 'warning'|'critical', 'message' => '...', 'period' => model]
     */
    public static function dashboardAlert(): ?array
    {
        $today = now()->toDateString();

        // ¿Existe periodo abierto que cubra hoy?
        $active = static::query()
            ->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('start_date')
            ->first();

        if ($active) {
            $daysLeft = (int) now()->diffInDays($active->end_date, false);

            if ($daysLeft <= 0) {
                // end_date = hoy, mañana ya vence
                return [
                    'level'   => 'warning',
                    'message' => "El periodo contable \"{$active->name}\" vence HOY ({$active->end_date->format('d/m/Y')}). Ciérrelo y cree el siguiente antes de mañana.",
                    'period'  => $active,
                ];
            }

            if ($daysLeft <= 7) {
                return [
                    'level'   => 'warning',
                    'message' => "El periodo contable \"{$active->name}\" vence en {$daysLeft} día(s) ({$active->end_date->format('d/m/Y')}). Prepare el siguiente periodo.",
                    'period'  => $active,
                ];
            }

            return null; // Todo OK
        }

        // No hay periodo activo → ¿hay uno vencido sin cerrar?
        $expired = static::query()
            ->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('end_date', '<', $today)
            ->orderByDesc('end_date')
            ->first();

        if ($expired) {
            return [
                'level'   => 'critical',
                'message' => "⚠️ El periodo contable \"{$expired->name}\" venció el {$expired->end_date->format('d/m/Y')} y no fue cerrado. Las ventas se están registrando en él de forma automática. Ciérrelo y cree el periodo actual.",
                'period'  => $expired,
            ];
        }

        // No hay ningún periodo abierto (ni vencido)
        return [
            'level'   => 'critical',
            'message' => '⚠️ No existe ningún periodo contable abierto. Las ventas y compras no podrán registrarse contablemente. Cree un periodo contable de inmediato.',
            'period'  => null,
        ];
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
