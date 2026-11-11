import React from 'react';

export default function PlaceholderPage({ title, crumb }) {
    return (
        <div dir="rtl">
            {crumb && (
                <div style={{ fontSize: '12px', color: '#68665F', marginBottom: '4px' }}>
                    پوهنتون کندز /{' '}
                    <strong style={{ color: '#152341' }}>{crumb}</strong>
                </div>
            )}
            <h1
                style={{
                    fontSize: '20px',
                    fontWeight: 800,
                    color: '#152341',
                    margin: '0 0 20px',
                }}
            >
                {title}
            </h1>
            <div
                style={{
                    background: '#fff',
                    border: '1px solid #E5E1D4',
                    borderRadius: '14px',
                    padding: '48px 24px',
                    textAlign: 'center',
                }}
            >
                <div style={{ fontSize: '48px', marginBottom: '16px' }}>🚧</div>
                <div
                    style={{
                        fontWeight: 700,
                        color: '#152341',
                        marginBottom: '8px',
                    }}
                >
                    {title}
                </div>
                <div style={{ fontSize: '12px', color: '#68665F' }}>
                    این ماژول در بخش‌های بعدی پیاده‌سازی می‌شود.
                </div>
            </div>
        </div>
    );
}