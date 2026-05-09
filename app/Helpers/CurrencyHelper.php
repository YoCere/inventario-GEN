<?php

use App\Models\Setting;

if (!function_exists('format_money')) {
    /**
     * Format a number into currency based on application settings.
     *
     * @param float|int $amount
     * @return string
     */
    function format_money($amount)
    {
        // Get settings, defaulting to Bs format
        $symbol = Setting::get('currency_symbol', 'Bs');
        $position = Setting::get('currency_position', 'right'); // 'left' or 'right'
        $fractions = (int) Setting::get('currency_fraction_digits', 2);
        $thousand = Setting::get('currency_thousand_separator', '.');
        $decimal = Setting::get('currency_decimal_separator', ',');

        // Amount stored in cents, convert to currency units by dividing by 100
        $formattedAmount = number_format((float) $amount / 100, $fractions, $decimal, $thousand);

        if ($position === 'left') {
            return "{$symbol} {$formattedAmount}";
        }

        if ($position === 'right') {
            return "{$formattedAmount} {$symbol}";
        }

        // Fallback
        return "{$symbol} {$formattedAmount}";
    }
}
