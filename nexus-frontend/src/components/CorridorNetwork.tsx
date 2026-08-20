import './CorridorNetwork.css';

const NODES = [
  { id: 'par', x: 18, y: 42, label: 'EUR' },
  { id: 'lon', x: 32, y: 28, label: 'GBP' },
  { id: 'nx', x: 50, y: 48, label: 'NEXUS' },
  { id: 'lag', x: 68, y: 62, label: 'NGN' },
  { id: 'dla', x: 78, y: 38, label: 'XAF' },
  { id: 'abj', x: 62, y: 22, label: 'XOF' },
] as const;

const EDGES: [number, number][] = [
  [0, 2],
  [1, 2],
  [2, 3],
  [2, 4],
  [2, 5],
  [1, 5],
];

/** Réseau de corridors — signature visuelle landing (SVG). */
export function CorridorNetwork() {
  return (
    <div className="corridor-network" aria-hidden="true">
      <svg className="corridor-svg" viewBox="0 0 100 80" preserveAspectRatio="xMidYMid slice">
        <defs>
          <linearGradient id="corridor-stroke" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" stopColor="#4F6EF7" stopOpacity="0.15" />
            <stop offset="50%" stopColor="#4F6EF7" stopOpacity="0.85" />
            <stop offset="100%" stopColor="#4F6EF7" stopOpacity="0.2" />
          </linearGradient>
        </defs>

        {EDGES.map(([a, b], i) => {
          const from = NODES[a];
          const to = NODES[b];
          return (
            <line
              key={`e-${i}`}
              className="corridor-edge"
              style={{ animationDelay: `${0.15 + i * 0.12}s` }}
              x1={from.x}
              y1={from.y}
              x2={to.x}
              y2={to.y}
              stroke="url(#corridor-stroke)"
              strokeWidth="0.35"
              vectorEffect="non-scaling-stroke"
            />
          );
        })}

        {NODES.map((n, i) => (
          <g key={n.id} className="corridor-node" style={{ animationDelay: `${0.35 + i * 0.08}s` }}>
            <circle cx={n.x} cy={n.y} r={n.id === 'nx' ? 1.8 : 1.15} className={n.id === 'nx' ? 'corridor-hub' : 'corridor-dot'} />
            <text x={n.x} y={n.y - 3.2} textAnchor="middle" className="corridor-label">
              {n.label}
            </text>
          </g>
        ))}
      </svg>
    </div>
  );
}
