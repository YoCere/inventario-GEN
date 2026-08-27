<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use Tests\TestCase;

class OpeningBalanceDataTest extends TestCase
{
    public function test_from_array_and_totals(): void
    {
        $d = OpeningBalanceData::fromArray([
            'cash' => 18000000, 'receivable' => 1500000, 'inventory' => 9050000, 'ppe' => 3500000,
            'payable' => 1000000, 'capital' => 31050000,
        ]);

        $this->assertSame(18000000, $d->cash);
        $this->assertSame(0, $d->bank);
        $this->assertSame(32050000, $d->totalAssets());
        $this->assertSame(32050000, $d->totalLiabilitiesAndEquity());
    }
}
