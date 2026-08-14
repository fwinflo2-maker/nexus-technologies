/**
 * §4 / §13 / §14 / §22 — Fiche provider.
 *
 * Onglets : Overview, Credentials, Public Keys, Webhooks, Environment, Health,
 * Routing, Activity. Chaque onglet dit clairement ce qu'il ne sait pas plutôt
 * que d'afficher un placeholder rassurant.
 */
import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiControlProvider, type ProviderCard } from '../../api/client';
import { useCtrlT } from '../../data/control-i18n';
import { Badge, C, docTone, Grid, Metric, Note, Panel, State, Table, Td } from './ui';

type Tab = 'overview' | 'credentials' | 'keys' | 'webhooks' | 'environment' | 'health' | 'routing' | 'activity';

const TABS: Tab[] = ['overview', 'credentials', 'keys', 'webhooks', 'environment', 'health', 'routing', 'activity'];

/** Configuré = au moins un environnement renseigné. */
function anyConfigured(p: ProviderCard): boolean {
  return Object.values(p.environments ?? {}).some((e) => e.configured);
}

export default function ProviderDetailPage() {
  const t = useCtrlT();
  const { slug = '' } = useParams();
  const [data, setData] = useState<ProviderCard | null>(null);
  const [state, setState] = useState<'loading' | 'error' | 'ready'>('loading');
  const [tab, setTab] = useState<Tab>('overview');

  const load = useCallback(async () => {
    setState('loading');
    const res = await apiControlProvider(slug);
    if (res.success && res.data) {
      setData(res.data);
      setState('ready');
    } else {
      setState('error');
    }
  }, [slug]);

  useEffect(() => { void load(); }, [load]);

  if (state === 'loading') return <State kind="loading" label={t('ctrl.loading')} />;
  if (state === 'error' || !data) {
    return <State kind="error" label={t('ctrl.error')} onRetry={() => void load()} retryLabel={t('ctrl.retry')} />;
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div>
        <Link to="/control/providers" style={{ color: C.dim, fontSize: 12.5, textDecoration: 'none' }}>
          ← {t('ctrl.back')}
        </Link>
      </div>

      <Panel
        title={data.name}
        subtitle={`${data.slug} · ${data.category}`}
        right={
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Badge tone={anyConfigured(data) ? 'ok' : 'unknown'}>
              {anyConfigured(data) ? t('ctrl.pv.configured') : t('ctrl.pv.not_configured')}
            </Badge>
            <Badge tone={data.active_environment === 'production' ? 'warn' : 'info'}>
              {t(`ctrl.env.${data.active_environment}`)}
            </Badge>
          </div>
        }
      >
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 4 }}>
          {TABS.map((tb) => (
            <button
              key={tb}
              onClick={() => setTab(tb)}
              style={{
                padding: '7px 14px',
                borderRadius: 8,
                border: `1px solid ${tab === tb ? C.accent : C.border}`,
                background: tab === tb ? 'rgba(0,200,255,0.10)' : 'transparent',
                color: tab === tb ? C.accent : C.dim,
                fontSize: 12.5,
                fontWeight: tab === tb ? 600 : 500,
                cursor: 'pointer',
              }}
            >
              {t(`ctrl.pv.tab.${tb}`)}
            </button>
          ))}
        </div>
      </Panel>

      {tab === 'overview' && <OverviewTab data={data} t={t} />}
      {tab === 'health' && <HealthTab data={data} t={t} />}
      {tab === 'environment' && <EnvironmentTab data={data} t={t} />}
      {tab === 'credentials' && <CredentialsTab data={data} t={t} />}
      {tab === 'keys' && <KeysTab data={data} t={t} />}
      {(tab === 'webhooks' || tab === 'routing' || tab === 'activity') && (
        <Panel title={t(`ctrl.pv.tab.${tab}`)}>
          <Note tone="warn">{t('ctrl.pv.no_operation')}</Note>
          <State kind="empty" label={t('ctrl.empty')} />
        </Panel>
      )}
    </div>
  );
}

function OverviewTab({ data, t }: { data: ProviderCard; t: (k: string) => string }) {
  const ops = Object.entries(data.operations);
  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Panel title={t('ctrl.pv.operations')} subtitle={t('ctrl.pv.matrix_note')}>
        <Table head={[t('ctrl.pv.operations'), t('ctrl.pv.status')]}>
          {ops.map(([name, impl]) => (
            <tr key={name}>
              <Td mono>{name}</Td>
              <Td>
                <Badge tone={impl ? 'ok' : 'warn'}>
                  {impl ? t('ctrl.pv.implemented') : t('ctrl.pv.not_implemented')}
                </Badge>
              </Td>
            </tr>
          ))}
        </Table>
      </Panel>

      <Panel title={t('ctrl.pv.docs')}>
        <Grid min={150}>
          {Object.entries(data.documentation).map(([k, v]) => (
            <div key={k} style={{
              border: `1px solid ${C.border}`,
              borderRadius: 10,
              padding: '12px 14px',
            }}>
              <div style={{ fontSize: 11, color: C.dim, marginBottom: 8, textTransform: 'uppercase', letterSpacing: 0.6 }}>
                {t(`ctrl.pv.doc.${k}`)}
              </div>
              <Badge tone={docTone(v)}>{t(`ctrl.st.${v}`)}</Badge>
            </div>
          ))}
        </Grid>
      </Panel>

      <Panel title={t('ctrl.pv.methods')} subtitle={t('ctrl.pv.matrix_note')}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {data.payment_rails.length === 0
            ? <span style={{ color: C.unknown, fontSize: 12.5 }}>—</span>
            : data.payment_rails.map((m) => <Badge key={m} tone="info">{m}</Badge>)}
        </div>
        <div style={{ marginTop: 16, fontSize: 12, color: C.dim }}>
          {t('ctrl.pv.countries')} : {data.countries.length > 0 ? data.countries.join(', ') : '—'}
        </div>
      </Panel>
    </div>
  );
}

function HealthTab({ data, t }: { data: ProviderCard; t: (k: string) => string }) {
  const h = data.health;
  if (!h) return <Panel title={t('ctrl.hl.title')}><State kind="empty" label={t('ctrl.empty')} /></Panel>;

  const tone = (v: boolean | null) => (v === null ? 'unknown' : v ? 'ok' : 'danger');
  const label = (v: boolean | null) => (v === null ? t('ctrl.hl.unknown') : v ? t('ctrl.st.yes') : t('ctrl.st.no'));

  return (
    <Panel title={t('ctrl.hl.title')} subtitle={t('ctrl.hl.note')}>
      <Grid min={170}>
        <div style={{ border: `1px solid ${C.border}`, borderRadius: 10, padding: '12px 14px' }}>
          <div style={{ fontSize: 11, color: C.dim, marginBottom: 8 }}>{t('ctrl.hl.reachable')}</div>
          <Badge tone={tone(h.reachable)}>{label(h.reachable)}</Badge>
        </div>
        <div style={{ border: `1px solid ${C.border}`, borderRadius: 10, padding: '12px 14px' }}>
          <div style={{ fontSize: 11, color: C.dim, marginBottom: 8 }}>{t('ctrl.hl.authenticated')}</div>
          <Badge tone={tone(h.authenticated)}>{label(h.authenticated)}</Badge>
        </div>
        <Metric
          label={t('ctrl.hl.latency')}
          value={h.latency_ms === null ? '—' : `${h.latency_ms} ms`}
          tone={h.latency_ms === null ? 'unknown' : 'neutral'}
        />
      </Grid>
      {h.message && (
        <p style={{ marginTop: 14, fontSize: 12.5, color: C.dim, lineHeight: 1.5 }}>{h.message}</p>
      )}
    </Panel>
  );
}

function EnvironmentTab({ data, t }: { data: ProviderCard; t: (k: string) => string }) {
  return (
    <Panel title={t('ctrl.pv.tab.environment')} subtitle={t('ctrl.cr.per_env')}>
      <Table head={[t('ctrl.env.label'), 'Base URL', t('ctrl.pv.configured'), t('ctrl.cr.last_tested')]}>
        {(['sandbox', 'production'] as const).map((env) => {
          const e = data.environments?.[env];
          return (
            <tr key={env}>
              <Td>
                <Badge tone={env === 'production' ? 'warn' : 'info'}>{t(`ctrl.env.${env}`)}</Badge>
                {data.active_environment === env && (
                  <span style={{ marginInlineStart: 8, fontSize: 11, color: C.accent }}>●</span>
                )}
              </Td>
              <Td mono style={{ fontSize: 11.5 }}>{e?.base_url ?? '—'}</Td>
              <Td>
                <Badge tone={e?.configured ? 'ok' : 'unknown'}>
                  {e?.configured ? t('ctrl.st.yes') : t('ctrl.st.no')}
                </Badge>
              </Td>
              <Td mono style={{ fontSize: 11.5 }}>{e?.last_tested_at ?? t('ctrl.cr.never')}</Td>
            </tr>
          );
        })}
      </Table>
      {data.active_environment === 'production' && (
        <div style={{ marginTop: 14 }}><Note tone="warn">{t('ctrl.env.warning')}</Note></div>
      )}
    </Panel>
  );
}

function CredentialsTab({ data, t }: { data: ProviderCard; t: (k: string) => string }) {
  const schema = data.credential_schema;
  return (
    <Panel title={t('ctrl.cr.title')} subtitle={t('ctrl.cr.per_env')}>
      {!schema?.verified
        ? <Note tone="warn">{t('ctrl.cr.no_schema')}</Note>
        : <Note>{t('ctrl.key.never_shown')}</Note>}

      {schema?.verified && (
        <Table head={[
          t('ctrl.key.key'), t('ctrl.cr.required'), t('ctrl.key.sensitivity'),
          t('ctrl.key.exposure'), t('ctrl.key.usage'),
        ]}>
          {schema.credentials.map((f) => (
            <tr key={f.key}>
              <Td mono>
                {f.key}
                <div style={{ fontSize: 11, color: C.dim, fontFamily: 'inherit', marginTop: 2 }}>{f.label}</div>
              </Td>
              <Td>
                <Badge tone={f.required ? 'info' : 'unknown'}>
                  {f.required ? t('ctrl.cr.required') : t('ctrl.cr.optional')}
                </Badge>
              </Td>
              <Td><Badge tone={f.sensitivity === 'secret' ? 'warn' : 'ok'}>{f.sensitivity}</Badge></Td>
              <Td>
                <Badge tone={f.frontend_exposable ? 'ok' : 'warn'}>
                  {f.frontend_exposable ? t('ctrl.key.frontend') : t('ctrl.key.backend')}
                </Badge>
              </Td>
              <Td mono style={{ fontSize: 11.5 }}>{f.usage}</Td>
            </tr>
          ))}
        </Table>
      )}

      <div style={{ marginTop: 18 }}>
        <Table head={[t('ctrl.env.label'), t('ctrl.pv.configured'), t('ctrl.cr.updated')]}>
          {(['sandbox', 'production'] as const).map((env) => {
            const e = data.environments?.[env];
            return (
              <tr key={env}>
                <Td><Badge tone={env === 'production' ? 'warn' : 'info'}>{t(`ctrl.env.${env}`)}</Badge></Td>
                <Td>
                  <Badge tone={e?.configured ? 'ok' : 'unknown'}>
                    {e?.configured ? t('ctrl.st.yes') : t('ctrl.st.no')}
                  </Badge>
                </Td>
                <Td mono style={{ fontSize: 11.5 }}>{e?.updated_at ?? t('ctrl.cr.never')}</Td>
              </tr>
            );
          })}
        </Table>
      </div>
    </Panel>
  );
}

function KeysTab({ data, t }: { data: ProviderCard; t: (k: string) => string }) {
  return (
    <Panel title={t('ctrl.key.title')} subtitle={t('ctrl.key.note')}>
      {!data.credential_schema?.verified
        ? <Note tone="warn">{t('ctrl.cr.no_schema')}</Note>
        : <Note>{t('ctrl.key.never_shown')}</Note>}
      <p style={{ fontSize: 12.5, color: C.dim, margin: 0 }}>
        <Link to="/control/keys" style={{ color: C.accent, textDecoration: 'none' }}>
          {t('ctrl.key.title')} →
        </Link>
      </p>
    </Panel>
  );
}
