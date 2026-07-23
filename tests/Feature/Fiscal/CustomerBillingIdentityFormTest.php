<?php

namespace Tests\Feature\Fiscal;

use App\Livewire\Customers\CustomerForm;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerBillingIdentityFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_customer_form_saves_identity(): void
    {
        Livewire::test(CustomerForm::class)
            ->set('name', 'Juan')
            ->set('doc_type', '1')
            ->set('doc_number', '5115889')
            ->set('business_name', 'Juan SRL')
            ->call('save');

        $c = Customer::where('doc_number', '5115889')->firstOrFail();
        $this->assertTrue($c->hasBillingIdentity());
    }
}
