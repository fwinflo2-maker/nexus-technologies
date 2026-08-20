import './TechOrbits.css';

/** Cercles concentriques i-tech (style loader / radar) — décor landing. */
export function TechOrbits() {
  return (
    <div className="tech-orbits" aria-hidden="true">
      <div className="tech-orbit tech-orbit-a" />
      <div className="tech-orbit tech-orbit-b" />
      <div className="tech-orbit tech-orbit-c" />
      <div className="tech-orbit tech-orbit-d" />
      <div className="tech-orbit-core" />
      <div className="tech-orbit-sweep" />
      <div className="tech-orbit-ping tech-orbit-ping-1" />
      <div className="tech-orbit-ping tech-orbit-ping-2" />
    </div>
  );
}
