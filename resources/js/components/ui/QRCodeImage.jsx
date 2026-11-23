import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

export default function QRCodeImage({
  value = '',
  size = 120,
  className = '',
  alt = 'QR Code کارت ورود',
}) {
  const [dataUrl, setDataUrl] = useState('');
  const [error, setError] = useState(false);

  useEffect(() => {
    let active = true;
    if (!value) {
      setDataUrl('');
      return;
    }

    QRCode.toDataURL(value, {
      width: size * 2,
      margin: 1,
      color: {
        dark: '#152341', // Navy رنگ سازمانی پوهنتون
        light: '#FFFFFF',
      },
      errorCorrectionLevel: 'M',
    })
      .then((url) => {
        if (active) {
          setDataUrl(url);
          setError(false);
        }
      })
      .catch(() => {
        if (active) setError(true);
      });

    return () => {
      active = false;
    };
  }, [value, size]);

  if (error || !value) {
    return (
      <div
        style={{ width: size, height: size }}
        className={`bg-paper border border-line rounded-lg flex items-center justify-center text-ink-soft text-[10px] text-center p-1 ${className}`}
      >
        کد نامعتبر
      </div>
    );
  }

  if (!dataUrl) {
    return (
      <div
        style={{ width: size, height: size }}
        className={`bg-paper animate-pulse border border-line rounded-lg ${className}`}
      />
    );
  }

  return (
    <img
      src={dataUrl}
      alt={alt}
      width={size}
      height={size}
      style={{ width: size, height: size }}
      className={`rounded border border-line bg-white shadow-2xs ${className}`}
    />
  );
}
