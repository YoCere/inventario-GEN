<?php

namespace App\Livewire\Users;

use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\On;
use App\DTOs\UserData;
use App\Services\UserService;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserForm extends Component
{
    public ?User $user = null;
    public bool $isEditing = false;

    public $name;
    public $username;
    public $email;
    public $password;
    public $password_confirmation;
    public string $role = 'staff';

    /**
     * Roles asignables. Excluye 'developer' — solo otro developer puede
     * promover a developer, y eso se gestiona vía CRUD de roles, no aquí.
     * @return array<int,string>
     */
    public function assignableRoles(): array
    {
        $names = Role::query()->pluck('name')->all();

        if (! auth()->user()?->isDeveloper()) {
            $names = array_values(array_filter($names, fn ($n) => $n !== 'developer'));
        }

        return $names;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user?->id)],
            'password' => [$this->isEditing ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in($this->assignableRoles())],
        ];
    }

    #[On('create-user')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['user', 'isEditing', 'name', 'username', 'email', 'password', 'password_confirmation', 'role']);
        $this->resetValidation();
        $this->role = 'staff';
        $this->dispatch('open-modal', name: 'user-form-modal');
    }

    #[On('edit-user')]
    public function edit(User $user): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->user = $user;
        $this->isEditing = true;

        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->role = $user->primaryRole() ?? 'staff';

        $this->dispatch('open-modal', name: 'user-form-modal');
    }

    public function save(UserService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->validate();

        $data = new UserData(
            name: $this->name,
            username: $this->username,
            email: $this->email,
            password: $this->password ?: null,
            role: $this->role,
        );

        try {
            if ($this->isEditing && $this->user) {
                $service->updateUser($this->user, $data);
                $message = 'Usuario actualizado correctamente.';
            } else {
                $service->createUser($data);
                $message = 'Usuario creado correctamente.';
            }

            $this->dispatch('close-modal', name: 'user-form-modal');
            $this->dispatch('pg:eventRefresh-user-table');
            $this->dispatch('toast', message: $message, type: 'success');

            $this->reset(['user', 'isEditing', 'name', 'username', 'email', 'password', 'password_confirmation', 'role']);
            $this->role = 'staff';

        } catch (\Exception $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    public function render()
    {
        return view('livewire.users.user-form');
    }
}
