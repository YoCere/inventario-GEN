<?php

namespace App\Support\Ui;

/**
 * Colores de estado del sistema. Cuatro tonos y nada más: si un estado nuevo no
 * entra en uno de estos, es que no es un estado.
 *
 * Las clases están escritas literales a propósito: Tailwind las descubre leyendo
 * este archivo (ver `content` en tailwind.config.js).
 */
final class Tone
{
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const DANGER = 'danger';
    public const INFO = 'info';
    public const NEUTRAL = 'neutral';

    /** Fondo + texto de la etiqueta. */
    public static function badge(string $tone): string
    {
        return match ($tone) {
            self::SUCCESS => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
            self::WARNING => 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
            self::DANGER => 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
            self::INFO => 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
            default => 'bg-muted text-muted-foreground',
        };
    }

    /** Punto de color que acompaña al texto (el color nunca va solo). */
    public static function dot(string $tone): string
    {
        return match ($tone) {
            self::SUCCESS => 'bg-emerald-600',
            self::WARNING => 'bg-amber-600',
            self::DANGER => 'bg-rose-600',
            self::INFO => 'bg-blue-600',
            default => 'bg-zinc-400',
        };
    }

    /** Solo el texto, para montos y cifras. */
    public static function text(string $tone): string
    {
        return match ($tone) {
            self::SUCCESS => 'text-emerald-700 dark:text-emerald-400',
            self::WARNING => 'text-amber-700 dark:text-amber-400',
            self::DANGER => 'text-rose-700 dark:text-rose-400',
            self::INFO => 'text-blue-700 dark:text-blue-400',
            default => 'text-muted-foreground',
        };
    }

    /**
     * Etiqueta completa lista para usar dentro de una tabla PowerGrid, que arma
     * HTML en PHP y no puede incluir componentes Blade.
     */
    public static function badgeHtml(string $tone, string $label): string
    {
        return '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ' . self::badge($tone) . '">'
            . '<span class="h-1.5 w-1.5 rounded-full ' . self::dot($tone) . '"></span>'
            . e($label)
            . '</span>';
    }
}
