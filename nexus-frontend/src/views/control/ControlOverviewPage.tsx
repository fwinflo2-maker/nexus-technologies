/**
 * §2 / §24 — Vue d'ensemble du Control Center.
 *
 * Chaque chiffre provient du backend, mesuré sur le système réel. Aucun KPI
 * inventé, aucune barre de progression décorative.
 */
import { useCallback, useEffect, useState } from 'react';
import { apiControlOverview, type ControlOverview } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { Badge, C, Grid, Metric, Note, Panel, State, Table, Td } from './ui';

export default function ControlOverviewPage() {
  const t = useCtrlT();
  const [data, setData] = useState<ControlOverview | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlOverview();
    if (res.success && res.data) {
      setData(res.data);
      setState('ready');
    } else {
      setState('error');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  if (state === 'loading') return <State kind="loading" label={t('ctrl.loading')} />;
  if (state === 'error' || !data) {
    return <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />;
  }

  const p = data.providers;
  const kycRows = [
    ...data.kyc.individual.map((r) => ({ ...r, kind: t('ctrl.kyc.individual') })),
    ...data.kyc.company.map((r) => ({ ...r, kind: t('ctrl.kyc.company') })),
  ];

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Panel title={t('ctrl.nav.overview')} subtitle={t('ctrl.ov.honesty')}>
        <Grid>
          <Metric label={t('ctrl.ov.providers')} value={p.total} />
          <Metric
            label={t('ctrl.ov.schema_verified')}
            value={`${p.schema_verified}/${p.total}`}
            tone={p.schema_verified > 0 ? 'ok' : 'unknown'}
          />
          <Metric label={t('ctrl.ov.configured')} value={p.configured} tone={p.configured > 0 ? 'ok' : 'unknown'} />
          <Metric label={t('ctrl.ov.enabled')} value={p.enabled} tone={p.enabled > 0 ? 'ok' : 'unknown'} />
          <Metric
            label={t('ctrl.ov.with_operations')}
            value={p.with_operations}
            tone={p.with_operations > 0 ? 'ok' : 'warn'}
            hint={p.with_operations === 0 ? t('ctrl.pv.no_operation') : undefined}
          />
        </Grid>
      </Panel>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16 }}>
        <Panel title={t('ctrl.ov.credentials')} subtitle={t('ctrl.cr.per_env')}>
          <Grid min={140}>
            <Metric label={t('ctrl.env.sandbox')} value={data.credentials.sandbox} />
            <Metric label={t('ctrl.env.production')} value={data.credentials.production} tone={data.credentials.production > 0 ? 'warn' : 'neutral'} />
          </Grid>
        </Panel>

        <Panel title={t('ctrl.nav.system')}>
          <div style={{ display: 'grid', gap: 10 }}>
            <Row label={t('ctrl.env.label')}>
              <Badge tone={data.is_production ? 'warn' : 'info'}>
                {data.is_production ? t('ctrl.env.production') : t('ctrl.env.sandbox')}
              </Badge>
            </Row>
            <Row label={t('ctrl.ov.strict')}>
              <Badge tone={data.strict_mode ? 'ok' : 'unknown'}>
                {data.strict_mode ? t('ctrl.st.yes') : t('ctrl.ov.demo')}
              </Badge>
            </Row>
            <Row label={t('ctrl.ov.webhooks')}>
              <span style={{ color: C.text, fontWeight: 600 }}>{data.webhooks.processed_total}</span>
            </Row>
          </div>
        </Panel>
      </div>

      <Panel title={t('ctrl.nav.kyc')} subtitle={t('ctrl.kyc.no_docs')}>
        {!data.kyc.provider.configured && (
          <Note tone="warn">{t('ctrl.kyc.not_configured')} — {data.kyc.provider.slug}</Note>
        )}
        {kycRows.length === 0 ? (
          <State kind="empty" label={t('ctrl.empty')} />
        ) : (
          <Table head={[t('ctrl.kyc.applicant'), t('ctrl.pv.status'), t('ctrl.total')]}>
            {kycRows.map((r, i) => (
              <tr key={i}>
                <Td>{r.kind}</Td>
                <Td mono>{String(r.status)}</Td>
                <Td mono>{r.total}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
      <span style={{ fontSize: 12.5, color: C.dim }}>{label}</span>
      {children}
    </div>
  );
}
