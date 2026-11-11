// RequireGuest.jsx
// از ورود کاربر لاگین‌شده به صفحات مهمان (مثل login) جلوگیری می‌کند.
// کاربر لاگین‌شده به dashboard مناسب redirect می‌شود.

import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { LoadingState } from '../ui/Loading.jsx';

export default function RequireGuest() {
    const { user, actorType, loading } = useAuth();

    // در حال بارگذاری اولیه
    if (loading) {
        return <LoadingState className="min-h-screen justify-center" />;
    }

    // لاگین شده — به dashboard مناسب بفرست
    if (user) {
        const destination = actorType === 'candidate' ? '/exam' : '/admin';
        return <Navigate to={destination} replace />;
    }

    return <Outlet />;
}