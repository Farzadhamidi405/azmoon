import React from 'react';

export default function ExamInstructionsPage() {
    return (
        <div
            dir="rtl"
            style={{
                flex: 1,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '24px',
            }}
        >
            <div
                style={{
                    width: '100%',
                    maxWidth: '480px',
                    background: '#fff',
                    border: '1px solid #E5E1D4',
                    borderRadius: '14px',
                    padding: '36px',
                    textAlign: 'center',
                }}
            >
                <div style={{ fontSize: '48px', marginBottom: '16px' }}>📋</div>
                <div
                    style={{
                        fontWeight: 700,
                        fontSize: '16px',
                        color: '#152341',
                        marginBottom: '8px',
                    }}
                >
                    دستورالعمل امتحان
                </div>
                <div style={{ fontSize: '12px', color: '#68665F' }}>
                    پیاده‌سازی کامل در Section 5 انجام می‌شود.
                </div>
            </div>
        </div>
    );
}