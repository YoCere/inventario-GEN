<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum FinanceCategoryType: string
{
    case Expense = 'expense';
    case Income = 'income';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::Income => 'Income',
        };
    }

    /** @deprecated Usa <x-status-badge> o Tone::badge($this->tone()). */
    public function color(): string
    {
        return Tone::badge($this->tone());
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Expense => Tone::DANGER,
            self::Income => Tone::SUCCESS,
        };
    }
}
