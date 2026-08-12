import { createContext, useContext, useState, useMemo, type ReactNode } from 'react';
import { translations, type LangCode } from '../data/translations';

interface I18nContextValue {
  lang: LangCode;
  setLang: (l: LangCode) => void;
  t: (key: string) => string;
}

const I18nContext = createContext<I18nContextValue>({
  lang: 'fr',
  setLang: () => {},
  t: (k) => k,
});

export function I18nProvider({ children }: { children: ReactNode }) {
  const [lang, setLang] = useState<LangCode>(() => {
    const saved = localStorage.getItem('nexus_lang') as LangCode | null;
    return saved && translations[saved] ? saved : 'fr';
  });

  const value = useMemo<I18nContextValue>(() => {
    const dict = translations[lang];
    const t = (key: string) => dict[key] ?? translations.fr[key] ?? key;
    const setLangFn = (l: LangCode) => {
      setLang(l);
      localStorage.setItem('nexus_lang', l);
      document.documentElement.lang = l;
    };
    return { lang, setLang: setLangFn, t };
  }, [lang]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useI18n() {
  return useContext(I18nContext);
}
