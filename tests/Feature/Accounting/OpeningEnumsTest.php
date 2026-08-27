<?php

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use Tests\TestCase;

class OpeningEnumsTest extends TestCase
{
    public function test_apertura_cases_exist(): void
    {
        $this->assertSame('apertura', JournalEntryType::Apertura->value);
        $this->assertSame('Apertura', JournalEntryType::Apertura->label());
        $this->assertSame('apertura', VoucherType::Apertura->value);
        $this->assertSame('APERTURA', VoucherType::Apertura->shortLabel());
    }
}
