import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth.js';
import { getErrorMessage } from '../../lib/api.js';
import AuthCard from '../../components/auth/AuthCard.jsx';
import { Alert, Button, Input } from '../../components/ui/index.js';

const TEXT = Object.freeze({
  username: '\u0646\u0627\u0645 \u06A9\u0627\u0631\u0628\u0631\u06CC / \u06A9\u062F \u0645\u062A\u0642\u0627\u0636\u06CC',
  password: '\u0631\u0645\u0632 \u0639\u0628\u0648\u0631',
  submit: '\u0648\u0631\u0648\u062F \u0628\u0647 \u0633\u06CC\u0633\u062A\u0645',
  submitting: '\u062F\u0631 \u062D\u0627\u0644 \u0648\u0631\u0648\u062F...',
  forgot: '\u0631\u0645\u0632 \u0639\u0628\u0648\u0631 \u0631\u0627 \u0641\u0631\u0627\u0645\u0648\u0634 \u06A9\u0631\u062F\u0647\u200C\u0627\u06CC\u062F\u061F',
  helper: '\u06CC\u06A9 \u062F\u0631\u06AF\u0627\u0647 \u0648\u0631\u0648\u062F \u0628\u0631\u0627\u06CC \u0647\u0645\u0647 \u2014 \u0633\u06CC\u0633\u062A\u0645 \u0628\u0647\u200C\u0635\u0648\u0631\u062A \u062E\u0648\u062F\u06A9\u0627\u0631 \u0628\u0631 \u0627\u0633\u0627\u0633 \u0646\u0642\u0634 \u0634\u0645\u0627 (Super Admin\u060C Exam Administrator\u060C Lab Manager\u060C \u06CC\u0627 \u0645\u062A\u0642\u0627\u0636\u06CC \u0627\u0645\u062A\u062D\u0627\u0646) \u0634\u0645\u0627 \u0631\u0627 \u0628\u0647 \u067E\u0646\u0644 \u0645\u0646\u0627\u0633\u0628 \u0647\u062F\u0627\u06CC\u062A \u0645\u06CC\u200C\u06A9\u0646\u062F.',
  usernameRequired: '\u0646\u0627\u0645 \u06A9\u0627\u0631\u0628\u0631\u06CC \u06CC\u0627 \u06A9\u062F \u0645\u062A\u0642\u0627\u0636\u06CC \u0631\u0627 \u0648\u0627\u0631\u062F \u06A9\u0646\u06CC\u062F.',
  passwordRequired: '\u0631\u0645\u0632 \u0639\u0628\u0648\u0631 \u0631\u0627 \u0648\u0627\u0631\u062F \u06A9\u0646\u06CC\u062F.',
  fallbackError: '\u0646\u0627\u0645 \u06A9\u0627\u0631\u0628\u0631\u06CC \u06CC\u0627 \u0631\u0645\u0632 \u0639\u0628\u0648\u0631 \u0627\u0634\u062A\u0628\u0627\u0647 \u0627\u0633\u062A.',
});

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [form, setForm] = useState({ username: '', password: '' });
  const [fieldErrors, setFieldErrors] = useState({});
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  function handleChange(event) {
    const { name, value } = event.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    setFieldErrors((prev) => (prev[name] ? { ...prev, [name]: '' } : prev));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (loading) return;

    // Client check only for empty fields (no length rule: candidates have no minimum).
    const nextErrors = {};
    if (!form.username.trim()) nextErrors.username = TEXT.usernameRequired;
    if (!form.password) nextErrors.password = TEXT.passwordRequired;
    setFieldErrors(nextErrors);
    setError('');
    if (Object.keys(nextErrors).length > 0) return;

    setLoading(true);
    try {
      const data = await login(form);
      navigate(data.actorType === 'candidate' ? '/exam/instructions' : '/admin/dashboard', { replace: true });
    } catch (err) {
      setError(getErrorMessage(err, TEXT.fallbackError));
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthCard brand>
      <form onSubmit={handleSubmit} noValidate>
        {error ? (
          <Alert tone="error" className="mb-4">
            {error}
          </Alert>
        ) : null}
        <Input
          name="username"
          label={TEXT.username}
          value={form.username}
          onChange={handleChange}
          error={fieldErrors.username}
          autoComplete="username"
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
        />
        <Input
          name="password"
          type="password"
          label={TEXT.password}
          value={form.password}
          onChange={handleChange}
          error={fieldErrors.password}
          autoComplete="current-password"
        />
        <Button type="submit" block loading={loading}>
          {loading ? TEXT.submitting : TEXT.submit}
        </Button>
      </form>
      <p className="mt-3.5 text-center text-[11.5px] leading-[1.8] text-ink-soft">{TEXT.helper}</p>
      <div className="mt-2.5 text-center">
        <Button as={Link} to="/forgot-password" variant="ghost" size="sm">
          {TEXT.forgot}
        </Button>
      </div>
    </AuthCard>
  );
}