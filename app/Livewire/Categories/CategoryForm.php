<?php

namespace App\Livewire\Categories;

use Livewire\Component;
use App\Models\Category;
use App\DTOs\CategoryData;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Illuminate\Validation\Rule;
use App\Services\CategoryService;
use App\Exceptions\CategoryException;

class CategoryForm extends Component
{
    public bool $isEditing = false;
    public ?Category $category = null;

    public string $name = '';

    public string $description = '';

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')->ignore($this->category?->id),
            ],
            'description' => ['nullable', 'string'],
        ];
    }

    public function render()
    {
        return view('livewire.categories.category-form');
    }

    #[On('create-category')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'description', 'category', 'isEditing']);
        $this->dispatch('open-modal', name: 'category-form-modal');
    }

    #[On('edit-category')]
    public function edit(Category $category): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->category = $category;
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'category-form-modal');
    }

    public function save(CategoryService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $validated = $this->validate();

        $slug = Str::slug(str_replace('&', '', $this->name));

        $validated['slug'] = $slug;
        $data = CategoryData::fromArray($validated);

        try {
            if ($this->isEditing && $this->category) {
                $service->updateCategory($this->category, $data);
                $message = 'Categoría actualizada correctamente.';
            } else {
                $service->createCategory($data);
                $message = 'Categoría creada correctamente.';
            }

            $this->dispatch('close-modal', name: 'category-form-modal');
            $this->dispatch('pg:eventRefresh-category-table');
            $this->dispatch('toast', message: $message, type: 'success');
        } catch (CategoryException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ocurrió un error inesperado.', type: 'error');
        }
    }
}
