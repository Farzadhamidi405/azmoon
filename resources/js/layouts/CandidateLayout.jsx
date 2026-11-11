import { useState } from 'react';
import { Outlet, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import Brand from '../components/layout/Brand.jsx';
import { LoadingState, Spinner } from '../components/ui/index.js';

const LOGOUT_LABEL = '\u062E\u0631\u0648\u062C';
const QUESTION_PATH = '/exam/question';

// Candidate shell: brand, "name - code", logout (hidden on the question page). No menu, no bell, no timer.
export default function CandidateLayout() {
  const { user, loading, logout } = useAuth();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const [loggingOut, setLoggingOut] = useState(false);

  if (loading) return <LoadingState className="min-h-screen justify-center bg-shell" />;

  if (!user) return <Navigate to="/login" replace />;

  const hideLogout = pathname.replace(/\/+$/, '') === QUESTION_PATH;
  const name = typeof user.name === 'string' ? user.name : '';
  const code = user.candidate_code ? String(user.candidate_code) : '';

  async function handleLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      await logout();
    } catch (err) {
      // logout() never rejects; this only guards against unexpected errors.
    }
    navigate('/login', { replace: true });
    setLoggingOut(false);
  }

  return (
    <div className="flex min-h-screen flex-col bg-shell">
      <header className="flex items-center justify-between gap-3 bg-navy px-4 py-[14px] text-[13px] text-white md:px-[26px]">
        <Brand className="shrink-0" />
        <div className="flex min-w-0 items-center gap-3">
          <span className="min-w-0 truncate">
            {name}
            {code ? (
              <>
                {' \u2014 '}
                <bdi>{code}</bdi>
              </>
            ) : null}
          </span>
          {hideLogout ? null : (
            <button
              type="button"
              onClick={handleLogout}
              disabled={loggingOut}
              aria-busy={loggingOut}
              className="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold text-nav-ink transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold disabled:cursor-wait disabled:opacity-70"
            >
              {loggingOut ? <Spinner size="sm" /> : null}
              {LOGOUT_LABEL}
            </button>
          )}
        </div>
      </header>
      <main className="flex flex-1 flex-col px-3 py-4 md:px-6 md:py-8">
        <Outlet />
      </main>
    </div>
  );
}
