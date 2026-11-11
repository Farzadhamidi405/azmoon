import { useRef } from 'react';
import { Outlet, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import TopNavbar from '../components/layout/TopNavbar.jsx';
import { LoadingState } from '../components/ui/index.js';

const SKIP_LABEL = '\u0631\u0641\u062A\u0646 \u0628\u0647 \u0645\u062D\u062A\u0648\u0627';

export default function AdminLayout() {
  const { user, loading } = useAuth();
  const mainRef = useRef(null);

  if (loading) return <LoadingState className="min-h-screen justify-center bg-paper" />;

  if (!user) return <Navigate to="/login" replace />;

  function skipToContent(event) {
    event.preventDefault();
    if (mainRef.current) mainRef.current.focus();
  }

  return (
    <div className="min-h-screen bg-paper">
      <a
        href="#main-content"
        onClick={skipToContent}
        className="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-navy focus:shadow-ds focus:outline-2 focus:outline-gold"
      >
        {SKIP_LABEL}
      </a>
      <TopNavbar />
      <main
        id="main-content"
        ref={mainRef}
        tabIndex={-1}
        className="mx-auto w-full max-w-[1280px] px-3 py-4 outline-none md:px-6 md:py-8"
      >
        <Outlet />
      </main>
    </div>
  );
}
