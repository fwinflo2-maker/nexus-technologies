// Gear SVG Animation Component
export default function GearsBackground() {
  return (
    <div className="gears-bg">
      <svg width="100%" height="100%" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
        <defs>
          <style>{`
            .gt1 { fill: none; stroke: #00C8FF; stroke-width: 1.5; }
            .gt2 { fill: none; stroke: #EAB830; stroke-width: 1.5; }
            .gt3 { fill: none; stroke: #8B5CF6; stroke-width: 1.5; }
            .gt4 { fill: none; stroke: #00CFA0; stroke-width: 1; }
          `}</style>
        </defs>

        {/* Large gear top-right */}
        <g transform="translate(1250, 120)" className="gear gear-1">
          <GearPath r={90} teeth={18} toothH={18} strokeClass="gt1" />
        </g>

        {/* Medium gear meshing */}
        <g transform="translate(1140, 178)" className="gear gear-2">
          <GearPath r={48} teeth={10} toothH={12} strokeClass="gt2" />
        </g>

        {/* Bottom-left large */}
        <g transform="translate(140, 720)" className="gear gear-3">
          <GearPath r={80} teeth={16} toothH={16} strokeClass="gt3" />
        </g>

        {/* Small meshing bottom-left */}
        <g transform="translate(238, 680)" className="gear gear-4">
          <GearPath r={36} teeth={8} toothH={10} strokeClass="gt4" />
        </g>

        {/* Center-ish medium */}
        <g transform="translate(700, 480)" className="gear gear-5">
          <GearPath r={55} teeth={12} toothH={14} strokeClass="gt2" />
        </g>

        {/* Small accent top-left */}
        <g transform="translate(200, 150)" className="gear gear-2">
          <GearPath r={30} teeth={7} toothH={9} strokeClass="gt4" />
        </g>
      </svg>
    </div>
  );
}

function GearPath({ r, teeth, toothH, strokeClass }: { r: number; teeth: number; toothH: number; strokeClass: string }) {
  const angleStep = (2 * Math.PI) / teeth;
  const innerR = r - toothH;
  const toothWidth = 0.35; // fraction of full angle

  let d = '';
  for (let i = 0; i < teeth; i++) {
    const a1 = i * angleStep;
    const a2 = a1 + angleStep * toothWidth;
    const a3 = a1 + angleStep * (0.5 - toothWidth / 2);
    const a4 = a1 + angleStep * (0.5 + toothWidth / 2);

    const p = (a: number, rad: number) => ({ x: Math.cos(a - Math.PI / 2) * rad, y: Math.sin(a - Math.PI / 2) * rad });

    if (i === 0) d += `M ${p(a1, innerR).x.toFixed(2)} ${p(a1, innerR).y.toFixed(2)} `;

    d += `L ${p(a1, innerR).x.toFixed(2)} ${p(a1, innerR).y.toFixed(2)} `;
    d += `L ${p(a2, r).x.toFixed(2)} ${p(a2, r).y.toFixed(2)} `;
    d += `L ${p(a3, r).x.toFixed(2)} ${p(a3, r).y.toFixed(2)} `;
    d += `L ${p(a4, innerR).x.toFixed(2)} ${p(a4, innerR).y.toFixed(2)} `;
  }
  d += 'Z';

  return (
    <>
      <path d={d} className={strokeClass} />
      <circle r={r * 0.35} className={strokeClass} />
      <circle r={r * 0.12} className={strokeClass} />
    </>
  );
}
