<?php

namespace Database\Factories;

use App\Models\Backup;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupFactory extends Factory
{
    protected $model = Backup::class;

    public function definition(): array
    {
        return [
            'backup_type'         => 'full',
            'file_path'           => 'backups/' . $this->faker->unique()->uuid() . '.zip',
            'size_bytes'          => $this->faker->numberBetween(1024, 10485760),
            'status'              => 'completed',
            'started_at'          => now()->subMinutes(5),
            'completed_at'        => now(),
            'initiated_by'        => null,
            'triggered_by'        => 'manual',
            'checksum'            => hash('sha256', (string) $this->faker->unique()->uuid()),
            'storage_location'    => 'local',
            'failure_reason'      => null,
            'retention_until'     => now()->addDays(30),
            'download_token'      => null,
            'download_expires_at' => null,
            'email_sent_at'       => null,
            'email_error'         => null,
        ];
    }
}
