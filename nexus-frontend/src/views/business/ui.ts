/** Helpers d'affichage partagés par les pages Business. */

import { dashTranslate } from '../../data/dashboard-i18n';

export const SYMBOL: Record<string, string> = {
  EUR: '€', USD: '$', GBP: '£', XAF: 'XAF', XOF: 'XOF', USDT: 'USDT', USDC: 'USDC',
};

export function fmtMoney(value: number | null | undefined, currency?: string | null): string {
  if (value === null || value === undefined) return '—';
  const cur = (currency ?? 'EUR').toUpperCase();
  const sym = SYMBOL[cur] ?? cur;
  const digits = cur === 'XAF' || cur === 'XOF' ? 0 : 2;
  const num = value.toLocaleString('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  return cur === 'EUR' || cur === 'USD' || cur === 'GBP' ? `${sym} ${num}` : `${num} ${sym}`;
}

export function pillForStatus(status: string): string {
  switch (status) {
    case 'completed': case 'matched': case 'resolved': case 'active': case 'verified': return 'p-gr';
    case 'approved': return 'p-c';
    case 'pending': case 'pending_approval': case 'draft': case 'pending_verification': case 'unverified': return 'p-g';
    case 'failed': case 'rejected': case 'discrepancy': case 'inactive': return 'p-r';
    case 'cancelled': case 'unmatched': return 'p-v';
    case 'executing': case 'processing': return 'p-c';
    default: return 'p';
  }
}

export function labelForStatus(status: string): string {
  return dashTranslate(`status.${status}`);
}

export const METHOD_LABEL: Record<string, string> = {
  mobile_money: '📱 Mobile Money',
  bank: '🏦 Banque',
  crypto: '🔗 Crypto',
  cash_pickup: '💵 Espèces',
};
