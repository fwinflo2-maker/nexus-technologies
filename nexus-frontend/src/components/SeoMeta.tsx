import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { useI18n } from '../context/I18nContext';

/**
 * Runtime SEO for the SPA shell: syncs <title> and key meta tags with route + language.
 * Crawlers that only read index.html still get the static French defaults.
 */
const ROUTE_SEO: Record<string, { titleKey: string; descKey: string }> = {
  '/': { titleKey: 'seo_home_title', descKey: 'seo_home_description' },
  '/login': { titleKey: 'seo_login_title', descKey: 'seo_login_description' },
  '/register': { titleKey: 'seo_register_title', descKey: 'seo_register_description' },
  '/forgot-password': { titleKey: 'seo_forgot_title', descKey: 'seo_forgot_description' },
  '/privacy': { titleKey: 'seo_privacy_title', descKey: 'seo_privacy_description' },
  '/terms': { titleKey: 'seo_terms_title', descKey: 'seo_terms_description' },
  '/docs': { titleKey: 'seo_docs_title', descKey: 'seo_docs_description' },
  '/support': { titleKey: 'seo_support_title', descKey: 'seo_support_description' },
};

function upsertMeta(attr: 'name' | 'property', key: string, content: string) {
  let el = document.head.querySelector(`meta[${attr}="${key}"]`) as HTMLMetaElement | null;
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, key);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

export function SeoMeta() {
  const { pathname } = useLocation();
  const { t, lang } = useI18n();

  useEffect(() => {
    const route = ROUTE_SEO[pathname];
    // Authenticated app shells keep their own chrome; don't overwrite with marketing SEO.
    if (!route) return;

    const title = t(route.titleKey);
    const description = t(route.descKey);

    document.title = title;
    upsertMeta('name', 'description', description);
    upsertMeta('property', 'og:title', title);
    upsertMeta('property', 'og:description', description);
    upsertMeta('name', 'twitter:title', title);
    upsertMeta('name', 'twitter:description', description);

    const origin = window.location.origin;
    const url = `${origin}${pathname === '/' ? '/' : pathname}`;
    const canonical = document.head.querySelector('link[rel="canonical"]') as HTMLLinkElement | null;
    if (canonical) canonical.href = url;
    upsertMeta('property', 'og:url', url);

    const image = `${origin}/og-image.png`;
    upsertMeta('property', 'og:image', image);
    upsertMeta('name', 'twitter:image', image);

    const ogLocale = lang === 'en' ? 'en_US'
      : lang === 'es' ? 'es_ES'
      : lang === 'pt' ? 'pt_PT'
      : lang === 'de' ? 'de_DE'
      : lang === 'ar' ? 'ar'
      : lang === 'zh' ? 'zh_CN'
      : 'fr_FR';
    upsertMeta('property', 'og:locale', ogLocale);
  }, [pathname, lang, t]);

  return null;
}
