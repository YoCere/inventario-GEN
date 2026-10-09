<?php

namespace App\Support;

/**
 * Teclado en línea de Telegram: los botones que la persona toca dentro del chat.
 *
 * Existe para que cada flujo del bot arme sus botones igual y no haya arrays
 * sueltos con la forma exacta que pide la API repartidos por los handlers.
 *
 * El dato que viaja al tocar un botón se escribe siempre como
 * "<flujo>:<accion>[:<detalle>]" (ej: "prod:guardar", "prod:campo:nombre").
 * BotHandler usa el primer tramo para saber a qué handler mandárselo, así que
 * un flujo nuevo solo necesita elegir su prefijo.
 *
 * Ejemplo:
 *   TelegramKeyboard::make()
 *       ->row(['Guardar' => 'prod:guardar', 'Editar' => 'prod:editar'])
 *       ->button('Volver', 'prod:volver')
 *       ->toArray();
 */
final class TelegramKeyboard
{
    /** Telegram rechaza el botón si el dato supera los 64 bytes. */
    private const MAX_DATA_BYTES = 64;

    /** @var array<int, array<int, array{text: string, callback_data: string}>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * Agrega una fila con uno o varios botones, como ['Texto visible' => 'dato'].
     * Varios botones en la misma fila quedan lado a lado en el celular.
     *
     * @param array<string, string> $buttons
     */
    public function row(array $buttons): self
    {
        $row = [];

        foreach ($buttons as $text => $data) {
            if (strlen($data) > self::MAX_DATA_BYTES) {
                throw new \InvalidArgumentException(
                    "El dato del botón \"{$text}\" supera los " . self::MAX_DATA_BYTES . ' bytes que acepta Telegram.'
                );
            }

            $row[] = ['text' => (string) $text, 'callback_data' => $data];
        }

        if ($row !== []) {
            $this->rows[] = $row;
        }

        return $this;
    }

    /**
     * Un botón solo en su propia fila. Para menús de varias opciones conviene
     * esta forma: en pantalla de celular es mucho más fácil de acertar.
     */
    public function button(string $text, string $data): self
    {
        return $this->row([$text => $data]);
    }

    /** @return array<int, array<int, array{text: string, callback_data: string}>> */
    public function toArray(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
