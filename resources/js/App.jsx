// App.jsx
// ورودی اصلی React — تعریف تمام مسیرها با محافظت Route Guard و پیاده‌سازی کامل صفحات سامانه آزمون پوهنتون کندز.

import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import { AuthProvider } from './context/AuthContext.jsx';
import RequireAuth from './components/auth/RequireAuth.jsx';
import RequireGuest from './components/auth/RequireGuest.jsx';

import GuestLayout from './layouts/GuestLayout.jsx';
import AdminLayout from './layouts/AdminLayout.jsx';
import CandidateLayout from './layouts/CandidateLayout.jsx';

// Auth Pages
import LoginPage from './pages/auth/LoginPage.jsx';
import ForgotPasswordPage from './pages/auth/ForgotPasswordPage.jsx';
import TwoFactorPage from './pages/auth/TwoFactorPage.jsx';

// Admin Core Pages
import DashboardPage from './pages/admin/DashboardPage.jsx';
import FacultiesPage from './pages/admin/FacultiesPage.jsx';
import SubjectsPage from './pages/admin/SubjectsPage.jsx';
import LabsPage from './pages/admin/LabsPage.jsx';
import ComputersPage from './pages/admin/ComputersPage.jsx';
import ShiftsPage from './pages/admin/ShiftsPage.jsx';

// Exam Management Pages
import ExamsPage from './pages/admin/ExamsPage.jsx';
import ExamCreatePage from './pages/admin/ExamCreatePage.jsx';
import QuestionsPage from './pages/admin/QuestionsPage.jsx';
import QuestionApprovalPage from './pages/admin/QuestionApprovalPage.jsx';
import CandidatesPage from './pages/admin/CandidatesPage.jsx';

// Monitoring & Results Pages
import SessionsPage from './pages/admin/SessionsPage.jsx';
import ResultsPage from './pages/admin/ResultsPage.jsx';
import ExamStatsPage from './pages/admin/ExamStatsPage.jsx';

// System & Security Pages
import UsersPage from './pages/admin/UsersPage.jsx';
import RolesPage from './pages/admin/RolesPage.jsx';
import AuditLogsPage from './pages/admin/AuditLogsPage.jsx';
import AntiCheatPage from './pages/admin/AntiCheatPage.jsx';
import NotificationsPage from './pages/admin/NotificationsPage.jsx';
import SettingsPage from './pages/admin/SettingsPage.jsx';
import BackupsPage from './pages/admin/BackupsPage.jsx';

// Candidate Exam Pages
import ExamInstructionsPage from './pages/candidate/ExamInstructionsPage.jsx';
import ExamQuestionPage from './pages/candidate/ExamQuestionPage.jsx';
import ExamDonePage from './pages/candidate/ExamDonePage.jsx';

import ErrorBoundary from './components/ui/ErrorBoundary.jsx';
import { ToastProvider } from './components/ui/Toast.jsx';

export default function App() {
  return (
    <ErrorBoundary>
      <ToastProvider>
        <AuthProvider>
          <BrowserRouter>
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
                  <Route path="questions" element={<QuestionsPage />} />
                  <Route path="questions/approval" element={<QuestionApprovalPage />} />
                  <Route path="candidates" element={<CandidatesPage />} />
                  <Route path="faculties" element={<FacultiesPage />} />
                  <Route path="subjects" element={<SubjectsPage />} />
                  <Route path="labs" element={<LabsPage />} />
                  <Route path="shifts" element={<ShiftsPage />} />
                  <Route path="computers" element={<ComputersPage />} />
                  <Route path="sessions" element={<SessionsPage />} />
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
              <Route path="*" element={<Navigate to="/login" replace />} />
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </ToastProvider>
    </ErrorBoundary>
  );
}
