<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * خروجی استاندارد Audit Log.
 * فیلد user_agent (سنگین) فقط در endpoint جزئیات برگردانده می‌شود.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'user'        => $this->whenLoaded('user', fn () => $this->user ? [
                'id'       => $this->user->id,
                'name'     => $this->user->name,
                'username' => $this->user->username,
            ] : null),
            'role'        => $this->role,
            'action'      => $this->action,
            'module'      => $this->module,
            'target_type' => $this->target_type,
            'target_id'   => $this->target_id,
            'old_value'   => $this->old_value,
            'new_value'   => $this->new_value,
            'ip_address'  => $this->ip_address,
            'device'      => $this->device,
            // فقط در show (وجود route parameter) نمایش داده می‌شود
            'user_agent'  => $this->when($request->route('auditLog') !== null, $this->user_agent),
            // ذخیره‌سازی UTC/Gregorian — تبدیل تقویم در لایهٔ نمایش (بند ۶۰.۳)
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}