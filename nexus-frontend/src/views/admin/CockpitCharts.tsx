import React from 'react';
import {
  ResponsiveContainer, AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
  Tooltip, XAxis, YAxis, CartesianGrid,
} from 'recharts';

/* Palette Revolut — lue depuis les variables CSS (single accent bleu). */
const C = {
  cyan: 'var(--cyan)',
  cyan2: 'var(--cyan2)',
  green: 'var(--green)',
  gold: 'var(--gold)',
  red: 'var(--red)',
  violet: 'var(--violet)',
  text: 'var(--text-mid)',
  textBright: 'var(--text-bright)',
  grid: 'rgba(255,255,255,0.06)',
};

/** Tooltip sombre unifié pour tous les graphiques. */
export const ChartTip = ({ active, payload, label, fmt }: {
  active?: boolean; payload?: Array<{ name?: string; value?: number | string; color?: string }>;
  label?: string | number; fmt?: (v: number) => string;
}) => {
  if (!active || !payload || payload.length === 0) return null;
  return (
    <div style={{
      background: 'var(--panel2)', border: '1px solid var(--border-soft)', borderRadius: 10,
      padding: '8px 12px', fontSize: 12, boxShadow: 'var(--shadow-lg)', minWidth: 140,
    }}>
      {label !== undefined && <div style={{ color: 'var(--text-dim)', marginBottom: 4, fontSize: 11 }}>{label}</div>}
      {payload.map((p, i) => (
        <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 16, padding: '2px 0' }}>
          <span style={{ color: 'var(--text-mid)', textTransform: 'capitalize' }}>{p.name}</span>
          <span style={{ color: 'var(--text-bright)', fontWeight: 700, fontFamily: 'var(--font-mono)' }}>
            {fmt ? fmt(Number(p.value)) : String(p.value)}
          </span>
        </div>
      ))}
    </div>
  );
};

function ChartCard({ title, icon, right, children }: {
  title: string; icon?: string; right?: React.ReactNode; children: React.ReactNode;
}) {
  return (
    <div className="card" style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 10, height: '100%' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {icon && <span style={{ fontSize: 15 }}>{icon}</span>}
          <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', letterSpacing: 0.3 }}>{title}</span>
        </div>
        {right}
      </div>
      <div style={{ flex: 1, minHeight: 220 }}>{children}</div>
    </div>
  );
}

const nf = (n: number) => n.toLocaleString('fr-FR');
const eur = (n: number) => `${nf(Math.round(n))} €`;

/** Évolution du volume (EUR) sur 14 jours. */
export function VolumeAreaChart({ data }: { data: Array<{ date: string; volume: number }> }) {
  return (
    <ChartCard title="Volume traité (EUR)" icon="📈">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 8, right: 6, left: 6, bottom: 0 }}>
          <defs>
            <linearGradient id="volGrad" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--cyan)" stopOpacity={0.45} />
              <stop offset="100%" stopColor="var(--cyan)" stopOpacity={0.02} />
            </linearGradient>
          </defs>
          <CartesianGrid stroke={C.grid} vertical={false} />
          <XAxis dataKey="date" tick={{ fill: C.text, fontSize: 10 }} tickFormatter={(d: string) => d.slice(8, 10)} axisLine={false} tickLine={false} />
          <YAxis tick={{ fill: C.text, fontSize: 10 }} axisLine={false} tickLine={false} tickFormatter={(v: number) => (v >= 1000 ? `${Math.round(v / 1000)}k` : String(v))} width={42} />
          <Tooltip content={<ChartTip fmt={eur} />} />
          <Area type="monotone" dataKey="volume" stroke="var(--cyan)" strokeWidth={2.5} fill="url(#volGrad)" name="Volume" />
        </AreaChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}

/** Transactions par jour (barres empilées par statut). */
export function TransactionsStackChart({ data }: { data: Array<{ date: string; count: number }> }) {
  return (
    <ChartCard title="Transactions par jour" icon="🔄">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 6, left: 6, bottom: 0 }}>
          <CartesianGrid stroke={C.grid} vertical={false} />
          <XAxis dataKey="date" tick={{ fill: C.text, fontSize: 10 }} tickFormatter={(d: string) => d.slice(8, 10)} axisLine={false} tickLine={false} />
          <YAxis tick={{ fill: C.text, fontSize: 10 }} axisLine={false} tickLine={false} width={30} allowDecimals={false} />
          <Tooltip content={<ChartTip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
          <Bar dataKey="count" name="Transactions" fill="var(--cyan)" radius={[5, 5, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}

/** Répartition des actifs par devise (donut). */
export function AssetDonut({ data }: { data: Array<{ name: string; value: number; color: string }> }) {
  return (
    <ChartCard title="Actifs par devise" icon="💰">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" innerRadius="58%" outerRadius="82%" paddingAngle={3} stroke="none">
            {data.map((d, i) => <Cell key={i} fill={d.color} />)}
          </Pie>
          <Tooltip content={<ChartTip fmt={nf} />} />
        </PieChart>
      </ResponsiveContainer>
      <div style={{ display: 'flex', gap: 14, justifyContent: 'center', flexWrap: 'wrap', marginTop: 4 }}>
        {data.map((d, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11.5, color: 'var(--text-mid)' }}>
            <span style={{ width: 9, height: 9, borderRadius: 3, background: d.color }} />
            {d.name}
            <b style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{nf(d.value)}</b>
          </div>
        ))}
      </div>
    </ChartCard>
  );
}

/** Statuts des transactions (donut). */
export function StatusDonut({ data }: { data: Array<{ status: string; count: number }> }) {
  const colorFor = (s: string) =>
    s === 'completed' ? 'var(--green)' : s === 'failed' ? 'var(--red)' : s === 'processing' ? 'var(--gold)' : 'var(--cyan2)';
  const rows = data.map((d) => ({ name: d.status, value: d.count, color: colorFor(d.status) }));
  return (
    <ChartCard title="Statut des transactions" icon="🛰️">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie data={rows} dataKey="value" nameKey="name" innerRadius="58%" outerRadius="82%" paddingAngle={3} stroke="none">
            {rows.map((d, i) => <Cell key={i} fill={d.color} />)}
          </Pie>
          <Tooltip content={<ChartTip />} />
        </PieChart>
      </ResponsiveContainer>
      <div style={{ display: 'flex', gap: 12, justifyContent: 'center', flexWrap: 'wrap', marginTop: 4 }}>
        {rows.map((d, i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11.5, color: 'var(--text-mid)', textTransform: 'capitalize' }}>
            <span style={{ width: 9, height: 9, borderRadius: 3, background: d.color }} />
            {d.name} <b style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{d.value}</b>
          </div>
        ))}
      </div>
    </ChartCard>
  );
}

/** Top providers (barres horizontales). */
export function ProviderTopChart({ data }: { data: Array<{ provider: string; count: number }> }) {
  const rows = [...data].sort((a, b) => b.count - a.count);
  return (
    <ChartCard title="Top providers" icon="🔌">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 20, left: 12, bottom: 0 }}>
          <CartesianGrid stroke={C.grid} horizontal={false} />
          <XAxis type="number" tick={{ fill: C.text, fontSize: 10 }} axisLine={false} tickLine={false} allowDecimals={false} />
          <YAxis type="category" dataKey="provider" tick={{ fill: C.text, fontSize: 11 }} axisLine={false} tickLine={false} width={70} />
          <Tooltip content={<ChartTip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
          <Bar dataKey="count" name="Transactions" fill="var(--cyan2)" radius={[0, 5, 5, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}

/** Activité d'audit par jour. */
export function AuditBarChart({ data }: { data: Array<{ date: string; count: number }> }) {
  return (
    <ChartCard title="Activité d'audit" icon="📜">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 6, left: 6, bottom: 0 }}>
          <CartesianGrid stroke={C.grid} vertical={false} />
          <XAxis dataKey="date" tick={{ fill: C.text, fontSize: 10 }} tickFormatter={(d: string) => d.slice(8, 10)} axisLine={false} tickLine={false} />
          <YAxis tick={{ fill: C.text, fontSize: 10 }} axisLine={false} tickLine={false} width={30} allowDecimals={false} />
          <Tooltip content={<ChartTip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
          <Bar dataKey="count" name="Événements" fill="var(--violet)" radius={[5, 5, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </ChartCard>
  );
}
