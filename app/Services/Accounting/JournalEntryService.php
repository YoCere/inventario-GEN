<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
}
