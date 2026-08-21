import { useEffect, useState } from 'react';

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
  const [failed, setFailed] = useState(false);
  const fallback = accountType === 'business' ? '🏢' : '👤';

  // Nouvelle URL / data URI → réessayer l'affichage (évite un fallback figé
  // après un upload réussi ou un changement de profil).
  useEffect(() => {
    setFailed(false);
  }, [avatar]);

  if (avatar && !failed) {
    return (
      <img
        key={avatar}
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
        onError={() => setFailed(true)}
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
