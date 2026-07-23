<?php

namespace App\DTOs;

class CustomerData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly ?string $notes,
        public readonly ?string $docType = null,
        public readonly ?string $docNumber = null,
        public readonly ?string $docComplement = null,
        public readonly ?string $businessName = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: empty($data['email']) ? null : $data['email'],
            phone: empty($data['phone']) ? null : $data['phone'],
            address: empty($data['address']) ? null : $data['address'],
            notes: empty($data['notes']) ? null : $data['notes'],
            docType: empty($data['doc_type']) ? null : $data['doc_type'],
            docNumber: empty($data['doc_number']) ? null : $data['doc_number'],
            docComplement: empty($data['doc_complement']) ? null : $data['doc_complement'],
            businessName: empty($data['business_name']) ? null : $data['business_name'],
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'doc_type' => $this->docType,
            'doc_number' => $this->docNumber,
            'doc_complement' => $this->docComplement,
            'business_name' => $this->businessName,
        ];
    }
}
