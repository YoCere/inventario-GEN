<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentMethod;
use Tests\TestCase;

class PaymentMethodSiatTest extends TestCase
{
    public function test_new_cases_exist(): void
    {
        $this->assertSame('qr', PaymentMethod::QR->value);
        $this->assertSame('card', PaymentMethod::CARD->value);
    }

    public function test_siat_code_maps_every_case(): void
    {
        $this->assertSame(1, PaymentMethod::CASH->siatCode());
        $this->assertSame(2, PaymentMethod::CARD->siatCode());
        $this->assertSame(7, PaymentMethod::QR->siatCode());
        $this->assertSame(1, PaymentMethod::TRANSFER->siatCode());

        foreach (PaymentMethod::cases() as $case) {
            $this->assertIsInt($case->siatCode());
        }
    }

    public function test_labels_exist_for_new_cases(): void
    {
        $this->assertNotSame('', PaymentMethod::QR->label());
        $this->assertNotSame('', PaymentMethod::CARD->label());
    }
}
