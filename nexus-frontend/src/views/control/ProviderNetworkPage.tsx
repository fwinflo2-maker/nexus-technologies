/**
 * §3 / §21 / §22 — Réseau de providers et matrice.
 *
 * Distinction fondamentale affichée à l'écran :
 *   « rails de paiement » = capacités DÉCLARÉES par le catalogue ;
 *   « opérations »        = méthodes réellement IMPLÉMENTÉES, détectées dans
 *                           le code par le backend.
 * Un provider peut annoncer 5 rails et n'avoir aucune opération : l'écran doit
 * le montrer sans ambiguïté plutôt que de laisser croire à une intégration.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiControlProviders, type ProviderCard } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { Badge, C, docTone, Grid, Metric, Note, Panel, State, Table, Td } from './ui';

/** Un provider est « configuré » si au moins un environnement l'est. */
function isConfigured(p: ProviderCard): boolean {
  return Object.values(p.environments ?? {}).some((e) => e.configured);
}

/** Nombre d'opérations réellement implémentées. */
function countOps(p: ProviderCard): number {
  return Object.values(p.operations ?? {}).filter(Boolean).length;
}

export default function ProviderNetworkPage() {
  const t = useCtrlT();
  const [items, setItems] = useState<ProviderCard[]>([]);
  const [operations, setOperations] = useState<string[]>([]);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [q, setQ] = useState('');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlProviders();
    if (res.success && res.data) {
      setItems(res.data.items);
      setOperations(res.data.operations);
      setState('ready');
    } else {
      setState('error');
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return items;
    return items.filter((i) =>
      i.name.toLowerCase().includes(needle) ||
      i.slug.toLowerCase().includes(needle) ||
      i.category.toLowerCase().includes(needle));
  }, [items, q]);

  if (state === 'loading') return <State kind="loading" label={t('ctrl.loading')} />;
  if (state === 'error') return <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />;

  const verified = items.filter((i) => i.credential_schema?.verified === true).length;
  const withOps = items.filter((i) => i.operations_enabled).length;

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Panel title={t('ctrl.nav.providers')}>
        <Grid>
          <Metric label={t('ctrl.total')} value={items.length} />
          <Metric label={t('ctrl.ov.schema_verified')} value={verified} tone={verified > 0 ? 'ok' : 'unknown'} />
          <Metric label={t('ctrl.pv.configured')} value={items.filter(isConfigured).length} />
          <Metric label={t('ctrl.ov.with_operations')} value={withOps} tone={withOps > 0 ? 'ok' : 'warn'} />
        </Grid>
      </Panel>

      <Panel
        title={t('ctrl.pv.matrix')}
        subtitle={t('ctrl.pv.matrix_note')}
        right={
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={t('ctrl.search')}
            style={{
              padding: '7px 12px',
              borderRadius: 8,
              border: `1px solid ${C.borderStrong}`,
              background: 'rgba(0,0,0,0.25)',
              color: C.text,
              fontSize: 12.5,
              minWidth: 180,
            }}
          />
        }
      >
        {filtered.length === 0 ? (
          <State kind="empty" label={t('ctrl.empty')} />
        ) : (
          <Table head={[
            t('ctrl.pv.name'),
            t('ctrl.pv.category'),
            t('ctrl.pv.methods'),
            t('ctrl.pv.operations'),
            t('ctrl.pv.doc.documentation'),
            t('ctrl.pv.status'),
          ]}>
            {filtered.map((p) => (
              <tr key={p.slug}>
                <Td>
                  <Link
                    to={`/control/provider/${p.slug}`}
                    style={{ color: C.accent, textDecoration: 'none', fontWeight: 600 }}
                  >
                    {p.name}
                  </Link>
                  <div style={{ fontSize: 11, color: C.dim, marginTop: 2 }}>{p.slug}</div>
                </Td>
                <Td>{p.category}</Td>
                <Td>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, maxWidth: 260 }}>
                    {p.payment_rails.length === 0
                      ? <span style={{ color: C.unknown }}>—</span>
                      : p.payment_rails.map((m) => <Badge key={m} tone="info">{m}</Badge>)}
                  </div>
                </Td>
                <Td>
                  {countOps(p) === 0 ? (
                    <Badge tone="warn" title={t('ctrl.pv.matrix_note')}>
                      0 / {operations.length}
                    </Badge>
                  ) : (
                    <Badge tone="ok">{countOps(p)} / {operations.length}</Badge>
                  )}
                </Td>
                <Td>
                  <Badge tone={docTone(p.documentation.documentation ?? 'unknown')}>
                    {t(`ctrl.st.${p.documentation.documentation ?? 'unknown'}`)}
                  </Badge>
                </Td>
                <Td>
                  <Badge tone={isConfigured(p) ? 'ok' : 'unknown'}>
                    {isConfigured(p) ? t('ctrl.pv.configured') : t('ctrl.pv.not_configured')}
                  </Badge>
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>

      {withOps === 0 && (
        <Note tone="warn">{t('ctrl.pv.no_operation')} — {t('ctrl.pv.matrix_note')}</Note>
      )}
    </div>
  );
}
