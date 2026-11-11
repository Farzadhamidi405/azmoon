<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates fan-out notifications: one row per eligible active user.
 *
 * - Never uses Laravel's Notifiable / database channel (custom "notifications" table).
 * - Never creates broadcast rows (notifiable_user_id is always set).
 * - Callers must not put sensitive data in title/body (no failure reasons, paths, secrets).
 * - Exceptions propagate: callers decide whether a notification failure may affect their flow.
 * - Dedupe is best effort: there is no unique index, so two truly concurrent calls may both insert.
 */
class NotificationService
{
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    public const MAX_TITLE_LENGTH = 255;

    public const MAX_BODY_LENGTH = 2000;

    private const INSERT_CHUNK_SIZE = 500;

    /**
     * @param int|null $excludeUserId  user who must not receive it (e.g. the actor of a manual action)
     * @param int|null $dedupeSeconds  skip recipients that already got the same type + related reference
     *                                 within this many seconds (requires relatedType and relatedId)
     *
     * @return int number of rows created
     *
     * @throws InvalidArgumentException on inconsistent arguments
     */
    public function dispatch(
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?int $excludeUserId = null,
        ?int $dedupeSeconds = null,
    ): int {
        if (($relatedType === null) !== ($relatedId === null)) {
            throw new InvalidArgumentException('relatedType and relatedId must be provided together.');
        }

        if ($dedupeSeconds !== null) {
            if ($dedupeSeconds < 1) {
                throw new InvalidArgumentException('dedupeSeconds must be at least 1.');
            }

            if ($relatedType === null) {
                throw new InvalidArgumentException('Dedupe requires relatedType and relatedId.');
            }
        }

        $title = mb_substr(trim($title), 0, self::MAX_TITLE_LENGTH);

        if ($title === '') {
            throw new InvalidArgumentException('Notification title must not be empty.');
        }

        $body = $body === null ? null : trim($body);
        $body = ($body === null || $body === '') ? null : mb_substr($body, 0, self::MAX_BODY_LENGTH);

        $recipientIds = $this->recipientIds($type, $excludeUserId);

        if ($recipientIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($type, $title, $body, $relatedType, $relatedId, $dedupeSeconds, $recipientIds) {
            if ($dedupeSeconds !== null) {
                $alreadyNotified = Notification::query()
                    ->where('type', $type->value)
                    ->where('related_type', $relatedType)
                    ->where('related_id', $relatedId)
                    ->where('created_at', '>=', now()->subSeconds($dedupeSeconds))
                    ->whereIn('notifiable_user_id', $recipientIds)
                    ->pluck('notifiable_user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $recipientIds = array_values(array_diff($recipientIds, $alreadyNotified));
            }

            if ($recipientIds === []) {
                return 0;
            }

            $now = now();

            $rows = array_map(fn (int $userId) => [
                'type' => $type->value,
                'title' => $title,
                'body' => $body,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'is_read' => false,
                'notifiable_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $recipientIds);

            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                Notification::query()->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * Single-query recipient lookup (no per-user hasPermission loop).
     * Active users who are Super Admin, or (unless the type is super-admin-only)
     * whose role holds every permission required by the type.
     *
     * @return list<int>
     */
    private function recipientIds(NotificationType $type, ?int $excludeUserId): array
    {
        $permissions = $type->requiredPermissions();
        $superAdminsOnly = $type->superAdminsOnly();

        $query = User::query()
            ->where('is_active', true)
            ->where(function ($outer) use ($permissions, $superAdminsOnly) {
                $outer->whereIn(
                    'role_id',
                    Role::query()->where('name', self::SUPER_ADMIN_ROLE)->select('id')
                );

                if (! $superAdminsOnly) {
                    $outer->orWhere(function ($holders) use ($permissions) {
                        foreach ($permissions as $permission) {
                            [$module, $action] = explode('.', $permission, 2);

                            $holders->whereExists(function ($sub) use ($module, $action) {
                                $sub->selectRaw('1')
                                    ->from('role_permissions')
                                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                                    ->whereColumn('role_permissions.role_id', 'users.role_id')
                                    ->where('permissions.module', $module)
                                    ->where('permissions.action', $action);
                            });
                        }
                    });
                }
            });

        if ($excludeUserId !== null) {
            $query->where('id', '!=', $excludeUserId);
        }

        return $query->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
