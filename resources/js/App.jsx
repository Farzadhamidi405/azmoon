// App.jsx
// ورودی اصلی React — تعریف تمام مسیرها با محافظت Route Guard و Code Splitting مبتنی بر React.lazy.

import React, { Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import { AuthProvider } from './context/AuthContext.jsx';
import RequireAuth from './components/auth/RequireAuth.jsx';
import RequireGuest from './components/auth/RequireGuest.jsx';

import GuestLayout from './layouts/GuestLayout.jsx';
import AdminLayout from './layouts/AdminLayout.jsx';
import CandidateLayout from './layouts/CandidateLayout.jsx';

import ErrorBoundary from './components/ui/ErrorBoundary.jsx';
import { ToastProvider } from './components/ui/Toast.jsx';
import { Spinner } from './components/ui/index.js';

// Auth Pages (Lazy Loaded)
const LoginPage = React.lazy(() => import('./pages/auth/LoginPage.jsx'));
const ForgotPasswordPage = React.lazy(() => import('./pages/auth/ForgotPasswordPage.jsx'));
const TwoFactorPage = React.lazy(() => import('./pages/auth/TwoFactorPage.jsx'));

// Admin Core Pages (Lazy Loaded)
const DashboardPage = React.lazy(() => import('./pages/admin/DashboardPage.jsx'));
const FacultiesPage = React.lazy(() => import('./pages/admin/FacultiesPage.jsx'));
const SubjectsPage = React.lazy(() => import('./pages/admin/SubjectsPage.jsx'));
const LabsPage = React.lazy(() => import('./pages/admin/LabsPage.jsx'));
const ComputersPage = React.lazy(() => import('./pages/admin/ComputersPage.jsx'));
const ShiftsPage = React.lazy(() => import('./pages/admin/ShiftsPage.jsx'));

// Exam Management Pages (Lazy Loaded)
const ExamsPage = React.lazy(() => import('./pages/admin/ExamsPage.jsx'));
const ExamCreatePage = React.lazy(() => import('./pages/admin/ExamCreatePage.jsx'));
const ExamPoolPage = React.lazy(() => import('./pages/admin/ExamPoolPage.jsx'));
const QuestionsPage = React.lazy(() => import('./pages/admin/QuestionsPage.jsx'));
const QuestionApprovalPage = React.lazy(() => import('./pages/admin/QuestionApprovalPage.jsx'));
const CandidatesPage = React.lazy(() => import('./pages/admin/CandidatesPage.jsx'));
const CandidateCheckinPage = React.lazy(() => import('./pages/admin/CandidateCheckinPage.jsx'));
const CandidateImportPage = React.lazy(() => import('./pages/admin/CandidateImportPage.jsx'));
const CandidateCardsPage = React.lazy(() => import('./pages/admin/CandidateCardsPage.jsx'));

// Monitoring & Results Pages (Lazy Loaded)
const SessionsPage = React.lazy(() => import('./pages/admin/SessionsPage.jsx'));
const IncidentsPage = React.lazy(() => import('./pages/admin/IncidentsPage.jsx'));
const ManualOverridePage = React.lazy(() => import('./pages/admin/ManualOverridePage.jsx'));
const ResultsPage = React.lazy(() => import('./pages/admin/ResultsPage.jsx'));
const ExamStatsPage = React.lazy(() => import('./pages/admin/ExamStatsPage.jsx'));

// System & Security Pages (Lazy Loaded)
const UsersPage = React.lazy(() => import('./pages/admin/UsersPage.jsx'));
const RolesPage = React.lazy(() => import('./pages/admin/RolesPage.jsx'));
const AuditLogsPage = React.lazy(() => import('./pages/admin/AuditLogsPage.jsx'));
const AntiCheatPage = React.lazy(() => import('./pages/admin/AntiCheatPage.jsx'));
const NotificationsPage = React.lazy(() => import('./pages/admin/NotificationsPage.jsx'));
const SettingsPage = React.lazy(() => import('./pages/admin/SettingsPage.jsx'));
const BackupsPage = React.lazy(() => import('./pages/admin/BackupsPage.jsx'));

// Candidate Exam Pages (Lazy Loaded)
const ExamInstructionsPage = React.lazy(() => import('./pages/candidate/ExamInstructionsPage.jsx'));
const ExamQuestionPage = React.lazy(() => import('./pages/candidate/ExamQuestionPage.jsx'));
const ExamDonePage = React.lazy(() => import('./pages/candidate/ExamDonePage.jsx'));

function PageFallback() {
  return (
    <div className="min-h-[50vh] flex flex-col items-center justify-center gap-3 p-8 text-ink-soft">
      <Spinner size="md" />
      <span className="text-xs font-semibold">در حال بارگذاری اطلاعات صفحه...</span>
    </div>
  );
}

export default function App() {
  return (
    <ErrorBoundary>
      <ToastProvider>
        <AuthProvider>
          <BrowserRouter>
            <Suspense fallback={<PageFallback />}>
              <Routes>
                {/* مسیرهای مهمان — فقط برای کاربران لاگین‌نشده */}
                <Route element={<RequireGuest />}>
                  <Route element={<GuestLayout />}>
                    <Route path="/login" element={<LoginPage />} />
                    <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                    <Route path="/2fa" element={<TwoFactorPage />} />
                  </Route>
                </Route>

                {/* مسیرهای Admin — فقط برای Staff لاگین‌شده */}
                <Route element={<RequireAuth requiredActorType="admin" />}>
                  <Route path="/admin" element={<AdminLayout />}>
                    <Route index element={<Navigate to="/admin/dashboard" replace />} />
                    <Route path="dashboard" element={<DashboardPage />} />
                    <Route path="exams" element={<ExamsPage />} />
                    <Route path="exams/create" element={<ExamCreatePage />} />
                    <Route path="exams/:id/edit" element={<ExamCreatePage />} />
                    <Route path="exams/pool" element={<ExamPoolPage />} />
                    <Route path="questions" element={<QuestionsPage />} />
                    <Route path="questions/approval" element={<QuestionApprovalPage />} />
                    <Route path="candidates" element={<CandidatesPage />} />
                    <Route path="candidates/checkin" element={<CandidateCheckinPage />} />
                    <Route path="candidates/import" element={<CandidateImportPage />} />
                    <Route path="candidates/cards" element={<CandidateCardsPage />} />
                    <Route path="faculties" element={<FacultiesPage />} />
                    <Route path="subjects" element={<SubjectsPage />} />
                    <Route path="labs" element={<LabsPage />} />
                    <Route path="shifts" element={<ShiftsPage />} />
                    <Route path="computers" element={<ComputersPage />} />
                    <Route path="sessions" element={<SessionsPage />} />
                    <Route path="incidents" element={<IncidentsPage />} />
                    <Route path="override" element={<ManualOverridePage />} />
                    <Route path="results" element={<ResultsPage />} />
                    <Route path="statistics" element={<ExamStatsPage />} />
                    <Route path="users" element={<UsersPage />} />
                    <Route path="roles" element={<RolesPage />} />
                    <Route path="audit-logs" element={<AuditLogsPage />} />
                    <Route path="anti-cheat" element={<AntiCheatPage />} />
                    <Route path="notifications" element={<NotificationsPage />} />
                    <Route path="settings" element={<SettingsPage />} />
                    <Route path="backups" element={<BackupsPage />} />
                  </Route>
                </Route>

                {/* مسیرهای Candidate — فقط برای متقاضی لاگین‌شده */}
                <Route element={<RequireAuth requiredActorType="candidate" />}>
                  {/* Standard /exam layout */}
                  <Route path="/exam" element={<CandidateLayout />}>
                    <Route index element={<Navigate to="/exam/instructions" replace />} />
                    <Route path="instructions" element={<ExamInstructionsPage />} />
                    <Route path="question" element={<ExamQuestionPage />} />
                    <Route path="done" element={<ExamDonePage />} />
                  </Route>
                  {/* Direct /candidate aliases */}
                  <Route path="/candidate">
                    <Route index element={<Navigate to="/candidate/instructions" replace />} />
                    <Route path="instructions" element={<ExamInstructionsPage />} />
                    <Route path="exam" element={<ExamQuestionPage />} />
                    <Route path="done" element={<ExamDonePage />} />
                  </Route>
                </Route>

                {/* پیش‌فرض */}
                <Route path="/" element={<Navigate to="/login" replace />} />
              </Routes>
            </Suspense>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ErrorBoundary>
  );
}
