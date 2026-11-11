import { useEffect, useState } from 'react';

function read(query) {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia(query).matches;
}

// Tracks a CSS media query. Use rem-based queries so they match Tailwind breakpoints,
// for example '(min-width: 64rem)' for lg and '(min-width: 48rem)' for md.
export default function useMediaQuery(query) {
  const [matches, setMatches] = useState(() => read(query));

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return undefined;
    const list = window.matchMedia(query);
    function onChange(event) {
      setMatches(event.matches);
    }
    setMatches(list.matches);
    list.addEventListener('change', onChange);
    return () => list.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}

export { useMediaQuery };
