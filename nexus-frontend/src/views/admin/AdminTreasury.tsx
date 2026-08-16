import { AssetDonut, VolumeAreaChart } from './CockpitCharts';
import { Stat, Panel, fmtMoney } from './adminUi';

interface Props { assets: { EUR: string; USD: string; XAF: string }; series: { volume_eur: Array<{ date: string; volume: number }> }; }

const CUR: Array<{ name: string; key: 'EUR' | 'USD' | 'XAF'; color: string; sym: string }> = [
  { name: 'EUR', key: 'EUR', color: 'var(--cyan)', sym: '€' },
  { name: 'USD', key: 'USD', color: 'var(--cyan2)', sym: '$' },
  { name: 'XAF', key: 'XAF', color: 'var(--violet)', sym: 'FCFA' },
];

export default function AdminTreasury({ assets, series }: Props) {
  const donut = CUR.map((c) => ({ name: c.name, value: Number(assets[c.key]), color: c.color }));
  const total = donut.reduce((s, d) => s + d.value, 0);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Liquidité totale" value={fmtMoney(total, '')} sub="toutes devises confondues" tone="var(--cyan)" />
        {CUR.map((c) => (
          <Stat key={c.key} label={c.name} value={`${fmtMoney(Number(assets[c.key]), '')} ${c.sym}`} />
        ))}
      </div>
      <div className="g3">
        <AssetDonut data={donut} />
        <div style={{ gridColumn: 'span 2' }}><VolumeAreaChart data={series.volume_eur} /></div>
      </div>
      <Panel title="Répartition" icon="🧾">
        {CUR.map((c) => {
          const v = Number(assets[c.key]);
          const pct = total > 0 ? Math.round((v / total) * 100) : 0;
          return (
            <div key={c.key} style={{ marginBottom: 8 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 4 }}>
                <span style={{ color: 'var(--text-mid)' }}>{c.name}</span>
                <span style={{ color: 'var(--text-bright)', fontFamily: 'var(--font-mono)' }}>{fmtMoney(v, '')} {c.sym} · {pct}%</span>
              </div>
              <div style={{ height: 6, background: 'var(--panel2)', borderRadius: 4, overflow: 'hidden' }}>
                <div style={{ width: `${pct}%`, height: '100%', background: c.color, borderRadius: 4 }} />
              </div>
            </div>
          );
        })}
      </Panel>
    </div>
  );
}
