<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory;

    protected $fillable = [
        'backup_type',
        'file_path',
        'size_bytes',
        'status',
        'started_at',
        'completed_at',
        'initiated_by',
        'triggered_by',
        'checksum',
        'storage_location',
        'failure_reason',
        'retention_until',
        'download_token',
        'download_expires_at',
        'email_sent_at',
        'email_error',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes'          => 'integer',
            'started_at'          => 'datetime',
            'completed_at'        => 'datetime',
            'retention_until'     => 'datetime',
            'download_expires_at' => 'datetime',
            'email_sent_at'       => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
