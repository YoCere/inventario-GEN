<?php

namespace App\Livewire\Customers;

use Exception;
use Livewire\Component;
use App\Models\Customer;
use App\DTOs\CustomerData;
use Livewire\Attributes\On;
use App\Services\CustomerService;

class CustomerForm extends Component
{
    public ?Customer $customer = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $notes = '';

    public string $doc_type = '';

    public string $doc_number = '';

    public string $doc_complement = '';

    public string $business_name = '';

    public bool $isEditing = false;

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email,' . ($this->customer?->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'doc_type' => ['nullable', 'string'],
            'doc_number' => ['nullable', 'string', 'max:20'],
            'doc_complement' => ['nullable', 'string', 'max:5'],
            'business_name' => ['nullable', 'string', 'max:240'],
        ];
    }

    public function render()
    {
        return view('livewire.customers.customer-form');
    }

    #[On('create-customer')]
    public function create(): void
    {
        $this->reset();
        $this->isEditing = false;
        $this->dispatch('open-modal', name: 'customer-modal');
    }

    #[On('edit-customer')]
    public function edit(Customer $customer): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->resetValidation();
        $this->customer = $customer;
        $this->name = $customer->name;
        $this->email = $customer->email ?? '';
        $this->phone = $customer->phone ?? '';
        $this->address = $customer->address ?? '';
        $this->notes = $customer->notes ?? '';
        $this->doc_type = $customer->doc_type ?? '';
        $this->doc_number = $customer->doc_number ?? '';
        $this->doc_complement = $customer->doc_complement ?? '';
        $this->business_name = $customer->business_name ?? '';

        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'customer-modal');
    }

    public function save(CustomerService $service): void
    {
        if ($this->isEditing) {
            abort_if(!auth()->user()->isAdmin(), 403);
        }

        $validated = $this->validate($this->rules());

        try {
            $customerData = CustomerData::fromArray($validated);

            if ($this->isEditing && $this->customer) {
                $service->updateCustomer($this->customer, $customerData);
                $message = 'Cliente actualizado correctamente.';
            } else {
                $service->createCustomer($customerData);
                $message = 'Cliente creado correctamente.';
            }

            $this->dispatch('close-modal', name: 'customer-modal');
            $this->dispatch('pg:eventRefresh-default');
            $this->dispatch('pg:eventRefresh-customer-table');
            $this->dispatch('toast', message: $message, type: 'success');
            $this->reset();

        } catch (Exception $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}