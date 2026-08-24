import { useCallback, useEffect, useState } from 'react';
import SettingsPage from '../dashboard/SettingsPage';
import { apiControlOverview, type ControlOverview } from '../../api/client';
import { Panel, Row, Badge, Stat } from './adminUi';

/**
 * Paramètres Super Admin.
 *
 * Deux blocs :
 *  1. Plateforme — état réel exposé par /api/control/overview (environnement,
 *     mode strict, providers configurés, credentials par environnement).
 *  2. Compte administrateur — réutilisation de la page Paramètres client
 *     (profil, avatar, mot de passe, sessions, préférences) branchée sur les
 *     mêmes API réelles (/api/users/me, /api/users/me/password, sessions).
 */
export default function AdminSettings() {
  const [ctrl, setCtrl] = useState<ControlOverview | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');

  const load = useCallback(async () => {
    setState('loading');
    const response = await apiControlOverview();
    if (!response.success || !response.data) { setState('error'); return; }
    setCtrl(response.data);
    setState('ready');
  }, []);
  useEffect(() => { void load(); }, [load]);

  const env = ctrl?.environment ?? 'Indisponible';
  const envProd = !!ctrl?.is_production;
  const creds = ctrl?.credentials;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
      {/* ═══ 1. Plateforme ═══ */}
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
          <span style={{ fontSize: 15 }}>🛰️</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>
            Configuration de la plateforme
          </span>
          {state === 'error' && (
            <span style={{ fontSize: 11, color: 'var(--gold)' }}>État indisponible <button className="btn btn-ghost" onClick={() => void load()}>Réessayer</button></span>
          )}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginBottom: 12 }}>
          <Stat
            label="Environnement"
            value={
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <span
                  style={{
                    width: 8, height: 8, borderRadius: '50%', display: 'inline-block',
                    background: envProd ? 'var(--green)' : 'var(--gold)',
                    boxShadow: `0 0 8px ${envProd ? 'var(--green)' : 'var(--gold)'}`,
                  }}
                />
                {ctrl ? (envProd ? 'Production' : 'Sandbox') : 'Indisponible'}
              </span>
            }
            sub={env}
          />
          <Stat
            label="Mode strict"
            value={ctrl ? (ctrl.strict_mode ? 'Activé' : 'Désactivé') : '—'}
            sub="Routing limité aux providers fiables"
            tone={ctrl?.strict_mode ? 'var(--gold)' : 'var(--text-mid)'}
          />
          <Stat
            label="Providers configurés"
            value={ctrl ? `${ctrl.providers.configured}/${ctrl.providers.total}` : '—'}
            sub={`${ctrl?.providers.enabled ?? 0} activés · ${ctrl?.providers.with_operations ?? 0} opérationnels`}
          />
          <Stat
            label="Credentials"
            value={creds ? `${creds.sandbox} / ${creds.production}` : '—'}
            sub="Sandbox / Production"
          />
        </div>

        <Panel title="État de la plateforme" icon="⚙️" pad={16}>
          <Row k="Environnement actif" v={<Badge status={envProd ? 'operational' : 'sandbox'} label={envProd ? 'Production' : 'Sandbox'} />} />
          <Row k="Mode strict" v={ctrl?.strict_mode ? 'Activé' : 'Désactivé'} tone={ctrl?.strict_mode ? 'var(--gold)' : 'var(--text-dim)'} />
          <Row k="Providers au catalogue" v={ctrl?.providers.total ?? '—'} />
          <Row k="Providers activés" v={ctrl?.providers.enabled ?? '—'} />
          <Row k="Providers configurés (credentials)" v={`${ctrl?.providers.configured ?? '—'} / ${ctrl?.providers.total ?? '—'}`} />
          <Row k="Schémas de credentials vérifiés" v={ctrl?.providers.schema_verified ?? '—'} />
          <Row k="Providers avec opérations implémentées" v={ctrl?.providers.with_operations ?? '—'} />
          {creds && (
            <Row k="Credentials enregistrées" v={`${creds.sandbox} sandbox · ${creds.production} production`} />
          )}
        </Panel>
      </div>

      {/* ═══ 2. Compte administrateur ═══ */}
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
          <span style={{ fontSize: 15 }}>👤</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>
            Compte administrateur
          </span>
        </div>
        <SettingsPage hideHeader />
      </div>
    </div>
  );
}
