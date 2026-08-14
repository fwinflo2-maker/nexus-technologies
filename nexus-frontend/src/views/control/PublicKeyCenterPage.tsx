/**
 * §7 / §8 — Centre des clés publiques.
 *
 * Sépare visuellement FRONTEND SAFE et BACKEND ONLY. La classification vient
 * du backend (schéma de credentials), jamais d'une heuristique sur le nom de
 * la clé. Aucune valeur de secret n'est affichée : seul l'état « configuré ».
 */
import { useCallback, useEffect, useState } from 'react';
import { apiControlPublicKeys, type PublicKeyRow } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { Badge, C, Note, Panel, State, Table, Td } from './ui';

export default function PublicKeyCenterPage() {
  const t = useCtrlT();
  const [rows, setRows] = useState<PublicKeyRow[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlPublicKeys();
    if (res.success && res.data) {
      setRows(res.data.items);
      setState('ready');
    } else {
      setState('error');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  if (state === 'loading') return <State kind="loading" label={t('ctrl.loading')} />;
  if (state === 'error') return <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />;

  const frontend = rows.filter((r) => r.exposure === 'frontend');
  const backend = rows.filter((r) => r.exposure === 'backend');

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Panel title={t('ctrl.key.title')} subtitle={t('ctrl.key.note')}>
        <Note>{t('ctrl.key.never_shown')}</Note>

        <h3 style={{ margin: '4px 0 10px', fontSize: 12, letterSpacing: 0.8, color: C.ok }}>
          {t('ctrl.key.frontend')} · {frontend.length}
        </h3>
        {frontend.length === 0 ? (
          <State kind="empty" label={t('ctrl.empty')} />
        ) : (
          <KeyTable rows={frontend} t={t} tone="ok" />
        )}

        <h3 style={{ margin: '26px 0 10px', fontSize: 12, letterSpacing: 0.8, color: C.warn }}>
          {t('ctrl.key.backend')} · {backend.length}
        </h3>
        {backend.length === 0 ? (
          <State kind="empty" label={t('ctrl.empty')} />
        ) : (
          <KeyTable rows={backend} t={t} tone="warn" />
        )}
      </Panel>
    </div>
  );
}

function KeyTable({ rows, t, tone }: { rows: PublicKeyRow[]; t: (k: string) => string; tone: 'ok' | 'warn' }) {
  return (
    <Table head={[
      t('ctrl.pv.name'),
      t('ctrl.key.key'),
      t('ctrl.env.label'),
      t('ctrl.key.sensitivity'),
      t('ctrl.pv.configured'),
      t('ctrl.key.justification'),
    ]}>
      {rows.map((r, i) => (
        <tr key={`${r.provider}.${r.key}.${r.environment}.${i}`}>
          <Td>{r.provider_name}</Td>
          <Td mono>
            {r.key}
            <div style={{ fontSize: 11, color: C.dim, fontFamily: 'inherit', marginTop: 2 }}>{r.label}</div>
          </Td>
          <Td>
            <Badge tone={r.environment === 'production' ? 'warn' : 'info'}>
              {t(`ctrl.env.${r.environment}`)}
            </Badge>
          </Td>
          <Td>
            <Badge tone={tone}>{r.sensitivity}</Badge>
          </Td>
          <Td>
            <Badge tone={r.configured ? 'ok' : 'unknown'}>
              {r.configured ? t('ctrl.st.yes') : t('ctrl.st.no')}
            </Badge>
          </Td>
          <Td style={{ maxWidth: 340, color: C.dim, fontSize: 12, whiteSpace: 'normal' }}>
            {r.justification || '—'}
          </Td>
        </tr>
      ))}
    </Table>
  );
}
