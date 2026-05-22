<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * @property int $id
     */

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'created_by');
    }

    /**
     * Backwards-compat helper. Antes el role vivía en users.role (enum).
     * Ahora vive en spatie. Mantenemos el método para no romper call sites
     * (controllers, middleware, blade, etc.) — internamente delega a hasRole.
     *
     * isAdmin() = admin OR developer. El Developer hereda toda autoridad
     * admin via Gate::before, pero este helper también lo refleja para
     * consultas explícitas.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['developer', 'admin']);
    }

    public function isDeveloper(): bool
    {
        return $this->hasRole('developer');
    }

    /**
     * Devuelve el rol primario para UI (el primero asignado). Si tiene varios,
     * prioriza developer > admin > staff. Útil para mostrar un badge único.
     */
    public function primaryRole(): ?string
    {
        $names = $this->getRoleNames();
        foreach (['developer', 'admin', 'staff'] as $candidate) {
            if ($names->contains($candidate)) {
                return $candidate;
            }
        }
        return $names->first();
    }

    public function primaryRoleLabel(): string
    {
        return match ($this->primaryRole()) {
            'developer' => 'Desarrollador',
            'admin' => 'Administrador',
            'staff' => 'Staff',
            null => 'Sin rol',
            default => ucfirst((string) $this->primaryRole()),
        };
    }
}
