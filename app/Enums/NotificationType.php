<?php

namespace App\Enums;

/**
 * Allowed values of notifications.type (Section 13).
 *
 * The backing value is stored as-is in the string column "type".
 * The recipient policy of each type is defined here once and is consumed by
 * App\Services\NotificationService.
 */
enum NotificationType: string
{
    case CandidateDisconnected = 'candidate_disconnected';
    case CandidateReconnected = 'candidate_reconnected';
    case SuspiciousSession = 'suspicious_session';
    case ExamCompleted = 'exam_completed';
    case BackupCompleted = 'backup_completed';
    case BackupFailed = 'backup_failed';

    /**
     * Permissions ("module.action") a user must hold, ALL of them, to receive this type.
     * Super Admin is always included unless superAdminsOnly() is true (then it is the only audience).
     *
     * @return list<string>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::SuspiciousSession => ['notifications.view', 'anticheat.view'],
            default => ['notifications.view'],
        };
    }

    /**
     * When true, only active Super Admin users receive this type.
     */
    public function superAdminsOnly(): bool
    {
        return match ($this) {
            self::BackupCompleted, self::BackupFailed => true,
            default => false,
        };
    }
}