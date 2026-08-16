/* ════════════════════════════════════════════════════════════════════════
 * SAFE STORAGE — accès aux stockages navigateur sans crash cross-platform.
 *
 * Problèmes résolus :
 *  - Certains navigateurs (Safari mode privé, Firefox avec cookies bloqués,
 *    quelques vieux WebViews) lancent une SecurityError/QuotaExceededError
 *    sur localStorage/sessionStorage.
 *  - Le décodage JWT en base64url échoue avec `atob()` quand le payload
 *    contient les caractères `-` / `_` (base64url) → l'utilisateur était
 *    déconnecté à chaque rechargement.
 *  - `atob`/`btoa` absents dans certains environnements non navigateur.
 *
 * Toutes les fonctions sont sans exception : elles retombent sur un cache
 * en mémoire si le storage est indisponible, et ne cassent jamais l'app.
 * ════════════════════════════════════════════════════════════════════════ */

/** Cache en mémoire de repli quand le storage navigateur est indisponible. */
const memoryStore = new Map<string, string>();

/** Tente d'accéder à un Storage (local/session) ; null si indisponible. */
function getStore(kind: 'local' | 'session'): Storage | null {
  try {
    const g = globalThis as Record<string, unknown>;
    const store = kind === 'local' ? g.localStorage : g.sessionStorage;
    // Tester l'accès réel (certains navigateurs exposent l'objet mais lancent à l'écriture).
    if (store && typeof (store as Storage).getItem === 'function') {
      const probe = '__nexus_probe__';
      (store as Storage).setItem(probe, '1');
      (store as Storage).removeItem(probe);
      return store as Storage;
    }
  } catch {
    /* storage bloqué → on retombe sur la mémoire */
  }
  return null;
}

export const safeStorage = {
  get(kind: 'local' | 'session', key: string): string | null {
    const store = getStore(kind);
    if (store) {
      try { return store.getItem(key); } catch { /* fallback */ }
    }
    return memoryStore.get(kind + ':' + key) ?? null;
  },
  set(kind: 'local' | 'session', key: string, value: string): void {
    memoryStore.set(kind + ':' + key, value);
    const store = getStore(kind);
    if (store) {
      try { store.setItem(key, value); } catch { /* mémoire déjà écrite */ }
    }
  },
  remove(kind: 'local' | 'session', key: string): void {
    memoryStore.delete(kind + ':' + key);
    const store = getStore(kind);
    if (store) {
      try { store.removeItem(key); } catch { /* ignore */ }
    }
  },
};

/* ═══ Décodage JWT robuste (base64url) ═══════════════════════════════════ */

function atobUniversal(input: string): string {
  if (typeof atob === 'function') {
    // Le payload JWT est en base64url : `-` et `_` ne sont pas du base64 standard.
    const b64 = input.replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64.padEnd(Math.ceil(b64.length / 4) * 4, '=');
    try { return atob(padded); } catch { /* fallback ci-dessous */ }
  }
  // Repli sans atob (WebViews anciennes) via Buffer si dispo, sinon échec propre.
  const g = globalThis as Record<string, unknown>;
  if (typeof (g as { Buffer?: unknown }).Buffer !== 'undefined') {
    return (g as { Buffer: { from: (s: string, e: 'base64') => { toString: (e: 'utf8') => string } } }).Buffer.from(
      input.replace(/-/g, '+').replace(/_/g, '/'),
      'base64',
    ).toString('utf8');
  }
  throw new Error('Base64 decoding unavailable');
}

/**
 * Décode et retourne le payload d'un JWT sans jamais lever d'exception.
 * Retourne null si le token est invalide ou illisible.
 */
export function decodeJwtPayload(token: string): Record<string, unknown> | null {
  if (!token || token.split('.').length < 2) return null;
  try {
    const part = token.split('.')[1];
    return JSON.parse(atobUniversal(part)) as Record<string, unknown>;
  } catch {
    return null;
  }
}

/** Le token est-il expiré selon son claim `exp` ? (false si inconnu/absurde.) */
export function isTokenExpired(token: string): boolean {
  const payload = decodeJwtPayload(token);
  if (!payload) return false;
  const exp = Number(payload.exp);
  if (!Number.isFinite(exp)) return false;
  return Date.now() >= exp * 1000;
}
