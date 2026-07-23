<?php

namespace App\DTOs;

class UnitData
{
    public function __construct(
        public readonly string $name,
        public readonly string $symbol,
        public readonly ?string $sin_code = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            symbol: $data['symbol'],
            sin_code: empty($data['sin_code']) ? null : $data['sin_code'],
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'symbol' => $this->symbol,
            'sin_code' => $this->sin_code,
        ];
    }
}
