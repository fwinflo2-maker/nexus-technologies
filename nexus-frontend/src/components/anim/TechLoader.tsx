/**
 * TECH LOADER — cercles de chargement i-tech réutilisables.
 * 3 anneaux concentriques (sens de rotation opposés) + noyau lumineux pulsant.
 * Tailles : sm (compact), md (défaut), lg (plein écran). Label optionnel.
 */
export default function TechLoader({
  size = 'md',
  label,
}: {
  size?: 'sm' | 'md' | 'lg';
  label?: React.ReactNode;
}) {
  return (
    <div className={`tl tl-${size}`} role="status" aria-label={typeof label === 'string' ? label : 'Chargement'}>
      <div className="tl-rings" aria-hidden="true">
        <span className="tl-ring tl-ring-1" />
        <span className="tl-ring tl-ring-2" />
        <span className="tl-ring tl-ring-3" />
        <span className="tl-core" />
      </div>
      {label && <span className="tl-label">{label}</span>}
    </div>
  );
}
