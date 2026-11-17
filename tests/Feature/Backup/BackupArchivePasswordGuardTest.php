<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\BackupSafeRunCommand;
use App\Jobs\SendTelegramMessage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Sin BACKUP_ARCHIVE_PASSWORD el zip viaja SIN cifrar hasta un tercero.
 * Preferimos no tener respaldo a tener uno en claro en manos ajenas: el
 * respaldo no debe correr y tiene que avisar por el canal que ya existe.
 */
class BackupArchivePasswordGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Simula un cliente con off-site provisionado (bucket B2 cargado). */
    private function conOffsiteConfigurado(): void
    {
        config([
            'backup.backup.destination.disks' => ['backups', 'backups_offsite'],
            'filesystems.disks.backups_offsite.bucket' => 'inv-backups-acme',
        ]);
    }

    private function conTelegramListo(): void
    {
        Setting::set('telegram_enabled', '1');
        Setting::set('telegram_admin_chat_id', '12345');
    }

    public function test_sin_password_y_con_offsite_el_respaldo_no_corre(): void
    {
        $this->conOffsiteConfigurado();
        config(['backup.backup.password' => null]);

        $this->assertNotNull(
            BackupSafeRunCommand::motivoDeBloqueo(),
            'Con off-site activo y sin password de cifrado el respaldo debe bloquearse'
        );
    }

    public function test_sin_password_y_con_offsite_el_comando_falla_y_avisa(): void
    {
        Bus::fake();
        $this->conOffsiteConfigurado();
        $this->conTelegramListo();
        config(['backup.backup.password' => '']);

        $this->artisan('backup:safe-run')->assertExitCode(1);

        Bus::assertDispatchedSync(
            SendTelegramMessage::class,
            fn (SendTelegramMessage $j) => $j->chatId === '12345'
                && str_contains($j->message, 'BACKUP_ARCHIVE_PASSWORD')
        );
    }

    public function test_con_password_cargado_no_se_bloquea(): void
    {
        $this->conOffsiteConfigurado();
        config(['backup.backup.password' => 'una-clave-larga']);

        $this->assertNull(BackupSafeRunCommand::motivoDeBloqueo());
    }

    public function test_sin_offsite_el_respaldo_local_corre_igual(): void
    {
        // Solo disco local: el zip no sale del servidor, no exigimos cifrado.
        config([
            'backup.backup.destination.disks' => ['backups'],
            'backup.backup.password' => null,
        ]);

        $this->assertNull(BackupSafeRunCommand::motivoDeBloqueo());
    }

    public function test_el_planificador_usa_el_comando_con_guardia(): void
    {
        $comandos = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '');

        $this->assertTrue(
            $comandos->contains(fn ($c) => str_contains($c, 'backup:safe-run')),
            'El respaldo diario debe pasar por la guardia, no llamar a backup:run pelado'
        );
    }
}
