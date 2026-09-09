<?php

namespace Tests\Feature\Backup;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class BackupMonitorScheduleTest extends TestCase
{
    public function test_backup_monitor_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn ($c) => str_contains($c, 'backup:monitor'));

        $this->assertTrue($commands->isNotEmpty(), 'backup:monitor debe estar agendado');
    }
}
