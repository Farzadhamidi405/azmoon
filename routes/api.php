<?php
use App\Http\Controllers\Api\V1\Auth\CandidateAuthController;
use App\Http\Controllers\Api\V1\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\AntiCheatController;
use App\Http\Controllers\Api\V1\AttemptController;
use App\Http\Controllers\Api\V1\CandidateController;
use App\Http\Controllers\Api\V1\ComputerController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\LabController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\ShiftController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ResultController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\ManualOverrideController;
use App\Http\Controllers\Api\V1\SessionTransferController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\FacultyController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectAccessController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\BackupController;




Route::prefix("v1")->group(function () {
    Route::get("ping", [PingController::class, "ping"]);

    // ─── Auth ──────────────────────────────────────────────────
    Route::prefix("auth")->group(function () {
        Route::post("login", [StaffAuthController::class, "login"]);
        Route::post("logout", [StaffAuthController::class, "logout"]);

        Route::middleware("auth:admin")->group(function () {
            Route::get("me", [StaffAuthController::class, "me"]);
        });

        Route::post("candidate/login", [CandidateAuthController::class, "login"]);
        Route::post("candidate/logout", [CandidateAuthController::class, "logout"]);

        Route::middleware("auth:candidate")->group(function () {
            Route::get("candidate/me", [CandidateAuthController::class, "me"]);
        });

        Route::post("forgot-password", [PingController::class, "stub"]);
        Route::post("2fa/verify", [PingController::class, "stub"]);
    });

    // ─── Admin Routes ──────────────────────────────────────────
    Route::middleware("auth:admin")
        ->prefix("admin")
        ->group(function () {
            Route::get("dashboard-stats", [PingController::class, "stub"]);
            Route::middleware("role:Super Admin,Exam Administrator")->get(
                "test-role",
                [PingController::class, "stub"],
            );
            Route::middleware("permission:questions.view")->get("test-permission", [
                PingController::class,
                "stub",
            ]);
            Route::middleware("subject.access")->get("test-subject", [
                PingController::class,
                "stub",
            ]);


            // ─── Exam Sessions Monitoring ────────────────────────
            Route::get('sessions', [AttemptController::class, 'adminIndex']);

            // ─── User Management ───────────────────────────────────
            // بند 3 SRS — فقط Super Admin
            Route::prefix('users')->group(function () {
                Route::get('/', [UserController::class, 'index']);
                Route::post('/', [UserController::class, 'store']);
                Route::get('/{user}', [UserController::class, 'show']);
                Route::put('/{user}', [UserController::class, 'update']);
                Route::delete('/{user}', [UserController::class, 'destroy']);
            });


             // ─── Incident Management ───────────────────────────────
            // بند 26 SRS — مدیریت حوادث جلسه امتحان
            Route::prefix('incidents')->group(function () {
                Route::get('/', [IncidentController::class, 'index']);
                Route::post('/', [IncidentController::class, 'store']);
                Route::get('/{incident}', [IncidentController::class, 'show']);
                Route::put('/{incident}', [IncidentController::class, 'update']);
                Route::patch('/{incident}/resolve', [IncidentController::class, 'resolve']);
            });


            // ─── Manual Override ───────────────────────────────────
            // بند 27 SRS — عملیات دستی ادمین روی Attempt
            // Override ها immutable هستند — فقط store و index و show
            Route::prefix('attempts/{attempt}/overrides')->group(function () {
                Route::get('/', [ManualOverrideController::class, 'index']);
                Route::post('/', [ManualOverrideController::class, 'store']);
                Route::get('/{manualOverride}', [ManualOverrideController::class, 'show']);
            });

            


            // ─── Exam Management ───────────────────────────────────
            Route::prefix("exams")->group(function () {
                Route::get("/", [ExamController::class, "index"]);
                Route::post("/", [ExamController::class, "store"]);
                Route::get("/{exam}", [ExamController::class, "show"]);
                Route::put("/{exam}", [ExamController::class, "update"]);
                Route::delete("/{exam}", [ExamController::class, "destroy"]);
                Route::patch("/{exam}/status", [ExamController::class, "changeStatus"]);
            });

            // ─── Question Bank ─────────────────────────────────────
            Route::prefix("questions")->group(function () {
                Route::get("/", [QuestionController::class, "index"]);
                Route::post("/", [QuestionController::class, "store"]);
                Route::get("/{question}", [QuestionController::class, "show"]);
                Route::put("/{question}", [QuestionController::class, "update"]);
                Route::delete("/{question}", [QuestionController::class, "destroy"]);
                Route::patch("/{question}/status", [QuestionController::class, "changeStatus"]);
                Route::get("/{question}/versions", [QuestionController::class, "versions"]);
            });

            // ─── Candidate Management ──────────────────────────────
            Route::prefix("candidates")->group(function () {
                Route::get("/", [CandidateController::class, "index"]);
                Route::post("/", [CandidateController::class, "store"]);
                Route::post("/bulk-generate", [CandidateController::class, "bulkGenerate"]);
                Route::post("/import", [CandidateController::class, "import"]);
                Route::get("/{candidate}", [CandidateController::class, "show"]);
                Route::put("/{candidate}", [CandidateController::class, "update"]);
                Route::delete("/{candidate}", [CandidateController::class, "destroy"]);
                Route::post("/{candidate}/assign", [CandidateController::class, "assign"]);
                Route::post("/{candidate}/checkin", [CandidateController::class, "checkIn"]);
                Route::get("/{candidate}/entry-card", [CandidateController::class, "entryCard"]);
            });

            // ─── Shift Management ───────────────────────────────────
            Route::prefix("shifts")->group(function () {
                Route::get("/", [ShiftController::class, "index"]);
                Route::post("/", [ShiftController::class, "store"]);
                Route::get("/{shift}", [ShiftController::class, "show"]);
                Route::put("/{shift}", [ShiftController::class, "update"]);
                Route::delete("/{shift}", [ShiftController::class, "destroy"]);
            });

            // ─── Lab Management ────────────────────────────────────
            Route::prefix("labs")->group(function () {
                Route::get("/", [LabController::class, "index"]);
                Route::post("/", [LabController::class, "store"]);
                Route::get("/{lab}", [LabController::class, "show"]);
                Route::put("/{lab}", [LabController::class, "update"]);
                Route::delete("/{lab}", [LabController::class, "destroy"]);
                Route::get("/{lab}/computers-grid", [LabController::class, "computersGrid"]);
                Route::post("/shifts/{shift}/auto-assign", [LabController::class, "autoAssign"]);
            });

            // ─── Computer Management ───────────────────────────────
            Route::prefix("computers")->group(function () {
                Route::get("/", [ComputerController::class, "index"]);
                Route::post("/", [ComputerController::class, "store"]);
                Route::get("/{computer}", [ComputerController::class, "show"]);
                Route::put("/{computer}", [ComputerController::class, "update"]);
                Route::delete("/{computer}", [ComputerController::class, "destroy"]);
                Route::patch("/{computer}/status", [ComputerController::class, "updateStatus"]);
            });

            // ─── Anti-Cheat — Admin ────────────────────────────────
            // بند 21 SRS — دریافت Risk Score برای Admin/Reviewer
            Route::get("attempts/{attempt}/risk-score", [AntiCheatController::class, "getRiskScore"]);


            

            // ─── Session Transfer ──────────────────────────────────
            // بند 22 SRS — انتقال جلسه امتحان بین کامپیوترها
            Route::prefix('attempts/{attempt}/session-transfers')->group(function () {
                Route::get('/', [SessionTransferController::class, 'index']);
                Route::post('/', [SessionTransferController::class, 'store']);
                Route::get('/{sessionTransfer}', [SessionTransferController::class, 'show']);
                
            });

             // Audit Logs — فقط خواندنی (بدون store/update/destroy)
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');

                        // ─── Results ───────────────────────────────────────────
            Route::prefix('exams')->group(function () {
                Route::get('/{exam}/results', [ResultController::class, 'examResults']);
                Route::get('/{exam}/statistics', [ResultController::class, 'statistics']);
                Route::get('/{exam}/question-analytics', [ResultController::class, 'questionAnalytics']);
            });
            Route::get('attempts/{attempt}/result', [ResultController::class, 'show']);

            // ─── Faculty Management ─────────────────────────────────
            // بند 60.2 SRS — چندمضمونی/چنددانشکده‌ای
            Route::prefix('faculties')->group(function () {
                Route::get('/', [FacultyController::class, 'index']);
                Route::post('/', [FacultyController::class, 'store']);
                Route::get('/{faculty}', [FacultyController::class, 'show']);
                Route::put('/{faculty}', [FacultyController::class, 'update']);
                Route::delete('/{faculty}', [FacultyController::class, 'destroy']);
            });

            // ─── Subject Management ─────────────────────────────────
            // بند 60.2 و 60.6 SRS
            Route::prefix('subjects')->group(function () {
                Route::get('/', [SubjectController::class, 'index']);
                Route::post('/', [SubjectController::class, 'store']);
                Route::get('/{subject}', [SubjectController::class, 'show']);
                Route::put('/{subject}', [SubjectController::class, 'update']);
                Route::delete('/{subject}', [SubjectController::class, 'destroy']);

                // ─── Subject Access — بند 60.6 SRS ──────────────────
                Route::get('/{subject}/access', [SubjectAccessController::class, 'index']);
                Route::post('/{subject}/access', [SubjectAccessController::class, 'store']);
                Route::delete('/{subject}/access/{userSubjectAccess}', [SubjectAccessController::class, 'destroy']);
                
            });

                // ─── Notifications ───────────────────────────────────
                Route::prefix('notifications')->group(function () {
                    Route::get('/', [NotificationController::class, 'index']);
                    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
                    Route::patch('/read-all', [NotificationController::class, 'readAll']);
                    Route::get('/{notification}', [NotificationController::class, 'show']);
                    Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
                });

                // ─── Settings ─────────────────────────────────────────
                Route::prefix('settings')->group(function () {
                    Route::get('/', [SettingController::class, 'index']);
                    Route::get('/{key}', [SettingController::class, 'show']);
                    Route::put('/{key}', [SettingController::class, 'update']);
                });

                // ─── Backups ─────────────────────────────────────────
                // فقط Super Admin — نسخه‌برداری کامل دستی از دیتابیس
                Route::prefix('backups')->group(function () {
                    Route::get('/', [BackupController::class, 'index']);
                    Route::post('/', [BackupController::class, 'store']);
                    Route::get('/{backup}', [BackupController::class, 'show']);
                    Route::get('/{backup}/download', [BackupController::class, 'download']);
                    Route::delete('/{backup}', [BackupController::class, 'destroy']);
                });
        });

       
    // ─── Candidate Routes ──────────────────────────────────────
    Route::middleware("auth:candidate")
        ->prefix("candidate")
        ->group(function () {
            // ─── Attempt Engine ────────────────────────────────────
            // بند 15، 16، 17 SRS — موتور اصلی امتحان
            Route::post("attempts/start", [AttemptController::class, "start"]);
            Route::get("attempts/{attempt}/state", [AttemptController::class, "state"]);
            Route::post("attempts/{attempt}/answer", [AttemptController::class, "saveAnswer"]);
            Route::post("attempts/{attempt}/heartbeat", [AttemptController::class, "heartbeat"]);
            Route::post("attempts/{attempt}/submit", [AttemptController::class, "submit"]);

            // ─── Anti-Cheat — Candidate ────────────────────────────
            // بند 20 SRS — ثبت رویداد مشکوک از Frontend
            Route::post("attempts/{attempt}/anti-cheat/event", [AntiCheatController::class, "recordEvent"]);
        });



        

       
});
