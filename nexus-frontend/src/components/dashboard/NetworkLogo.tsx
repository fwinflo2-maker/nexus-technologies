import type { ReactNode } from 'react';

/** Icônes de réseaux blockchain — sélection visuelle (pas de liste déroulante). */

const SHORT: Record<string, string> = {
  Ethereum: 'ETH',
  Bitcoin: 'BTC',
  Tron: 'TRX',
  'BNB Smart Chain': 'BNB',
  Polygon: 'POL',
  Arbitrum: 'ARB',
  Optimism: 'OP',
  Solana: 'SOL',
  Base: 'BASE',
};

export function networkShortLabel(name: string): string {
  return SHORT[name] ?? name.slice(0, 4).toUpperCase();
}

function CircleMark({
  size, bg, children,
}: { size: number; bg: string; children: ReactNode }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill={bg} />
      {children}
    </svg>
  );
}

function EthereumIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#627EEA">
      <path fill="#fff" opacity="0.7" d="M16 5.4l.15.5v12.8l-.15.15L9.2 14.8 16 5.4z" />
      <path fill="#fff" d="M16 5.4l6.8 9.4L16 18.85V5.4z" />
      <path fill="#fff" opacity="0.7" d="M16 19.9l.1.15v5.3l-.1.35-6.8-9.4L16 19.9z" />
      <path fill="#fff" d="M16 25.7V19.9l6.8-3.6L16 25.7z" />
    </CircleMark>
  );
}

function BitcoinIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#F7931A">
      <path fill="#fff" d="M20.2 14.2c.3-2-1.2-3.1-3.3-3.8l.7-2.7-1.6-.4-.7 2.6c-.4-.1-.9-.2-1.3-.3l.7-2.6-1.6-.4-.7 2.7c-.4-.1-.7-.2-1-.2l-2.2-.6-.4 1.7s1.2.3 1.2.3c.6.2.8.5.8.9l-.8 3.2c.1 0 .1 0 .2.1h-.2l-1.1 4.5c-.1.2-.3.6-.8.4 0 0-1.2-.3-1.2-.3l-.8 1.8 2.1.5c.4.1.8.2 1.1.3l-.7 2.8 1.6.4.7-2.7c.4.1.9.2 1.3.3l-.7 2.7 1.6.4.7-2.8c2.9.5 5 .3 5.9-2.3.7-2.1 0-3.3-1.5-4 .1 0 1.2-1.6.7-3.7zm-2.6 5.1c-.5 2.1-4 1-5.1.7l.9-3.6c1.1.3 4.7.8 4.2 2.9zm.5-5.2c-.5 1.9-3.4.9-4.3.7l.8-3.3c.9.2 4 .7 3.5 2.6z" />
    </CircleMark>
  );
}

function TronIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#FF0013">
      <path fill="#fff" d="M8.2 9.2l15.6 4.2-7.4 9.8L8.2 9.2zm2.4 1.8l5.5 11.2 5.4-7.2-10.9-2.9v-1.1z" />
    </CircleMark>
  );
}

function BnbIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#F3BA2F">
      <path fill="#fff" d="M16 7.2l2.4 2.4-2.4 2.4-2.4-2.4L16 7.2zm-5.4 5.4l2.4-2.4 2.4 2.4-2.4 2.4-2.4-2.4zm10.8 0l-2.4 2.4-2.4-2.4 2.4-2.4 2.4 2.4zM16 18.2l2.4 2.4-2.4 2.4-2.4-2.4 2.4-2.4zm-5.4-2.2l2.4 2.4-2.4 2.4-2.4-2.4 2.4-2.4zm10.8 0l2.4 2.4-2.4 2.4-2.4-2.4 2.4-2.4zM16 12.4l2.2 2.2-2.2 2.2-2.2-2.2 2.2-2.2z" />
    </CircleMark>
  );
}

function PolygonIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#8247E5">
      <path fill="#fff" d="M20.6 12.2c-.3-.2-.7-.2-1 0l-2.3 1.3-1.6.9-2.3 1.3c-.3.2-.7.2-1 0l-1.8-1c-.3-.2-.5-.5-.5-.9v-2.1c0-.3.2-.7.5-.9l1.8-1c.3-.2.7-.2 1 0l1.8 1c.3.2.5.5.5.9v1.3l1.6-.9v-1.4c0-.3-.2-.7-.5-.9l-3.3-1.9c-.3-.2-.7-.2-1 0L8.4 10c-.3.2-.5.5-.5.9v3.8c0 .3.2.7.5.9l3.3 1.9c.3.2.7.2 1 0l2.3-1.3 1.6-.9 2.3-1.3c.3-.2.7-.2 1 0l1.8 1c.3.2.5.5.5.9v2.1c0 .3-.2.7-.5.9l-1.8 1c-.3.2-.7.2-1 0l-1.8-1c-.3-.2-.5-.5-.5-.9v-1.3l-1.6.9v1.4c0 .3.2.7.5.9l3.3 1.9c.3.2.7.2 1 0l3.3-1.9c.3-.2.5-.5.5-.9v-3.8c0-.3-.2-.7-.5-.9l-3.3-1.9z" />
    </CircleMark>
  );
}

function ArbitrumIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#213147">
      <path fill="#28A0F0" d="M16.8 7.2l7.6 13.2H9.2L16.8 7.2z" />
      <path fill="#fff" d="M16.2 11.4l4.4 7.6h-8.8l4.4-7.6z" />
      <path fill="#9DCCED" d="M11.2 20.6l2.4-4.2 2.1 3.6-1.3 2.2H8.8l2.4-1.6z" />
    </CircleMark>
  );
}

function OptimismIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#FF0420">
      <text x="16" y="20.5" textAnchor="middle" fill="#fff" fontSize="11" fontWeight="800" fontFamily="system-ui,sans-serif">OP</text>
    </CircleMark>
  );
}

function SolanaIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#000">
      <defs>
        <linearGradient id="sol" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#00FFA3" />
          <stop offset="100%" stopColor="#DC1FFF" />
        </linearGradient>
      </defs>
      <path fill="url(#sol)" d="M9.2 19.8c.2-.2.4-.3.7-.3h12.4c.4 0 .6.5.3.8l-1.5 1.5c-.2.2-.4.3-.7.3H8c-.4 0-.6-.5-.3-.8l1.5-1.5zm0-5.3c.2-.2.4-.3.7-.3h12.4c.4 0 .6.5.3.8l-1.5 1.5c-.2.2-.4.3-.7.3H8c-.4 0-.6-.5-.3-.8l1.5-1.5zm13.4-3.8c-.2-.2-.4-.3-.7-.3H9.5c-.4 0-.6.5-.3.8l1.5 1.5c.2.2.4.3.7.3h12.4c.4 0 .6-.5.3-.8l-1.5-1.5z" />
    </CircleMark>
  );
}

function BaseIcon({ size }: { size: number }) {
  return (
    <CircleMark size={size} bg="#0052FF">
      <circle cx="16" cy="16" r="8" fill="#fff" />
      <path fill="#0052FF" d="M16 10a6 6 0 1 0 6 6h-6V10z" />
    </CircleMark>
  );
}

function FallbackIcon({ size, label }: { size: number; label: string }) {
  return (
    <CircleMark size={size} bg="#1E3A4C">
      <text x="16" y="20" textAnchor="middle" fill="#7EE8FF" fontSize="8" fontWeight="800" fontFamily="system-ui,sans-serif">
        {label.slice(0, 3)}
      </text>
    </CircleMark>
  );
}

export function NetworkLogo({ name, size = 32 }: { name: string; size?: number }) {
  switch (name) {
    case 'Ethereum': return <EthereumIcon size={size} />;
    case 'Bitcoin': return <BitcoinIcon size={size} />;
    case 'Tron': return <TronIcon size={size} />;
    case 'BNB Smart Chain': return <BnbIcon size={size} />;
    case 'Polygon': return <PolygonIcon size={size} />;
    case 'Arbitrum': return <ArbitrumIcon size={size} />;
    case 'Optimism': return <OptimismIcon size={size} />;
    case 'Solana': return <SolanaIcon size={size} />;
    case 'Base': return <BaseIcon size={size} />;
    default: return <FallbackIcon size={size} label={networkShortLabel(name)} />;
  }
}
