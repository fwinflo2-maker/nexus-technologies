/**
 * §26 — Centre d'audit.
 *
 * Lecture seule du journal `audit_logs`. Les métadonnées sont affichées telles
 * qu'enregistrées : elles ne contiennent jamais de secret.
 */
import { useCallback, useEffect, useState } from 'react';
import { apiControlAudit } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { C, Panel, State, Table, Td } from './ui';

interface AuditRow {
  id: number;
  actor: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  ip_address: string | null;
  created_at: string;
}

export default function ControlAuditPage() {
  const t = useCtrlT();
  const [rows, setRows] = useState<AuditRow[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlAudit();
    if (res.success && res.data) {
      setRows(res.data.items as unknown as AuditRow[]);
      setState('ready');
    } else {
      setState('error');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  if (state === 'loading') return <State kind="loading" label={t('ctrl.loading')} />;
  if (state === 'error') return <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />;

  return (
    <Panel title={t('ctrl.au.title')} right={
      <button onClick={() => void load()} style={{
        padding: '6px 14px', borderRadius: 8, border: `1px solid ${C.borderStrong}`,
        background: 'transparent', color: C.dim, fontSize: 12, cursor: 'pointer',
      }}>{t('ctrl.refresh')}</button>
    }>
      {rows.length === 0 ? (
        <State kind="empty" label={t('ctrl.empty')} />
      ) : (
        <Table head={[t('ctrl.au.date'), t('ctrl.au.actor'), t('ctrl.au.action'), t('ctrl.au.entity'), t('ctrl.au.ip')]}>
          {rows.map((r) => (
            <tr key={r.id}>
              <Td mono>{r.created_at}</Td>
              <Td>{r.actor ?? '—'}</Td>
              <Td mono>{r.action}</Td>
              <Td>{r.entity_type ? `${r.entity_type}${r.entity_id ? ` #${r.entity_id}` : ''}` : '—'}</Td>
              <Td mono>{r.ip_address ?? '—'}</Td>
            </tr>
          ))}
        </Table>
      )}
    </Panel>
  );
}
