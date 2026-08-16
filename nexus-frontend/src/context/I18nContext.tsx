import { createContext, useContext, useEffect, useState, useMemo, type ReactNode } from 'react';
import { translations, type LangCode } from '../data/translations';
import { safeStorage } from '../lib/safeStorage';

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
    const saved = safeStorage.get('local', 'nexus_lang') as LangCode | null;
    return saved && translations[saved] ? saved : 'fr';
  });

  // RTL : l'arabe bascule le document en direction droite-à-gauche.
  useEffect(() => {
    document.documentElement.lang = lang;
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
  }, [lang]);

  const value = useMemo<I18nContextValue>(() => {
    const dict = translations[lang];
    const t = (key: string) => dict[key] ?? translations.fr[key] ?? key;
    const setLangFn = (l: LangCode) => {
      setLang(l);
      safeStorage.set('local', 'nexus_lang', l);
    };
    return { lang, setLang: setLangFn, t };
  }, [lang]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

// eslint-disable-next-line react-refresh/only-export-components
export function useI18n() {
  return useContext(I18nContext);
}
