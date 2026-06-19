# Recordatorios personales por Telegram — Plan de implementación (Fase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cada usuario del bot de Telegram crea recordatorios personales (una-vez y recurrentes) por comando guiado o lenguaje natural, y el bot se los envía como DM a la hora indicada.

**Architecture:** Tabla `reminders` aislada por `user_id`. Servicio puro `RecurrenceCalculator` para la próxima ocurrencia. Comando `reminders:dispatch` corre por scheduler cada minuto, envía vía el job `SendTelegramMessage` existente y re-agenda los recurrentes. Dos vías de creación: `ReminderHandler` (pasos guiados, sin IA) y tools del agente (`CreateReminderTool`/`ListRemindersTool`/`CancelReminderTool`).

**Tech Stack:** Laravel 11, PHP 8.x, MySQL, PHPUnit/Pest (feature + unit), Carbon, cola de Laravel.

**Scope (Fase 1):** una-vez + recurrente, texto libre, crear/listar/cancelar. NO incluye botones inline (`callback_query`, Fase 2) ni enlace a entidades de negocio (Fase 3).

**Nota MySQL (memoria del proyecto):** NUNCA `migrate:fresh` en MySQL de desarrollo. Solo `php artisan migrate` (la migración es aditiva: tabla nueva).

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---------|-----------------|
| `database/migrations/2026_06_19_120000_create_reminders_table.php` | Esquema tabla `reminders` |
| `app/Models/Reminder.php` | Modelo + scope `forUser` + relación `remindable` |
| `database/factories/ReminderFactory.php` | Factory para tests |
| `app/Services/Reminders/RecurrenceCalculator.php` | Cálculo puro de próxima ocurrencia |
| `app/Console/Commands/DispatchRemindersCommand.php` | Despacho programado |
| `routes/console.php` | Registro del scheduler (modificar) |
| `app/Services/Telegram/ReminderHandler.php` | Flujo guiado `/recordar` + `/recordatorios` |
| `app/Services/Telegram/BotHandler.php` | Routing a ReminderHandler (modificar) |
| `app/Services/Agent/Tools/CreateReminderTool.php` | Crear por lenguaje natural |
| `app/Services/Agent/Tools/ListRemindersTool.php` | Listar por lenguaje natural |
| `app/Services/Agent/Tools/CancelReminderTool.php` | Cancelar por lenguaje natural |
| `app/Providers/AppServiceProvider.php` | Registro de tools (modificar) |
| `tests/Feature/Reminders/*`, `tests/Unit/Reminders/*` | Tests |

---

## Task 1: Tabla, modelo y factory (WP1)

**Files:**
- Create: `database/migrations/2026_06_19_120000_create_reminders_table.php`
- Create: `app/Models/Reminder.php`
- Create: `database/factories/ReminderFactory.php`
- Test: `tests/Feature/Reminders/ReminderModelTest.php`

- [ ] **Step 1: Write the migration**

`database/migrations/2026_06_19_120000_create_reminders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->dateTime('remind_at');
            $table->string('timezone')->default('UTC');
            $table->enum('recurrence', ['none', 'daily', 'weekly', 'monthly', 'custom'])->default('none');
            $table->json('recurrence_rule')->nullable();
            $table->string('remindable_type')->nullable();
            $table->unsignedBigInteger('remindable_id')->nullable();
            $table->enum('status', ['pending', 'sent', 'done', 'cancelled', 'snoozed'])->default('pending');
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->enum('created_via', ['nl', 'command'])->default('command');
            $table->timestamps();

            $table->index(['status', 'remind_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
```

- [ ] **Step 2: Write the model**

`app/Models/Reminder.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'chat_id', 'title', 'body', 'remind_at', 'timezone',
        'recurrence', 'recurrence_rule', 'remindable_type', 'remindable_id',
        'status', 'last_sent_at', 'sent_count', 'created_via',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'recurrence_rule' => 'array',
        'sent_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Aislamiento estricto: solo recordatorios del usuario dado. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== 'none';
    }
}
```

- [ ] **Step 3: Write the factory**

`database/factories/ReminderFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reminder>
 */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_id' => (string) fake()->numberBetween(100000, 999999),
            'title' => fake()->sentence(3),
            'body' => null,
            'remind_at' => now()->addHour(),
            'timezone' => 'UTC',
            'recurrence' => 'none',
            'recurrence_rule' => null,
            'status' => 'pending',
            'sent_count' => 0,
            'created_via' => 'command',
        ];
    }

    public function due(): static
    {
        return $this->state(fn () => ['remind_at' => now()->subMinute()]);
    }

    public function daily(): static
    {
        return $this->state(fn () => ['recurrence' => 'daily']);
    }
}
```

- [ ] **Step 4: Write the failing test**

`tests/Feature/Reminders/ReminderModelTest.php`:

```php
<?php

use App\Models\Reminder;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('scopes reminders to a single user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Reminder::factory()->count(2)->create(['user_id' => $alice->id]);
    Reminder::factory()->create(['user_id' => $bob->id]);

    expect(Reminder::forUser($alice->id)->count())->toBe(2);
    expect(Reminder::forUser($bob->id)->count())->toBe(1);
});

it('casts recurrence_rule to array and detects recurring', function () {
    $reminder = Reminder::factory()->daily()->create([
        'recurrence_rule' => ['days' => [1, 3]],
    ]);

    expect($reminder->recurrence_rule)->toBe(['days' => [1, 3]]);
    expect($reminder->isRecurring())->toBeTrue();
});
```

- [ ] **Step 5: Run migration and tests; verify pass**

Run: `php artisan migrate` then `php artisan test tests/Feature/Reminders/ReminderModelTest.php`
Expected: migración OK; ambos tests PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_19_120000_create_reminders_table.php app/Models/Reminder.php database/factories/ReminderFactory.php tests/Feature/Reminders/ReminderModelTest.php
git commit -m "feat(reminders): tabla, modelo y scope forUser"
```

---

## Task 2: RecurrenceCalculator (WP2)

**Files:**
- Create: `app/Services/Reminders/RecurrenceCalculator.php`
- Test: `tests/Unit/Reminders/RecurrenceCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Reminders/RecurrenceCalculatorTest.php`:

```php
<?php

use App\Services\Reminders\RecurrenceCalculator;
use Carbon\Carbon;

beforeEach(function () {
    $this->calc = new RecurrenceCalculator();
});

it('returns null for non-recurring', function () {
    $from = Carbon::parse('2026-06-19 15:00', 'UTC');
    expect($this->calc->next($from, 'none', null, 'UTC'))->toBeNull();
});

it('advances daily preserving time of day', function () {
    $from = Carbon::parse('2026-06-19 15:00', 'UTC');
    $next = $this->calc->next($from, 'daily', null, 'UTC');
    expect($next->toDateTimeString())->toBe('2026-06-20 15:00:00');
});

it('advances weekly to the next selected weekday', function () {
    // 2026-06-19 is a Friday (isoWeekday 5). Rule = Mondays only (1).
    $from = Carbon::parse('2026-06-19 09:00', 'UTC');
    $next = $this->calc->next($from, 'weekly', ['days' => [1]], 'UTC');
    expect($next->isoFormat('YYYY-MM-DD'))->toBe('2026-06-22'); // Monday
    expect($next->toTimeString())->toBe('09:00:00');
});

it('clamps monthly to end of short month', function () {
    $from = Carbon::parse('2026-01-31 08:00', 'UTC');
    $next = $this->calc->next($from, 'monthly', ['day' => 31], 'UTC');
    expect($next->isoFormat('YYYY-MM-DD'))->toBe('2026-02-28');
});

it('preserves wall-clock time across a non-UTC timezone', function () {
    // America/La_Paz is UTC-4 year-round (no DST). 19:00 UTC = 15:00 local.
    $from = Carbon::parse('2026-06-19 19:00', 'UTC');
    $next = $this->calc->next($from, 'daily', null, 'America/La_Paz');
    // Same wall clock next day: 15:00 La_Paz = 19:00 UTC.
    expect($next->setTimezone('UTC')->toDateTimeString())->toBe('2026-06-20 19:00:00');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Reminders/RecurrenceCalculatorTest.php`
Expected: FAIL — class `App\Services\Reminders\RecurrenceCalculator` not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Reminders/RecurrenceCalculator.php`:

```php
<?php

namespace App\Services\Reminders;

use Carbon\Carbon;

/**
 * Servicio puro: calcula la próxima ocurrencia de un recordatorio recurrente.
 * Recibe y devuelve instantes en UTC; los cálculos de calendario se hacen en
 * la zona horaria del recordatorio para preservar la hora de pared (DST-safe).
 */
class RecurrenceCalculator
{
    /**
     * @param  Carbon  $current  Instante que acaba de dispararse (UTC).
     * @param  string  $recurrence  none|daily|weekly|monthly|custom
     * @param  array<string,mixed>|null  $rule
     * @return Carbon|null  Próxima ocurrencia en UTC, o null si no recurre.
     */
    public function next(Carbon $current, string $recurrence, ?array $rule, string $tz): ?Carbon
    {
        if ($recurrence === 'none') {
            return null;
        }

        $local = $current->copy()->setTimezone($tz);

        $next = match ($recurrence) {
            'daily' => $local->copy()->addDay(),
            'weekly' => $this->nextWeekly($local, $rule),
            'monthly' => $this->nextMonthly($local, $rule),
            'custom' => $local->copy()->addDays(max(1, (int) ($rule['interval_days'] ?? 1))),
            default => null,
        };

        return $next?->setTimezone('UTC');
    }

    private function nextWeekly(Carbon $local, ?array $rule): Carbon
    {
        $days = $rule['days'] ?? [$local->isoWeekday()];
        for ($i = 1; $i <= 7; $i++) {
            $candidate = $local->copy()->addDays($i);
            if (in_array($candidate->isoWeekday(), $days, true)) {
                return $candidate;
            }
        }
        return $local->copy()->addWeek();
    }

    private function nextMonthly(Carbon $local, ?array $rule): Carbon
    {
        $day = (int) ($rule['day'] ?? $local->day);
        $candidate = $local->copy()->addMonthNoOverflow();
        $candidate->day(min($day, $candidate->daysInMonth));
        return $candidate;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Reminders/RecurrenceCalculatorTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reminders/RecurrenceCalculator.php tests/Unit/Reminders/RecurrenceCalculatorTest.php
git commit -m "feat(reminders): RecurrenceCalculator con cobertura DST y fin de mes"
```

---

## Task 3: Comando de despacho + scheduler (WP3)

**Files:**
- Create: `app/Console/Commands/DispatchRemindersCommand.php`
- Modify: `routes/console.php` (agregar bloque al final)
- Test: `tests/Feature/Reminders/DispatchRemindersTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Reminders/DispatchRemindersTest.php`:

```php
<?php

use App\Jobs\SendTelegramMessage;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-06-19 15:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('sends due reminders and marks one-time as sent', function () {
    $user = User::factory()->create();
    $reminder = Reminder::factory()->create([
        'user_id' => $user->id,
        'chat_id' => '555',
        'remind_at' => now()->subMinute(),
        'recurrence' => 'none',
        'status' => 'pending',
    ]);

    $this->artisan('reminders:dispatch')->assertSuccessful();

    Queue::assertPushed(SendTelegramMessage::class, function ($job) {
        return $job->chatId === '555';
    });

    $reminder->refresh();
    expect($reminder->status)->toBe('sent');
    expect($reminder->sent_count)->toBe(1);
});

it('reschedules recurring reminders instead of marking sent', function () {
    $user = User::factory()->create();
    $reminder = Reminder::factory()->daily()->create([
        'user_id' => $user->id,
        'chat_id' => '555',
        'remind_at' => Carbon::parse('2026-06-19 14:00:00'),
        'status' => 'pending',
    ]);

    $this->artisan('reminders:dispatch')->assertSuccessful();

    $reminder->refresh();
    expect($reminder->status)->toBe('pending');
    expect($reminder->remind_at->toDateTimeString())->toBe('2026-06-20 14:00:00');
    expect($reminder->sent_count)->toBe(1);
});

it('does not send reminders that are not due yet', function () {
    $user = User::factory()->create();
    Reminder::factory()->create([
        'user_id' => $user->id,
        'remind_at' => now()->addHour(),
        'status' => 'pending',
    ]);

    $this->artisan('reminders:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('falls back to TelegramUser chat_id when reminder chat_id is null', function () {
    $user = User::factory()->create();
    \App\Models\TelegramUser::create([
        'chat_id' => '999',
        'user_id' => $user->id,
        'identifier' => 'alice',
    ]);
    Reminder::factory()->create([
        'user_id' => $user->id,
        'chat_id' => null,
        'remind_at' => now()->subMinute(),
        'status' => 'pending',
    ]);

    $this->artisan('reminders:dispatch')->assertSuccessful();

    Queue::assertPushed(SendTelegramMessage::class, fn ($job) => $job->chatId === '999');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/DispatchRemindersTest.php`
Expected: FAIL — command `reminders:dispatch` not defined.

- [ ] **Step 3: Write the command**

`app/Console/Commands/DispatchRemindersCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramMessage;
use App\Models\Reminder;
use App\Models\TelegramUser;
use App\Services\Reminders\RecurrenceCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch';
    protected $description = 'Envía los recordatorios vencidos y re-agenda los recurrentes';

    public function handle(RecurrenceCalculator $calc): int
    {
        Reminder::query()
            ->whereIn('status', ['pending', 'snoozed'])
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->chunkById(100, function ($reminders) use ($calc) {
                foreach ($reminders as $reminder) {
                    $this->dispatchOne($reminder, $calc);
                }
            });

        return self::SUCCESS;
    }

    private function dispatchOne(Reminder $reminder, RecurrenceCalculator $calc): void
    {
        $chatId = $reminder->chat_id
            ?: TelegramUser::where('user_id', $reminder->user_id)->value('chat_id');

        if (! $chatId) {
            Log::warning('Reminder sin chat_id resoluble', ['reminder_id' => $reminder->id]);
            return;
        }

        SendTelegramMessage::dispatch((string) $chatId, $this->buildMessage($reminder));

        $next = $calc->next($reminder->remind_at, $reminder->recurrence, $reminder->recurrence_rule, $reminder->timezone);

        $reminder->forceFill([
            'last_sent_at' => now(),
            'sent_count' => $reminder->sent_count + 1,
        ]);

        if ($next) {
            $reminder->remind_at = $next;
            $reminder->status = 'pending';
        } else {
            $reminder->status = 'sent';
        }

        $reminder->save();
    }

    private function buildMessage(Reminder $reminder): string
    {
        $msg = "⏰ <b>Recordatorio</b>\n\n{$reminder->title}";
        if ($reminder->body) {
            $msg .= "\n\n{$reminder->body}";
        }
        return $msg;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/DispatchRemindersTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Register the scheduler**

Agregar al final de `routes/console.php`:

```php
// Recordatorios personales: revisa cada minuto los vencidos y los envía por Telegram.
Schedule::command('reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/DispatchRemindersCommand.php routes/console.php tests/Feature/Reminders/DispatchRemindersTest.php
git commit -m "feat(reminders): comando reminders:dispatch + scheduler cada minuto"
```

---

## Task 4: Flujo guiado /recordar + /recordatorios (WP4)

**Files:**
- Create: `app/Services/Telegram/ReminderHandler.php`
- Modify: `app/Services/Telegram/BotHandler.php` (constructor + routing + comandos)
- Test: `tests/Feature/Reminders/ReminderHandlerTest.php`

Convención de pasos de conversación (igual que `nuevo:*`): `recordar:titulo`,
`recordar:fecha`, `recordar:recurrencia`, `recordar:confirmar`, y `recordatorios:gestionar`.

Formato de fecha aceptado: `DD/MM/YYYY HH:MM` o `DD/MM HH:MM` (año actual).

- [ ] **Step 1: Write the handler**

`app/Services/Telegram/ReminderHandler.php`:

```php
<?php

namespace App\Services\Telegram;

use App\Models\Reminder;
use App\Models\TelegramConversation;
use App\Services\Messaging\TelegramService;
use Carbon\Carbon;

class ReminderHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected BotAuthHandler $auth,
    ) {}

    public function start(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'recordar:titulo',
            'data' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "⏰ <b>Nuevo recordatorio</b>\n\n¿Qué quieres que te recuerde?\n\n(Escribe /cancelar para salir)"
        );
    }

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        match ($conversation->step) {
            'recordar:titulo' => $this->askFecha($chatId, $conversation, $text),
            'recordar:fecha' => $this->askRecurrencia($chatId, $conversation, $text),
            'recordar:recurrencia' => $this->askConfirmar($chatId, $conversation, $text),
            'recordar:confirmar' => $this->finish($chatId, $conversation, $text),
            'recordatorios:gestionar' => $this->cancelByNumber($chatId, $conversation, $text),
            default => null,
        };
    }

    private function askFecha(string $chatId, TelegramConversation $conv, string $text): void
    {
        if ($text === '') {
            $this->telegram->sendMessage($chatId, "❌ Escribe un texto para el recordatorio.");
            return;
        }

        $conv->update([
            'step' => 'recordar:fecha',
            'data' => ['title' => $text],
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📅 ¿Cuándo? Escribe fecha y hora.\n\nEjemplos:\n<code>20/06/2026 15:00</code>\n<code>20/06 15:00</code>"
        );
    }

    private function askRecurrencia(string $chatId, TelegramConversation $conv, string $text): void
    {
        $when = $this->parseFecha($text);
        if (! $when) {
            $this->telegram->sendMessage($chatId, "❌ Formato no válido. Usa <code>DD/MM/YYYY HH:MM</code> (ej. 20/06/2026 15:00).");
            return;
        }
        if ($when->isPast()) {
            $this->telegram->sendMessage($chatId, "❌ Esa fecha ya pasó. Escribe una fecha futura.");
            return;
        }

        $conv->update([
            'step' => 'recordar:recurrencia',
            'data' => array_merge($conv->data, ['remind_at' => $when->toIso8601String()]),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔁 ¿Se repite?\n\n1️⃣ No, una sola vez\n2️⃣ Cada día\n3️⃣ Cada semana (este día)\n4️⃣ Cada mes (este día)\n\nEscribe el número."
        );
    }

    private function askConfirmar(string $chatId, TelegramConversation $conv, string $text): void
    {
        $map = ['1' => 'none', '2' => 'daily', '3' => 'weekly', '4' => 'monthly'];
        $recurrence = $map[trim($text)] ?? null;
        if (! $recurrence) {
            $this->telegram->sendMessage($chatId, "❌ Escribe 1, 2, 3 o 4.");
            return;
        }

        $when = Carbon::parse($conv->data['remind_at']);
        $rule = match ($recurrence) {
            'weekly' => ['days' => [$when->isoWeekday()]],
            'monthly' => ['day' => $when->day],
            default => null,
        };

        $conv->update([
            'step' => 'recordar:confirmar',
            'data' => array_merge($conv->data, [
                'recurrence' => $recurrence,
                'recurrence_rule' => $rule,
            ]),
        ]);

        $repeat = [
            'none' => 'una sola vez',
            'daily' => 'cada día',
            'weekly' => 'cada semana',
            'monthly' => 'cada mes',
        ][$recurrence];

        $this->telegram->sendMessage(
            $chatId,
            "✅ Confirma:\n\n📝 <b>{$conv->data['title']}</b>\n📅 {$when->format('d/m/Y H:i')}\n🔁 {$repeat}\n\n1️⃣ Guardar\n2️⃣ Cancelar"
        );
    }

    private function finish(string $chatId, TelegramConversation $conv, string $text): void
    {
        if (trim($text) !== '1') {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ Recordatorio descartado.");
            return;
        }

        $user = $this->auth->getAuthenticatedUser($chatId);
        if (! $user) {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida. Inicia sesión de nuevo.");
            return;
        }

        Reminder::create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'title' => $conv->data['title'],
            'remind_at' => Carbon::parse($conv->data['remind_at'])->setTimezone('UTC'),
            'timezone' => config('app.timezone'),
            'recurrence' => $conv->data['recurrence'],
            'recurrence_rule' => $conv->data['recurrence_rule'],
            'status' => 'pending',
            'created_via' => 'command',
        ]);

        $conv->delete();
        $this->telegram->sendMessage($chatId, "✅ Recordatorio guardado. Te avisaré a tiempo.");
    }

    /** Lista los recordatorios pendientes del usuario y arma estado para cancelar por número. */
    public function listAndManage(string $chatId): void
    {
        $user = $this->auth->getAuthenticatedUser($chatId);
        if (! $user) {
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida.");
            return;
        }

        $reminders = Reminder::forUser($user->id)
            ->whereIn('status', ['pending', 'snoozed'])
            ->orderBy('remind_at')
            ->limit(20)
            ->get();

        if ($reminders->isEmpty()) {
            $this->telegram->sendMessage($chatId, "📭 No tienes recordatorios pendientes.");
            return;
        }

        $msg = "⏰ <b>Tus recordatorios</b>\n\n";
        $ids = [];
        foreach ($reminders as $idx => $r) {
            $n = $idx + 1;
            $ids[(string) $n] = $r->id;
            $msg .= "{$n}. <b>{$r->title}</b> — {$r->remind_at->format('d/m/Y H:i')}\n";
        }
        $msg .= "\n<i>Escribe el número para cancelar uno, o /cancelar para salir.</i>";

        $conv = TelegramConversation::getOrCreate($chatId);
        $conv->update([
            'step' => 'recordatorios:gestionar',
            'data' => ['ids' => $ids],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function cancelByNumber(string $chatId, TelegramConversation $conv, string $text): void
    {
        $ids = $conv->data['ids'] ?? [];
        $id = $ids[trim($text)] ?? null;
        if (! $id) {
            $this->telegram->sendMessage($chatId, "❌ Número no válido. Escribe uno de la lista o /cancelar.");
            return;
        }

        $user = $this->auth->getAuthenticatedUser($chatId);
        // Doble candado anti-IDOR: solo cancela si es del propio usuario.
        $reminder = Reminder::forUser($user->id)->find($id);
        if (! $reminder) {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "❌ No encontrado.");
            return;
        }

        $reminder->update(['status' => 'cancelled']);
        $conv->delete();
        $this->telegram->sendMessage($chatId, "🗑️ Recordatorio cancelado: <b>{$reminder->title}</b>");
    }

    /** Parsea "DD/MM/YYYY HH:MM" o "DD/MM HH:MM" en la tz de la app. */
    private function parseFecha(string $text): ?Carbon
    {
        $text = trim($text);
        foreach (['d/m/Y H:i', 'd/m H:i'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text, config('app.timezone'));
                if ($date !== false) {
                    return $date;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Reminders/ReminderHandlerTest.php`:

```php
<?php

use App\Models\Reminder;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\BotAuthHandler;
use App\Services\Telegram\ReminderHandler;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-06-19 10:00:00');

    // TelegramService no debe llamar a la API real en tests.
    $this->telegram = Mockery::mock(TelegramService::class);
    $this->telegram->shouldReceive('sendMessage')->andReturnNull();

    $this->user = User::factory()->create();
    TelegramUser::create(['chat_id' => '555', 'user_id' => $this->user->id, 'identifier' => 'alice']);

    $auth = Mockery::mock(BotAuthHandler::class);
    $auth->shouldReceive('getAuthenticatedUser')->with('555')->andReturn($this->user);

    $this->handler = new ReminderHandler($this->telegram, $auth);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('creates a one-time reminder through the guided flow', function () {
    $this->handler->start('555');
    $this->handler->handle('555', ['text' => 'Comprar cajas']);
    $this->handler->handle('555', ['text' => '20/06/2026 15:00']);
    $this->handler->handle('555', ['text' => '1']); // una sola vez
    $this->handler->handle('555', ['text' => '1']); // guardar

    $reminder = Reminder::forUser($this->user->id)->first();
    expect($reminder)->not->toBeNull();
    expect($reminder->title)->toBe('Comprar cajas');
    expect($reminder->recurrence)->toBe('none');
    expect($reminder->remind_at->format('d/m/Y H:i'))->toBe('20/06/2026 15:00');
    expect(TelegramConversation::where('chat_id', '555')->exists())->toBeFalse();
});

it('rejects a past date', function () {
    $this->handler->start('555');
    $this->handler->handle('555', ['text' => 'Algo']);
    $this->handler->handle('555', ['text' => '01/01/2020 10:00']);

    expect(Reminder::count())->toBe(0);
    expect(TelegramConversation::where('chat_id', '555')->first()->step)->toBe('recordar:fecha');
});

it('cancels only the own reminder by number', function () {
    $other = User::factory()->create();
    $foreign = Reminder::factory()->create(['user_id' => $other->id, 'status' => 'pending']);
    $mine = Reminder::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'remind_at' => now()->addDay()]);

    $this->handler->listAndManage('555');
    $this->handler->handle('555', ['text' => '1']);

    expect($mine->fresh()->status)->toBe('cancelled');
    expect($foreign->fresh()->status)->toBe('pending');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Reminders/ReminderHandlerTest.php`
Expected: FAIL — class `App\Services\Telegram\ReminderHandler` not found (si escribiste Step 1 antes, fallará en el wiring de BotHandler aún no hecho; los tests de este archivo no dependen de BotHandler, así que deberían pasar tras Step 1).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Reminders/ReminderHandlerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Wire into BotHandler — constructor**

En `app/Services/Telegram/BotHandler.php`, agregar el handler al constructor (junto a los otros handlers, alrededor de la línea 27):

```php
        protected WhisperService $whisperService,
        protected VisionService $visionService,
        protected ReminderHandler $reminderHandler,
    ) {}
```

(Laravel resuelve la dependencia por autowiring; no requiere binding extra.)

- [ ] **Step 6: Wire into BotHandler — routing de conversación activa**

En `app/Services/Telegram/BotHandler.php`, dentro de `dispatch()`, en el bloque `if ($conversation) { ... }` (después de la rama `agent:active`, alrededor de la línea 210), agregar:

```php
                } elseif (str_starts_with($conversation->step, 'recordar:') || $conversation->step === 'recordatorios:gestionar') {
                    $this->reminderHandler->handle($chatId, $message);
                    return;
                }
```

También incluir `recordar:` y `recordatorios:` en la lista de flujos activos que interceptan `/cancelar`. En el bloque `$isActiveFlow` (alrededor de la línea 132):

```php
                $isActiveFlow = $conversation && (
                    str_starts_with($conversation->step, 'nuevo:') ||
                    str_starts_with($conversation->step, 'venta_rapida:') ||
                    str_starts_with($conversation->step, 'devolver:') ||
                    str_starts_with($conversation->step, 'recordar:') ||
                    $conversation->step === 'recordatorios:gestionar'
                );
```

- [ ] **Step 7: Wire into BotHandler — comandos**

En `handleCommand()` `match` (alrededor de la línea 429), agregar dos comandos:

```php
            '/recordar' => $this->reminderHandler->start($chatId),
            '/recordatorios' => $this->reminderHandler->listAndManage($chatId),
```

Y en `cmdHelp()` agregar al texto de ayuda (después de `/devolver`):

```php
            "/recordar — Crear un recordatorio personal\n" .
            "/recordatorios — Ver y cancelar tus recordatorios\n";
```

- [ ] **Step 8: Smoke-run the bot routing test**

Run: `php artisan test tests/Feature/Reminders/ReminderHandlerTest.php`
Expected: PASS. Además, verificar manualmente que `php artisan test` global no rompe BotHandler (constructor nuevo).

Run: `php artisan test --filter=Telegram`
Expected: sin regresiones.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Telegram/ReminderHandler.php app/Services/Telegram/BotHandler.php tests/Feature/Reminders/ReminderHandlerTest.php
git commit -m "feat(reminders): flujo guiado /recordar y /recordatorios con scope por usuario"
```

---

## Task 5: Tools del agente IA (WP5)

**Files:**
- Create: `app/Services/Agent/Tools/CreateReminderTool.php`
- Create: `app/Services/Agent/Tools/ListRemindersTool.php`
- Create: `app/Services/Agent/Tools/CancelReminderTool.php`
- Modify: `app/Providers/AppServiceProvider.php` (registro)
- Test: `tests/Feature/Reminders/ReminderToolsTest.php`

`AgentContext` ya expone `$context->user` y `$context->chatId`. `CreateReminderTool`
usa `requiresConfirmation() = true` (mecanismo ya existente del agente) para que el
usuario confirme la fecha interpretada antes de guardar — mitiga el riesgo de fecha errónea.

- [ ] **Step 1: Write CreateReminderTool**

`app/Services/Agent/Tools/CreateReminderTool.php`:

```php
<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use Carbon\Carbon;

class CreateReminderTool extends BaseTool
{
    public function name(): string
    {
        return 'create_reminder';
    }

    public function description(): string
    {
        return 'Crea un recordatorio personal para el usuario. Usa la fecha/hora absoluta en ISO 8601 '
            . '(resuelve relativos como "mañana 3pm" usando la fecha actual provista en el contexto). '
            . 'recurrence: none|daily|weekly|monthly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Qué recordar'],
                'remind_at' => ['type' => 'string', 'description' => 'Fecha/hora ISO 8601, ej. 2026-06-20T15:00:00'],
                'recurrence' => [
                    'type' => 'string',
                    'enum' => ['none', 'daily', 'weekly', 'monthly'],
                    'description' => 'Frecuencia de repetición',
                ],
            ],
            'required' => ['title', 'remind_at'],
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function confirmationSummary(array $input): string
    {
        $when = Carbon::parse($input['remind_at'])->format('d/m/Y H:i');
        $repeat = match ($input['recurrence'] ?? 'none') {
            'daily' => ' (cada día)',
            'weekly' => ' (cada semana)',
            'monthly' => ' (cada mes)',
            default => '',
        };
        return "Crear recordatorio: \"{$input['title']}\" para el {$when}{$repeat}";
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        $when = Carbon::parse($input['remind_at'], config('app.timezone'));
        if ($when->isPast()) {
            return ['error' => 'La fecha indicada ya pasó. Pide una fecha futura.'];
        }

        $recurrence = $input['recurrence'] ?? 'none';
        $rule = match ($recurrence) {
            'weekly' => ['days' => [$when->isoWeekday()]],
            'monthly' => ['day' => $when->day],
            default => null,
        };

        $reminder = Reminder::create([
            'user_id' => $context->user->id,
            'chat_id' => $context->chatId,
            'title' => $input['title'],
            'remind_at' => $when->copy()->setTimezone('UTC'),
            'timezone' => config('app.timezone'),
            'recurrence' => $recurrence,
            'recurrence_rule' => $rule,
            'status' => 'pending',
            'created_via' => 'nl',
        ]);

        return [
            'ok' => true,
            'id' => $reminder->id,
            'message' => "Recordatorio guardado para {$when->format('d/m/Y H:i')}.",
        ];
    }
}
```

- [ ] **Step 2: Write ListRemindersTool**

`app/Services/Agent/Tools/ListRemindersTool.php`:

```php
<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class ListRemindersTool extends BaseTool
{
    public function name(): string
    {
        return 'list_reminders';
    }

    public function description(): string
    {
        return 'Lista los recordatorios pendientes del usuario actual.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        $reminders = Reminder::forUser($context->user->id)
            ->whereIn('status', ['pending', 'snoozed'])
            ->orderBy('remind_at')
            ->limit(20)
            ->get();

        return [
            'count' => $reminders->count(),
            'reminders' => $reminders->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'remind_at' => $r->remind_at->format('d/m/Y H:i'),
                'recurrence' => $r->recurrence,
            ])->toArray(),
        ];
    }
}
```

- [ ] **Step 3: Write CancelReminderTool**

`app/Services/Agent/Tools/CancelReminderTool.php`:

```php
<?php

namespace App\Services\Agent\Tools;

use App\Models\Reminder;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class CancelReminderTool extends BaseTool
{
    public function name(): string
    {
        return 'cancel_reminder';
    }

    public function description(): string
    {
        return 'Cancela un recordatorio del usuario por su id (obtenido de list_reminders).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'ID del recordatorio a cancelar'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        // Scope anti-IDOR: solo cancela si pertenece al usuario.
        $reminder = Reminder::forUser($context->user->id)->find($input['id']);
        if (! $reminder) {
            return ['error' => 'Recordatorio no encontrado.'];
        }

        $reminder->update(['status' => 'cancelled']);

        return ['ok' => true, 'message' => "Cancelado: {$reminder->title}"];
    }
}
```

- [ ] **Step 4: Register the tools**

En `app/Providers/AppServiceProvider.php`, dentro del singleton `ToolRegistry` (después de `GetBalanceSheetTool`, alrededor de la línea 47), agregar:

```php
            $registry->register($app->make(\App\Services\Agent\Tools\CreateReminderTool::class));
            $registry->register($app->make(\App\Services\Agent\Tools\ListRemindersTool::class));
            $registry->register($app->make(\App\Services\Agent\Tools\CancelReminderTool::class));
```

- [ ] **Step 5: Write the failing test**

`tests/Feature/Reminders/ReminderToolsTest.php`:

```php
<?php

use App\Models\Reminder;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CancelReminderTool;
use App\Services\Agent\Tools\CreateReminderTool;
use App\Services\Agent\Tools\ListRemindersTool;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-06-19 10:00:00');
    $this->user = User::factory()->create();
    $this->context = new AgentContext($this->user, '555', 'telegram');
});

afterEach(fn () => Carbon::setTestNow());

it('creates a reminder via the tool', function () {
    $result = (new CreateReminderTool())->execute([
        'title' => 'Reunión con proveedor',
        'remind_at' => '2026-06-20T15:00:00',
        'recurrence' => 'none',
    ], $this->context);

    expect($result['ok'])->toBeTrue();
    $reminder = Reminder::forUser($this->user->id)->first();
    expect($reminder->title)->toBe('Reunión con proveedor');
    expect($reminder->created_via)->toBe('nl');
});

it('rejects a past date via the tool', function () {
    $result = (new CreateReminderTool())->execute([
        'title' => 'Tarde',
        'remind_at' => '2020-01-01T10:00:00',
    ], $this->context);

    expect($result)->toHaveKey('error');
    expect(Reminder::count())->toBe(0);
});

it('lists only the own reminders', function () {
    $other = User::factory()->create();
    Reminder::factory()->create(['user_id' => $other->id, 'status' => 'pending']);
    Reminder::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'remind_at' => now()->addDay()]);

    $result = (new ListRemindersTool())->execute([], $this->context);

    expect($result['count'])->toBe(1);
});

it('cannot cancel another users reminder', function () {
    $other = User::factory()->create();
    $foreign = Reminder::factory()->create(['user_id' => $other->id, 'status' => 'pending']);

    $result = (new CancelReminderTool())->execute(['id' => $foreign->id], $this->context);

    expect($result)->toHaveKey('error');
    expect($foreign->fresh()->status)->toBe('pending');
});
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Reminders/ReminderToolsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Agent/Tools/CreateReminderTool.php app/Services/Agent/Tools/ListRemindersTool.php app/Services/Agent/Tools/CancelReminderTool.php app/Providers/AppServiceProvider.php tests/Feature/Reminders/ReminderToolsTest.php
git commit -m "feat(reminders): tools IA crear/listar/cancelar con confirmacion de fecha"
```

---

## Cierre de Fase 1

- [ ] **Suite completa**

Run: `php artisan test`
Expected: toda la suite verde (sin regresiones).

- [ ] **Smoke-test del bot real**

Crear un recordatorio por `/recordar` y otro por lenguaje natural ("recuérdame X mañana 9am"),
esperar/forzar `php artisan reminders:dispatch`, confirmar que llega el DM y que el recurrente
re-agenda. (Regla de memoria del proyecto: probar el flujo real, no solo unit.)

- [ ] **Code review**

Usar `superpowers:requesting-code-review` sobre el branch `feature/telegram-reminders`.

---

## Cobertura del spec (self-review)

| Requisito spec | Task |
|----------------|------|
| R1 personal por usuario | Task 1 (scope `forUser`), usado en Tasks 4/5 |
| R2 crear por lenguaje natural | Task 5 (`CreateReminderTool`) |
| R3 comando guiado `/recordar` | Task 4 |
| R4 una-vez + recurrente | Task 2 (cálculo) + Task 3 (re-agenda) + Tasks 4/5 (captura) |
| R6 entrega DM | Task 3 (`SendTelegramMessage`) |
| R7 listar/cancelar | Task 4 (`/recordatorios`) + Task 5 (tools) |
| R8 aislamiento (anti-IDOR) | scope `forUser` + tests de scope en Tasks 1/4/5 |
| R9 zona horaria/DST | Task 2 (cálculo en tz, test wall-clock) |

**Fuera de alcance (fases siguientes):** R5 enlace a entidades de negocio (Fase 3, WP7);
botones inline Hecho/Posponer (Fase 2, WP6). La columna `status='snoozed'` y la morph
`remindable` ya existen en el esquema (Task 1) para no re-migrar después.
```
