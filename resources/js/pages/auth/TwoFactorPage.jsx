import { Link } from 'react-router-dom';
import AuthCard from '../../components/auth/AuthCard.jsx';
import { Alert, Button } from '../../components/ui/index.js';

const TEXT = Object.freeze({
  title: '\u062A\u0623\u06CC\u06CC\u062F \u062F\u0648\u0645\u0631\u062D\u0644\u0647\u200C\u0627\u06CC',
  notice: '\u062A\u0623\u06CC\u06CC\u062F \u062F\u0648\u0645\u0631\u062D\u0644\u0647\u200C\u0627\u06CC \u062F\u0631 \u062D\u0627\u0644 \u062D\u0627\u0636\u0631 \u0641\u0639\u0627\u0644 \u0646\u06CC\u0633\u062A \u0648 \u0628\u0631\u0627\u06CC \u0648\u0631\u0648\u062F \u0628\u0647 \u0633\u06CC\u0633\u062A\u0645 \u0644\u0627\u0632\u0645 \u0646\u06CC\u0633\u062A.',
  back: '\u0628\u0627\u0632\u06AF\u0634\u062A \u0628\u0647 \u0648\u0631\u0648\u062F',
});

// Informational page only: no OTP boxes, no timer and no API request.
export default function TwoFactorPage() {
  return (
    <AuthCard title={TEXT.title} centered>
      <Alert tone="info" className="mt-3.5">
        {TEXT.notice}
      </Alert>
      <div className="mt-3.5">
        <Button as={Link} to="/login" variant="ghost" size="sm">
          {TEXT.back}
        </Button>
      </div>
    </AuthCard>
  );
}