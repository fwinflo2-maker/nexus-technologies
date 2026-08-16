import { useEffect, useState } from 'react';
import { apiAdminRisk } from '../../api/client';
import { Stat, Table, fmtMoney, fmtDate } from './adminUi';
import { ResponsiveContainer, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { ChartTip } from './CockpitCharts';

interface FailedTx { id: number; label: string; amount: number; currency: string; provider: string | null; created_at: string; user_email: string | null; }

export default function AdminRisk() {
  const [risk, setRisk] = useState({ suspended_accounts: 0, failed_transactions: 0, kyc_rejected: 0, kyc_resubmission: 0, failed_rate: 0 });
  const [recent, setRecent] = useState<FailedTx[]>([]);
  const [byProvider, setByProvider] = useState<Array<{ provider: string; n: number; fails: number; fail_rate: number }>>([]);
  useEffect(() => {
    void apiAdminRisk().then((res) => { if (res.success && res.data) { setRisk(res.data.risk); setRecent(res.data.recent_failed as unknown as FailedTx[]); setByProvider(res.data.by_provider); } });
  }, []);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Taux d'échec" value={`${risk.failed_rate}%`} tone={risk.failed_rate > 15 ? 'var(--red)' : 'var(--green)'} />
        <Stat label="Échecs (14j)" value={risk.failed_transactions} tone="var(--red)" />
        <Stat label="Comptes suspendus" value={risk.suspended_accounts} tone="var(--gold)" />
        <Stat label="KYC à re-soumettre" value={risk.kyc_resubmission} tone="var(--gold)" />
      </div>

      <div className="g2">
        <div className="card" style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)' }}>📊 Taux d'échec par provider</div>
          <div style={{ height: 220 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={byProvider} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <CartesianGrid stroke="rgba(255,255,255,0.06)" vertical={false} />
                <XAxis dataKey="provider" tick={{ fill: 'var(--text-mid)', fontSize: 10 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fill: 'var(--text-mid)', fontSize: 10 }} axisLine={false} tickLine={false} unit="%" width={34} />
                <Tooltip content={<ChartTip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
                <Bar dataKey="fail_rate" name="Taux d'échec" fill="var(--red)" radius={[5, 5, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </div>
        <div className="card" style={{ padding: 14 }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🚨 Échecs récents à surveiller</div>
          <Table
            head={['ID', 'Client', 'Libellé', 'Montant', 'Provider', 'Heure']}
            rows={recent.map((t) => [
              <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>#{t.id}</span>,
              <span style={{ color: 'var(--text-bright)' }}>{t.user_email ?? '—'}</span>,
              <span>{t.label}</span>,
              <span style={{ fontFamily: 'var(--font-mono)' }}>{fmtMoney(Number(t.amount), t.currency)}</span>,
              <span style={{ textTransform: 'capitalize' }}>{t.provider ?? '—'}</span>,
              <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{fmtDate(t.created_at)}</span>,
            ])}
          />
        </div>
      </div>
    </div>
  );
}
