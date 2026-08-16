import { useState, useRef, useEffect } from 'react';
import { languages } from '../data/languages';
import { useI18n } from '../context/I18nContext';

interface LanguageSwitcherProps {
  variant?: 'nav' | 'dashboard';
}

export function LanguageSwitcher({ variant = 'nav' }: LanguageSwitcherProps) {
  const { lang, setLang } = useI18n();
  const current = languages.find(l => l.code === lang) ?? languages[0];
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const isDash = variant === 'dashboard';

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button
        onClick={() => setOpen(!open)}
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 6,
          padding: isDash ? '5px 10px' : '6px 12px',
          borderRadius: 8,
          border: `1px solid ${isDash ? 'var(--border)' : 'rgba(255,255,255,0.1)'}`,
          background: isDash ? 'transparent' : 'rgba(255,255,255,0.04)',
          color: isDash ? 'var(--text-mid)' : 'var(--text-muted)',
          fontSize: isDash ? 11 : '0.8125rem',
          fontFamily: 'var(--font-sans)',
          cursor: 'pointer',
          transition: 'all 0.2s',
        }}
        aria-label="Choisir la langue"
      >
        <span style={{ fontSize: isDash ? 14 : 16 }}>{current.flag}</span>
        <span>{current.code.toUpperCase()}</span>
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" style={{ opacity: 0.6 }}>
          <path d="M6 9l6 6 6-6" />
        </svg>
      </button>

      {open && (
        <div
          style={{
            position: 'absolute',
            top: '100%',
            right: 0,
            marginTop: 4,
            minWidth: 180,
            background: 'rgba(17,17,24,0.97)',
            backdropFilter: 'blur(20px)',
            border: '1px solid rgba(255,255,255,0.1)',
            borderRadius: 10,
            boxShadow: '0 12px 40px rgba(0,0,0,0.5)',
            padding: '6px',
            zIndex: 200,
          }}
        >
          {languages.map((item) => (
            <button
              key={item.code}
              onClick={() => { setLang(item.code as any); setOpen(false); }}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                width: '100%',
                padding: '8px 12px',
                borderRadius: 7,
                border: 'none',
                background: item.code === lang ? 'rgba(124,58,237,0.12)' : 'transparent',
                color: item.code === lang ? 'var(--text-heading)' : 'var(--text-muted)',
                fontSize: '0.8125rem',
                fontFamily: 'var(--font-sans)',
                cursor: 'pointer',
                transition: 'background 0.15s',
                textAlign: 'left',
              }}
              onMouseEnter={(e) => { if (item.code !== lang) e.currentTarget.style.background = 'rgba(255,255,255,0.04)'; }}
              onMouseLeave={(e) => { if (item.code !== lang) e.currentTarget.style.background = 'transparent'; }}
            >
              <span style={{ fontSize: 16 }}>{item.flag}</span>
              <span style={{ flex: 1 }}>{item.name}</span>
              {item.code === lang && (
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--primary-light)" strokeWidth="2.5">
                  <path d="M20 6L9 17l-5-5" />
                </svg>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
