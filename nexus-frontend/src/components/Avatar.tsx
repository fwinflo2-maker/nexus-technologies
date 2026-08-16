/**
 * Avatar utilisateur / entreprise.
 * Affiche l'image de profil si disponible (avatar), sinon un fallback emoji
 * selon le type de compte (👤 personnel / 🏢 business).
 */
export default function Avatar({
  avatar,
  accountType = 'personal',
  size = 32,
  className,
}: {
  avatar?: string | null;
  accountType?: 'personal' | 'business';
  size?: number;
  className?: string;
}) {
  const fallback = accountType === 'business' ? '🏢' : '👤';

  if (avatar) {
    return (
      <img
        src={avatar}
        alt="Avatar"
        className={className}
        style={{
          width: size,
          height: size,
          borderRadius: '50%',
          objectFit: 'cover',
          background: 'rgba(0,200,255,0.12)',
          border: '1px solid rgba(0,200,255,0.25)',
          flexShrink: 0,
        }}
        onError={(e) => {
          // Si l'image ne charge pas, repli sur l'emoji
          e.currentTarget.style.display = 'none';
          const parent = e.currentTarget.parentElement;
          if (parent) {
            const sib = parent.querySelector('[data-avatar-fallback]');
            if (sib) (sib as HTMLElement).style.display = 'flex';
          }
        }}
      />
    );
  }

  return (
    <div
      data-avatar-fallback
      className={className}
      style={{
        width: size,
        height: size,
        borderRadius: '50%',
        background: 'rgba(0,200,255,0.12)',
        border: '1px solid rgba(0,200,255,0.25)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontSize: size * 0.5,
        flexShrink: 0,
      }}
    >
      {fallback}
    </div>
  );
}
