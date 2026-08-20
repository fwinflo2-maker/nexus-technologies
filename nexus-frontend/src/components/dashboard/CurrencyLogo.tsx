/** Marques visuelles des devises de réception — cliquables, pas une liste. */

const FIAT_FACE: Record<string, { bg: string; fg: string; mark: string }> = {
  EUR: { bg: '#1A3A8A', fg: '#FFD700', mark: '€' },
  USD: { bg: '#1A5F3A', fg: '#E8F5E9', mark: '$' },
  GBP: { bg: '#012169', fg: '#C8102E', mark: '£' },
  XAF: { bg: '#C9A227', fg: '#1A1204', mark: 'F' },
  XOF: { bg: '#C9A227', fg: '#1A1204', mark: 'F' },
  NGN: { bg: '#008751', fg: '#fff',    mark: '₦' },
  GHS: { bg: '#CE1126', fg: '#FCD116', mark: '₵' },
  KES: { bg: '#BB0000', fg: '#fff',    mark: 'K' },
  CAD: { bg: '#FF0000', fg: '#fff',    mark: '$' },
  CHF: { bg: '#D52B1E', fg: '#fff',    mark: '₣' },
  JPY: { bg: '#BC002D', fg: '#fff',    mark: '¥' },
  AUD: { bg: '#00008B', fg: '#FFD200', mark: '$' },
};

function UsdtMark({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#26A17B" />
      <path fill="#fff" d="M8.6 10.4h14.8v2.6h-6.1V18h-2.6v-5h-6.1V10.4z" />
      <path fill="#fff" d="M7.4 20.2h17.2v1.8H7.4zm1.8 2.6h13.6v1.5H9.2z" />
    </svg>
  );
}

function UsdcMark({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#2775CA" />
      <circle cx="16" cy="16" r="10.4" fill="none" stroke="#fff" strokeWidth="1.5" />
      <path fill="#fff" d="M17.3 8.8v1.5c2.1.4 3.4 1.7 3.4 3.4 0 1.4-.8 2.5-2.2 3.1 1.7.6 2.7 1.8 2.7 3.4 0 2-1.6 3.4-4 3.8v1.5h-1.8v-1.5c-2.4-.3-4.2-1.7-4.4-3.8h2.1c.2 1.2 1.2 2 2.4 2.2v-3.2c-2.4-.5-3.7-1.6-3.7-3.4 0-1.9 1.5-3.2 3.7-3.6V8.8h1.8zm-1.8 6.7v-3.1c-1 .2-1.6.8-1.6 1.5 0 .8.6 1.3 1.6 1.6zm1.8 5.4c1.1-.2 1.8-.8 1.8-1.7 0-.8-.6-1.4-1.8-1.7v3.4z" />
    </svg>
  );
}

function EthMark({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#1C1C2A" />
      <path fill="#627EEA" d="M16 5.2l.2.6v13.4l-.2.2L8.8 15.6 16 5.2z" />
      <path fill="#8A9FF0" d="M16 5.2l7.2 10.4L16 19.4V5.2z" />
      <path fill="#627EEA" d="M16 20.6l.1.2v5.7l-.1.4L8.8 16.8 16 20.6z" />
      <path fill="#8A9FF0" d="M16 26.9V20.6l7.2-3.8L16 26.9z" />
      <path fill="#4B5EBF" opacity=".85" d="M16 19.4L8.8 15.6 16 13.2v6.2z" />
      <path fill="#627EEA" opacity=".7" d="M23.2 15.6L16 19.4v-6.2l7.2 2.4z" />
    </svg>
  );
}

function FiatMark({ code, size }: { code: string; size: number }) {
  const face = FIAT_FACE[code] ?? { bg: '#1E3A4C', fg: '#7EE8FF', mark: code.slice(0, 1) };
  const fontSize = face.mark.length > 1 ? size * 0.34 : size * 0.48;
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill={face.bg} />
      <circle cx="16" cy="16" r="13.2" fill="none" stroke={face.fg} strokeWidth="1.1" opacity="0.45" />
      <text
        x="16"
        y="21"
        textAnchor="middle"
        fill={face.fg}
        fontSize={fontSize}
        fontWeight="800"
        fontFamily="system-ui, sans-serif"
      >
        {face.mark}
      </text>
    </svg>
  );
}

function BtcMark({ size }: { size: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
      <circle cx="16" cy="16" r="16" fill="#F7931A" />
      <path fill="#fff" d="M20.2 14.2c.3-2-1.2-3.1-3.3-3.8l.7-2.7-1.6-.4-.7 2.6c-.4-.1-.9-.2-1.3-.3l.7-2.6-1.6-.4-.7 2.7c-.4-.1-.7-.2-1-.2l-2.2-.6-.4 1.7s1.2.3 1.2.3c.6.2.8.5.8.9l-.8 3.2c.1 0 .1 0 .2.1h-.2l-1.1 4.5c-.1.2-.3.6-.8.4 0 0-1.2-.3-1.2-.3l-.8 1.8 2.1.5c.4.1.8.2 1.1.3l-.7 2.8 1.6.4.7-2.7c.4.1.9.2 1.3.3l-.7 2.7 1.6.4.7-2.8c2.9.5 5 .3 5.9-2.3.7-2.1 0-3.3-1.5-4 .1 0 1.2-1.6.7-3.7zm-2.6 5.1c-.5 2.1-4 1-5.1.7l.9-3.6c1.1.3 4.7.8 4.2 2.9zm.5-5.2c-.5 1.9-3.4.9-4.3.7l.8-3.3c.9.2 4 .7 3.5 2.6z" />
    </svg>
  );
}

export function CurrencyLogo({ code, size = 32 }: { code: string; size?: number }) {
  const cur = code.toUpperCase();
  if (cur === 'USDT') return <UsdtMark size={size} />;
  if (cur === 'USDC') return <UsdcMark size={size} />;
  if (cur === 'ETH')  return <EthMark size={size} />;
  if (cur === 'BTC')  return <BtcMark size={size} />;
  return <FiatMark code={cur} size={size} />;
}
