<?php

namespace App\DTOs;

/**
 * Rubros del asiento de apertura, todos en centavos-int.
 * Activos: cash, bank, receivable, inventory, ppe.
 * Contra-activo: accumulated_depreciation (resta del activo).
 * Pasivos: payable, loans. Patrimonio: capital.
 */
class OpeningBalanceData
{
    public function __construct(
        public readonly int $cash = 0,
        public readonly int $bank = 0,
        public readonly int $receivable = 0,
        public readonly int $inventory = 0,
        public readonly int $ppe = 0,
        public readonly int $accumulated_depreciation = 0,
        public readonly int $payable = 0,
        public readonly int $loans = 0,
        public readonly int $capital = 0,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            cash: (int) ($d['cash'] ?? 0),
            bank: (int) ($d['bank'] ?? 0),
            receivable: (int) ($d['receivable'] ?? 0),
            inventory: (int) ($d['inventory'] ?? 0),
            ppe: (int) ($d['ppe'] ?? 0),
            accumulated_depreciation: (int) ($d['accumulated_depreciation'] ?? 0),
            payable: (int) ($d['payable'] ?? 0),
            loans: (int) ($d['loans'] ?? 0),
            capital: (int) ($d['capital'] ?? 0),
        );
    }

    /** Activo neto = activos brutos - depreciacion acumulada. */
    public function totalAssets(): int
    {
        return $this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe
            - $this->accumulated_depreciation;
    }

    public function totalLiabilitiesAndEquity(): int
    {
        return $this->payable + $this->loans + $this->capital;
    }

    /** Capital que hace cuadrar el asiento (plug). */
    public function balancingCapital(): int
    {
        return ($this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe)
            - $this->accumulated_depreciation - $this->payable - $this->loans;
    }
}
