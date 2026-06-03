<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use RuntimeException;

class ChartOfAccountService
{
    /**
     * Crea una nueva cuenta contable aplicando las reglas de integridad.
     *
     * Reglas:
     *  - Código único (lanza RuntimeException si ya existe).
     *  - Si tiene parent_id: el tipo de cuenta del hijo debe coincidir con el padre.
     *  - Si el padre tiene allows_posting=true y ya tiene movimientos → rechaza.
     *  - Si el padre tiene allows_posting=true pero SIN movimientos → lo voltea a false.
     *  - level = parent.level + 1 si tiene padre, 1 si es raíz.
     *
     * @param  array<string, mixed> $data
     * @throws RuntimeException
     */
    public function create(array $data): ChartOfAccount
    {
        // Regla 1: Código único
        if (ChartOfAccount::where('code', $data['code'])->exists()) {
            throw new RuntimeException(
                "El código '{$data['code']}' ya existe en el plan de cuentas."
            );
        }

        // Regla 2-4: validar padre y derivar level
        if (!empty($data['parent_id'])) {
            $parent = ChartOfAccount::findOrFail($data['parent_id']);

            // Regla 3: tipo de cuenta debe coincidir
            $childType = $data['account_type'] instanceof \App\Enums\AccountType
                ? $data['account_type']
                : \App\Enums\AccountType::from($data['account_type']);

            if ($parent->account_type !== $childType) {
                throw new RuntimeException(
                    "El tipo de cuenta del hijo ({$childType->label()}) debe coincidir con el tipo del padre ({$parent->account_type->label()})."
                );
            }

            // Regla 2: si el padre es imputable, verificar si tiene movimientos
            if ($parent->allows_posting) {
                if ($this->hasMovements($parent)) {
                    throw new RuntimeException(
                        "La cuenta padre ya tiene movimientos; no puede agrupar sub-cuentas."
                    );
                }
                // Padre imputable sin movimientos → voltearlo a no-imputable
                $parent->update(['allows_posting' => false]);
            }

            // Regla 4: derivar level
            $data['level'] = $parent->level + 1;
        } else {
            $data['level'] = 1;
        }

        return ChartOfAccount::create($data);
    }

    /**
     * Actualiza una cuenta contable.
     *
     * Si la cuenta ya tiene movimientos, solo se permiten cambiar:
     *   name, description, is_active.
     * Los campos estructurales (code, account_type, normal_balance, allows_posting,
     * parent_id, level) se ignoran silenciosamente.
     *
     * Si la cuenta NO tiene movimientos, se aplica una actualización completa con reglas:
     *   - Si data.allows_posting == true y la cuenta ya tiene hijos → rechaza.
     *
     * @param  array<string, mixed> $data
     * @throws RuntimeException
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        if ($this->hasMovements($account)) {
            // Solo campos seguros
            $safeData = array_intersect_key($data, array_flip(['name', 'description', 'is_active']));
            $account->update($safeData);
        } else {
            // Validar: si se quiere marcar como imputable pero ya tiene hijos → rechazar
            $allowsPosting = $data['allows_posting'] ?? $account->allows_posting;
            if ($allowsPosting && $account->children()->exists()) {
                throw new RuntimeException(
                    "Una cuenta con sub-cuentas no puede ser imputable."
                );
            }

            $account->update($data);
        }

        return $account->fresh();
    }

    /**
     * Activa o desactiva una cuenta.
     */
    public function setActive(ChartOfAccount $account, bool $active): ChartOfAccount
    {
        $account->update(['is_active' => $active]);
        return $account->fresh();
    }

    /**
     * Indica si la cuenta ya tiene líneas de asiento asociadas.
     */
    public function hasMovements(ChartOfAccount $account): bool
    {
        return $account->journalEntryLines()->exists();
    }
}
