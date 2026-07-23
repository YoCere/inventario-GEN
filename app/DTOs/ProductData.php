<?php

namespace App\DTOs;

class ProductData
{
    public function __construct(
        public readonly int $category_id,
        public readonly int $unit_id,
        public readonly ?string $sku,
        public readonly string $name,
        public readonly int $purchase_price,
        public readonly int $selling_price,
        public readonly int $quantity,
        public readonly int $min_stock,
        public readonly bool $is_active,
        public readonly ?string $description,
        public readonly ?string $notes,
        public readonly ?string $image_path = null,
        public readonly ?int $location_id = null,
        public readonly bool $is_public = true,
        public readonly bool $featured = false,
        public readonly ?string $sin_code = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            category_id: (int) $data['category_id'],
            unit_id: (int) $data['unit_id'],
            sku: !empty($data['sku']) ? $data['sku'] : null,
            name: $data['name'],
            purchase_price: (int) $data['purchase_price'],
            selling_price: (int) $data['selling_price'],
            quantity: (int) ($data['quantity'] ?? 0),
            min_stock: (int) ($data['min_stock'] ?? 0),
            is_active: (bool) ($data['is_active'] ?? true),
            description: empty($data['description']) ? null : $data['description'],
            notes: empty($data['notes']) ? null : $data['notes'],
            image_path: empty($data['image_path']) ? null : $data['image_path'],
            location_id: empty($data['location_id']) ? null : (int) $data['location_id'],
            is_public: (bool) ($data['is_public'] ?? true),
            featured: (bool) ($data['featured'] ?? false),
            sin_code: empty($data['sin_code']) ? null : $data['sin_code'],
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->category_id,
            'unit_id' => $this->unit_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'purchase_price' => $this->purchase_price,
            'selling_price' => $this->selling_price,
            'quantity' => $this->quantity,
            'min_stock' => $this->min_stock,
            'is_active' => $this->is_active,
            'description' => $this->description,
            'notes' => $this->notes,
            'image_path' => $this->image_path,
            'location_id' => $this->location_id,
            'is_public' => $this->is_public,
            'featured' => $this->featured,
            'sin_code' => $this->sin_code,
        ];
    }
}
