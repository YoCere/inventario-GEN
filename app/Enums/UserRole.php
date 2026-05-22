<?php

namespace App\Enums;

enum UserRole: string
{
    case Developer = 'developer';
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Developer => 'Desarrollador',
            self::Admin => 'Administrador',
            self::Staff => 'Staff',
        };
    }

    /**
     * Roles que cuentan como "admin-grade" para gates existentes en código
     * (isAdmin() ya delega a esto, route middleware 'admin' también).
     * Developer hereda todo lo de Admin por diseño.
     */
    public static function adminGrade(): array
    {
        return [self::Developer, self::Admin];
    }
}
