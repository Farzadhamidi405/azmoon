<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backup_type' => $this->backup_type,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'storage_location' => $this->storage_location,
            'failure_reason' => $this->failure_reason,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'retention_until' => $this->retention_until,
            'initiated_by' => $this->initiated_by,
            'initiator_name' => $this->whenLoaded('initiator', fn () => $this->initiator?->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}