import { Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';

export default function GuestLayout() {
  const { loading } = useAuth();

  if (loading) return null;

  return (
    <div className="flex min-h-screen items-center justify-center bg-shell p-4 md:p-6">
      <Outlet />
    </div>
  );
}
