<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class JournalEntryService
{
    /**
     * @param array{
     *     entry_number?: string,
     *     entry_date: string,
     *     accounting_period_id: int,
     *     description?: string|null,
     *     source_type?: string|null,
     *     source_id?: int|null,
     *     created_by: int,
     *     posted_by?: int|null
     * } $payload
     * @param array<int, array{
     *     chart_of_account_id: int,
     *     description?: string|null,
     *     debit_amount?: int,
     *     credit_amount?: int,
     *     reference?: string|null
     * }> $lines
     */
    public function createPostedEntry(array $payload, array $lines): JournalEntry
    {
        $this->validateLines($lines);

        return DB::transaction(function () use ($payload, $lines) {
            $entry = JournalEntry::create([
                'entry_number' => $payload['entry_number'] ?? $this->generateEntryNumber(),
                'entry_date' => $payload['entry_date'],
                'accounting_period_id' => $payload['accounting_period_id'],
                'description' => $payload['description'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'status' => JournalEntryStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $payload['posted_by'] ?? $payload['created_by'],
                'created_by' => $payload['created_by'],
            ]);

            foreach (array_values($lines) as $index => $line) {
                $entry->lines()->create([
                    'chart_of_account_id' => $line['chart_of_account_id'],
                    'line_number' => $index + 1,
                    'description' => $line['description'] ?? null,
                    'debit_amount' => (int) ($line['debit_amount'] ?? 0),
                    'credit_amount' => (int) ($line['credit_amount'] ?? 0),
                    'reference' => $line['reference'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    public function findPostedSourceEntry(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatus::Posted)
            ->whereNull('reversed_entry_id')
            ->first();
    }

    public function reverseEntry(JournalEntry $entry, int $userId, ?string $description = null): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::Posted) {
            throw new RuntimeException('Solo se pueden revertir asientos contabilizados.');
        }

        $existing = JournalEntry::query()
            ->where('reversed_entry_id', $entry->id)
            ->where('status', JournalEntryStatus::Posted)
            ->first();

        if ($existing) {
            return $existing->load('lines');
        }

        $entry->loadMissing('lines');

        $reverseDate = now()->toDateString();
        $period = $this->resolveOpenPeriod($reverseDate);

        $reverseLines = $entry->lines->map(function ($line) {
            return [
                'chart_of_account_id' => $line->chart_of_account_id,
                'description' => $line->description,
                'debit_amount' => (int) $line->credit_amount,
                'credit_amount' => (int) $line->debit_amount,
                'reference' => $line->reference,
            ];
        })->all();

        $reverse = $this->createPostedEntry([
            'entry_date' => $reverseDate,
            'accounting_period_id' => $period->id,
            'description' => $description ?? ('Reverso de asiento ' . $entry->entry_number),
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'created_by' => $userId,
            'posted_by' => $userId,
        ], $reverseLines);

        $reverse->update(['reversed_entry_id' => $entry->id]);
        $entry->update(['status' => JournalEntryStatus::Reversed]);

        return $reverse->fresh('lines');
    }

    /**
     * @param array<int, array{debit_amount?: int, credit_amount?: int}> $lines
     */
    protected function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('El asiento debe tener al menos dos líneas.');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($lines as $line) {
            $debit = (int) ($line['debit_amount'] ?? 0);
            $credit = (int) ($line['credit_amount'] ?? 0);

            if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
                throw new InvalidArgumentException('Cada línea debe tener únicamente débito o crédito.');
            }

            $debitTotal += $debit;
            $creditTotal += $credit;
        }

        if ($debitTotal <= 0 || $creditTotal <= 0) {
            throw new InvalidArgumentException('Los totales débito/crédito deben ser mayores que cero.');
        }

        if ($debitTotal !== $creditTotal) {
            throw new InvalidArgumentException('El asiento no cuadra: débito y crédito son diferentes.');
        }
    }

    protected function generateEntryNumber(): string
    {
        $prefix = 'JRN.' . now()->format('ymd') . '.';
        $latest = JournalEntry::query()
            ->where('entry_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            return $prefix . '0001';
        }

        $last = (int) substr($latest->entry_number, -4);
        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    protected function resolveOpenPeriod(string $entryDate): AccountingPeriod
    {
        $date = Carbon::parse($entryDate)->toDateString();

        $period = AccountingPeriod::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if (!$period) {
            throw new RuntimeException("No existe periodo contable abierto para {$date}.");
        }

        return $period;
    }
}
