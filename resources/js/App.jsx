// App.jsx
// ورودی اصلی React — تعریف تمام مسیرها با محافظت Route Guard.

import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';

import { AuthProvider } from './context/AuthContext.jsx';
import RequireAuth from './components/auth/RequireAuth.jsx';
import RequireGuest from './components/auth/RequireGuest.jsx';

import GuestLayout from './layouts/GuestLayout.jsx';
import AdminLayout from './layouts/AdminLayout.jsx';
import CandidateLayout from './layouts/CandidateLayout.jsx';

import LoginPage from './pages/auth/LoginPage.jsx';
import ForgotPasswordPage from './pages/auth/ForgotPasswordPage.jsx';
import TwoFactorPage from './pages/auth/TwoFactorPage.jsx';
import DashboardPage from './pages/admin/DashboardPage.jsx';
import FacultiesPage from './pages/admin/FacultiesPage.jsx';
import SubjectsPage from './pages/admin/SubjectsPage.jsx';
import LabsPage from './pages/admin/LabsPage.jsx';
import ComputersPage from './pages/admin/ComputersPage.jsx';
import ShiftsPage from './pages/admin/ShiftsPage.jsx';
import ExamInstructionsPage from './pages/candidate/ExamInstructionsPage.jsx';
import PlaceholderPage from './components/ui/PlaceholderPage.jsx';
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
                                    <Route path="exams" element={<PlaceholderPage title="آزمون‌ها" crumb="امتحانات" />} />
                                    <Route path="exams/create" element={<PlaceholderPage title="ایجاد آزمون" crumb="امتحانات" />} />
                                    <Route path="questions" element={<PlaceholderPage title="بانک سوالات" crumb="سوالات" />} />
                                    <Route path="candidates" element={<PlaceholderPage title="متقاضیان" crumb="متقاضیان" />} />
                                    <Route path="faculties" element={<FacultiesPage />} />
                                    <Route path="subjects" element={<SubjectsPage />} />
                                    <Route path="labs" element={<LabsPage />} />
                                    <Route path="shifts" element={<ShiftsPage />} />
                                    <Route path="computers" element={<ComputersPage />} />
                                    <Route path="sessions" element={<PlaceholderPage title="جلسات امتحان" crumb="جلسات" />} />
                                    <Route path="results" element={<PlaceholderPage title="نتایج" crumb="نتایج" />} />
                                    <Route path="statistics" element={<PlaceholderPage title="آمار" crumb="نتایج" />} />
                                    <Route path="users" element={<PlaceholderPage title="کاربران" crumb="کاربران" />} />
                                    <Route path="roles" element={<PlaceholderPage title="نقش‌ها" crumb="کاربران" />} />
                                    <Route path="audit-logs" element={<PlaceholderPage title="لاگ فعالیت‌ها" crumb="نظارت" />} />
                                    <Route path="anti-cheat" element={<PlaceholderPage title="ضدتقلب" crumb="نظارت" />} />
                                    <Route path="notifications" element={<PlaceholderPage title="اعلان‌ها" crumb="سیستم" />} />
                                    <Route path="settings" element={<PlaceholderPage title="تنظیمات" crumb="سیستم" />} />
                                    <Route path="backups" element={<PlaceholderPage title={'\u0646\u0633\u062E\u0647\u200C\u0647\u0627\u06CC \u067E\u0634\u062A\u06CC\u0628\u0627\u0646'} crumb={'\u0633\u06CC\u0633\u062A\u0645'} />} />
                                </Route>
                            </Route>

                            {/* مسیرهای Candidate — فقط برای متقاضی لاگین‌شده */}
                            <Route element={<RequireAuth requiredActorType="candidate" />}>
                                <Route path="/exam" element={<CandidateLayout />}>
                                    <Route index element={<Navigate to="/exam/instructions" replace />} />
                                    <Route path="instructions" element={<ExamInstructionsPage />} />
                                    <Route path="question" element={<PlaceholderPage title="سوال امتحان" />} />
                                    <Route path="done" element={<PlaceholderPage title="پایان امتحان" />} />
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