<?php

namespace Tests\Feature\Backup;

use Tests\TestCase;

/**
 * El zip del respaldo se sube a un tercero (bucket B2). Si adentro viaja el .env,
 * un solo archivo entrega el APP_KEY (que descifra los secretos de `settings`),
 * el usuario/clave de MySQL y las credenciales del propio bucket.
 */
class BackupSecretsExclusionTest extends TestCase
{
    private function exclusiones(): array
    {
        return config('backup.backup.source.files.exclude');
    }

    public function test_env_is_excluded_from_backup(): void
    {
        $this->assertContains(
            base_path('.env'),
            $this->exclusiones(),
            'El .env NO debe viajar dentro del zip: lleva el APP_KEY y las credenciales'
        );
    }

    public function test_env_variants_are_excluded(): void
    {
        // Spatie compara archivo por archivo (FileSelection::shouldExclude descarta
        // la coincidencia por prefijo cuando lo excluido es un archivo), asi que
        // excluir `.env` NO tapa a `.env.backup` ni a `.env.production`.
        foreach (['.env.backup', '.env.production'] as $variante) {
            $this->assertContains(
                base_path($variante),
                $this->exclusiones(),
                "{$variante} tambien lleva secretos y debe quedar fuera del zip"
            );
        }
    }

    public function test_auth_json_is_excluded(): void
    {
        $this->assertContains(
            base_path('auth.json'),
            $this->exclusiones(),
            'auth.json guarda tokens de Composer/repos privados'
        );
    }
}
