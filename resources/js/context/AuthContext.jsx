import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api, ensureCsrf, setUnauthorizedHandler, MESSAGES } from '../lib/api.js';
import { hasPermission, hasRole as userHasRole, roleName } from '../lib/permissions.js';
import { notifyError } from '../lib/notify.js';

export const AuthContext = createContext(null);

const ENDPOINTS = {
  admin: {
    login: '/api/v1/auth/login',
    logout: '/api/v1/auth/logout',
    me: '/api/v1/auth/me',
  },
  candidate: {
    login: '/api/v1/auth/candidate/login',
    logout: '/api/v1/auth/candidate/logout',
    me: '/api/v1/auth/candidate/me',
  },
};

// Login forms show their own errors, so no global toast and no 401 handler here.
const FORM_REQUEST = { skipAuthHandler: true, skipErrorToast: true };

// Restores the session. A 401 from /me only means "not signed in as this actor" and is silent.
async function loadSession() {
  for (const actorType of ['admin', 'candidate']) {
    try {
      const res = await api.get(ENDPOINTS[actorType].me, { skipAuthHandler: true });
      const body = res.data;
      if (body && body.authenticated && body.user) {
        return { user: body.user, actorType };
      }
    } catch (error) {
      // Network / 5xx already produced a toast in lib/api.js; stop probing.
      if (error.status !== 401) return null;
    }
  }
  return null;
}

async function postLogin(actorType, identifier, password) {
  const res = await api.post(
    ENDPOINTS[actorType].login,
    { login: identifier, password },
    FORM_REQUEST,
  );
  return res.data;
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [actorType, setActorType] = useState(null);
  const [loading, setLoading] = useState(true);
  const userRef = useRef(null);
  const typeRef = useRef(null);

  const applySession = useCallback((nextUser, nextType) => {
    userRef.current = nextUser;
    typeRef.current = nextType;
    setUser(nextUser);
    setActorType(nextType);
  }, []);

  // Initial session restore. "loading" is only true until this first check ends.
  useEffect(() => {
    let active = true;
    loadSession()
      .then((session) => {
        if (!active) return;
        applySession(session ? session.user : null, session ? session.actorType : null);
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [applySession]);

  // A 401 on an authenticated request means the session expired: clear it.
  // Returning false (no signed-in user) suppresses the "session expired" toast.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      if (!userRef.current) return false;
      applySession(null, null);
      return true;
    });
    return () => setUnauthorizedHandler(null);
  }, [applySession]);

  const refresh = useCallback(async () => {
    const session = await loadSession();
    applySession(session ? session.user : null, session ? session.actorType : null);
    return session ? session.user : null;
  }, [applySession]);

  const login = useCallback(
    async (credentials = {}) => {
      const identifier = String(credentials.login ?? credentials.username ?? '').trim();
      const password = credentials.password ?? '';

      // Fresh CSRF cookie (the previous session token is invalid after logout).
      await ensureCsrf(true);

      let type = 'admin';
      let body;
      try {
        body = await postLogin('admin', identifier, password);
      } catch (error) {
        // 403 (inactive) and 429 (locked) are real answers for staff: do not mask them.
        if (error.status !== 401 && error.status !== 422) throw error;
        type = 'candidate';
        body = await postLogin('candidate', identifier, password);
      }

      if (!body || !body.user) {
        const unexpected = new Error('Unexpected login response');
        unexpected.apiMessage = MESSAGES.unknown;
        throw unexpected;
      }

      applySession(body.user, type);
      return { ...body, actorType: type };
    },
    [applySession],
  );

  // Local state is always cleared. 401/419 on logout are accepted silently
  // (session already gone); other failures are reported but never block leaving.
  const logout = useCallback(async () => {
    const type = typeRef.current === 'candidate' ? 'candidate' : 'admin';
    try {
      await api.post(ENDPOINTS[type].logout, null, FORM_REQUEST);
    } catch (error) {
      if (error.status !== 401 && error.status !== 419) {
        notifyError(error.apiMessage || MESSAGES.unknown);
      }
    } finally {
      applySession(null, null);
      try {
        await ensureCsrf(true);
      } catch (error) {
        // The next login refreshes the cookie again.
      }
    }
  }, [applySession]);

  const value = useMemo(
    () => ({
      user,
      actorType,
      loading,
      role: roleName(user),
      login,
      logout,
      refresh,
      can: (permission) => hasPermission(user, permission),
      hasRole: (...roles) => userHasRole(user, ...roles),
    }),
    [user, actorType, loading, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
