<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
        'role',
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
            'role' => UserRole::class,
        ];
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'created_by');
    }

    /**
     * isAdmin incluye Developer: el rol técnico hereda todos los permisos de
     * Admin por diseño. Para chequeos específicos de developer usar
     * isDeveloper() explícitamente.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, UserRole::adminGrade(), true);
    }

    public function isDeveloper(): bool
    {
        return $this->role === UserRole::Developer;
    }

    public function hasRole(UserRole|string $role): bool
    {
        $roleEnum = $role instanceof UserRole ? $role : UserRole::from($role);
        return $this->role === $roleEnum;
    }
}
