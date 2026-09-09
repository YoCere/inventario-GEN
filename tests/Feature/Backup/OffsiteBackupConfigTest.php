<?php

namespace Tests\Feature\Backup;

use Tests\TestCase;

class OffsiteBackupConfigTest extends TestCase
{
    public function test_offsite_disk_is_a_backup_destination(): void
    {
        $this->assertContains('backups_offsite', config('backup.backup.destination.disks'));
        $this->assertContains('backups', config('backup.backup.destination.disks'));
    }

    public function test_continue_on_failure_is_true(): void
    {
        $this->assertTrue(config('backup.backup.destination.continue_on_failure'));
    }

    public function test_offsite_disk_throws_on_write_failure(): void
    {
        $this->assertTrue(config('filesystems.disks.backups_offsite.throw'));
        $this->assertSame('s3', config('filesystems.disks.backups_offsite.driver'));
    }

    public function test_monitor_covers_offsite_disk(): void
    {
        $this->assertContains('backups_offsite', config('backup.monitor_backups.0.disks'));
    }
}
