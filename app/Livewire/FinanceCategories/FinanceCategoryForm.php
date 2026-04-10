<?php

namespace App\Livewire\FinanceCategories;

use Livewire\Component;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use App\Models\FinanceCategory;
use Illuminate\Validation\Rule;
use App\DTOs\FinanceCategoryData;
use App\Enums\FinanceCategoryType;
use App\Services\FinanceCategoryService;
use App\Exceptions\FinanceCategoryException;

class FinanceCategoryForm extends Component
{
    public bool $isEditing = false;
    public ?FinanceCategory $category = null;

    public string $name = '';
    public string $type = 'expense';
    public string $description = '';

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_categories', 'name')->ignore($this->category?->id),
            ],
            'type' => ['required', Rule::enum(FinanceCategoryType::class)],
            'description' => ['nullable', 'string'],
        ];
    }

    public function render()
    {
        return view('livewire.finance-categories.finance-category-form');
    }

    #[On('create-finance-category')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'type', 'description', 'category', 'isEditing']);
        $this->type = FinanceCategoryType::Expense->value;
        $this->dispatch('open-modal', name: 'finance-category-form-modal');
    }

    #[On('edit-finance-category')]
    public function edit(FinanceCategory $category): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->category = $category;
        $this->name = $category->name;
        $this->type = $category->type->value;
        $this->description = $category->description ?? '';
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'finance-category-form-modal');
    }

    public function save(FinanceCategoryService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->validate();

        if ($this->isEditing && $this->category && in_array($this->category->name, ['Product Sales', 'Product Purchases'])) {
            $this->dispatch('toast', ['message' => 'Las categorías del sistema no se pueden editar.', 'type' => 'error']);
            return;
        }

        $slug = Str::slug($this->name);

        $data = new FinanceCategoryData(
            name: $this->name,
            slug: $slug,
            type: FinanceCategoryType::from($this->type),
            description: $this->description,
        );

        try {
            if ($this->isEditing && $this->category) {
                $service->updateCategory($this->category, $data);
                $message = 'Categoría financiera actualizada correctamente.';
            } else {
                $service->createCategory($data);
                $message = 'Categoría financiera creada correctamente.';
            }

            $this->dispatch('close-modal', name: 'finance-category-form-modal');
            $this->dispatch('pg:eventRefresh-finance-category-table');
            $this->dispatch('toast', message: $message, type: 'success');
        } catch (FinanceCategoryException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ocurrió un error inesperado.', type: 'error');
        }
    }
}