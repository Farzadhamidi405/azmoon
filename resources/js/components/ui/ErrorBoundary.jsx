import { Component } from 'react';

const TEXT = {
  title: '\u0645\u0634\u06A9\u0644\u06CC \u062F\u0631 \u0646\u0645\u0627\u06CC\u0634 \u0635\u0641\u062D\u0647 \u0631\u062E \u062F\u0627\u062F.',
  reload: '\u0628\u0627\u0631\u06AF\u0630\u0627\u0631\u06CC \u062F\u0648\u0628\u0627\u0631\u0647',
};

export default class ErrorBoundary extends Component {
  state = { hasError: false };

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, info) {
    console.error('UI error:', error, info && info.componentStack);
  }

  render() {
    if (!this.state.hasError) return this.props.children;
    return (
      <div role="alert" className="flex min-h-screen items-center justify-center bg-paper p-4">
        <div className="w-full max-w-md rounded-ds-lg border border-line bg-panel p-6 text-center shadow-sm">
          <p className="mb-4 text-base font-semibold text-ink">{TEXT.title}</p>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="min-h-11 rounded-ds bg-navy px-5 text-sm font-medium text-white hover:bg-navy-2"
          >
            {TEXT.reload}
          </button>
        </div>
      </div>
    );
  }
}
