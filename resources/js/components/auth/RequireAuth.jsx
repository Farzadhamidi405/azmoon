// RequireAuth.jsx
// از ورود کاربر لاگین‌نشده به مسیرهای محافظت‌شده جلوگیری می‌کند.
// در صورت لاگین‌نبودن، به صفحه login redirect می‌شود.

import React from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { LoadingState } from '../ui/Loading.jsx';

/**
 * @param {string|null} requiredActorType - 'admin' | 'candidate' | null (هر دو مجاز)
 */
export default function RequireAuth({ requiredActorType = null }) {
    const { user, actorType, loading } = useAuth();
    const location = useLocation();

    // در حال بارگذاری اولیه — هیچ redirect انجام نمی‌شود
    if (loading) {
        return <LoadingState className="min-h-screen justify-center" />;
    }

    // لاگین نشده
    if (!user) {
        return <Navigate to="/login" state={{ from: location }} replace />;
    }

    // نوع کاربر مطابقت ندارد
    if (requiredActorType && actorType !== requiredActorType) {
        return <Navigate to="/login" replace />;
    }

    return <Outlet />;
}