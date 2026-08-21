/**
 * Modes de paiement / kinds de compte selon le pays (ISO-2).
 * Miroir client de FundingProposalService::availablePaymentModes — utilisé
 * comme source de vérité UI si l’API échoue, et pour éviter les défauts CG/FR.
 */
import type { AccountKind } from '../api/client';
import { MOBILE_MONEY_OPERATORS } from './mobile-money';

const EU = new Set([
  'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
  'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
  'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
]);

const CEMAC = new Set(['CG', 'CM', 'GA', 'GQ', 'CF', 'TD']);
const UEMOA = new Set(['SN', 'CI', 'BF', 'BJ', 'ML', 'NE', 'TG', 'GW']);

const COUNTRY_CURRENCY: Record<string, string> = {
  FR: 'EUR', DE: 'EUR', ES: 'EUR', IT: 'EUR', BE: 'EUR', NL: 'EUR',
  PT: 'EUR', IE: 'EUR', AT: 'EUR', LU: 'EUR', FI: 'EUR', GR: 'EUR',
  GB: 'GBP', US: 'USD', CA: 'CAD',
  CG: 'XAF', CM: 'XAF', GA: 'XAF', CD: 'CDF',
  SN: 'XOF', CI: 'XOF', BF: 'XOF', BJ: 'XOF', ML: 'XOF', NE: 'XOF', TG: 'XOF',
  NG: 'NGN', GH: 'GHS', KE: 'KES', UG: 'UGX', RW: 'RWF', TZ: 'TZS', ZM: 'ZMW', ZA: 'ZAR',
};

const CRYPTO_DEPOSIT = ['USDT', 'USDC', 'ETH', 'BTC'] as const;

export interface LocalPaymentModes {
  country: string;
  methods: string[];
  account_kinds: { source: AccountKind[]; destination: AccountKind[] };
  default_currency: string;
  has_mobile_money: boolean;
}

export function hasMobileMoney(country: string): boolean {
  const cc = country.toUpperCase();
  return Boolean(MOBILE_MONEY_OPERATORS[cc]?.length);
}

/**
 * Devises de dépôt proposées pour un pays d’enregistrement.
 * FR → EUR/USD/GBP + crypto (pas XAF/XOF).
 * CG → XAF + EUR/USD + crypto (pas XOF).
 */
export function depositCurrenciesForCountry(country: string): string[] {
  const cc = country.trim().toUpperCase();
  const fiat: string[] = [];

  if (cc.length !== 2) {
    return ['EUR', 'USD', ...CRYPTO_DEPOSIT];
  }

  if (EU.has(cc)) {
    fiat.push('EUR', 'USD', 'GBP');
  } else if (cc === 'GB') {
    fiat.push('GBP', 'EUR', 'USD');
  } else if (cc === 'US' || cc === 'CA') {
    fiat.push('USD', 'EUR');
  } else if (CEMAC.has(cc) || cc === 'CD') {
    fiat.push(cc === 'CD' ? 'CDF' : 'XAF', 'EUR', 'USD');
  } else if (UEMOA.has(cc)) {
    fiat.push('XOF', 'EUR', 'USD');
  } else if (cc === 'NG') {
    fiat.push('NGN', 'USD', 'EUR');
  } else if (cc === 'GH') {
    fiat.push('GHS', 'USD', 'EUR');
  } else if (cc === 'KE') {
    fiat.push('KES', 'USD', 'EUR');
  } else if (cc === 'ZA') {
    fiat.push('ZAR', 'USD', 'EUR');
  } else {
    const local = COUNTRY_CURRENCY[cc];
    if (local) fiat.push(local);
    fiat.push('USD', 'EUR');
  }

  return [...new Set([...fiat, ...CRYPTO_DEPOSIT])];
}

export function isCryptoDepositCurrency(code: string): boolean {
  return (CRYPTO_DEPOSIT as readonly string[]).includes(code.toUpperCase());
}

/** Sépare fiat / crypto pour l’UI de recharge. */
export function splitDepositCurrencies(currencies: string[]): { fiat: string[]; crypto: string[] } {
  const fiat: string[] = [];
  const crypto: string[] = [];
  for (const c of currencies) {
    if (isCryptoDepositCurrency(c)) crypto.push(c);
    else fiat.push(c);
  }
  return { fiat, crypto };
}

/** Calcule les modes autorisés pour un pays (sans appel API). */
export function paymentModesForCountry(country: string): LocalPaymentModes | null {
  const cc = country.trim().toUpperCase();
  if (cc.length !== 2) return null;

  const momo = hasMobileMoney(cc);
  const isEu = EU.has(cc);

  const methods: string[] = ['crypto'];
  const source: AccountKind[] = ['crypto_wallet'];
  const destination: AccountKind[] = ['crypto_wallet'];

  if (momo) {
    methods.push('mobile_money');
    source.push('mobile_money');
    destination.push('mobile_money');
  }
  methods.push('bank');
  source.push('bank_iban', 'virtual_iban');
  destination.push('bank_iban');

  if (isEu || cc === 'GB' || cc === 'US') {
    methods.push('card');
    source.push('card');
  }

  // Cash pickup / cashout — Western Union (et MoneyGram) disponibles mondialement.
  methods.push('cash_pickup');
  destination.push('cash_pickup');
  source.push('cash_pickup');

  return {
    country: cc,
    methods: [...new Set(methods)],
    account_kinds: {
      source: [...new Set(source)],
      destination: [...new Set(destination)],
    },
    default_currency: COUNTRY_CURRENCY[cc] ?? (isEu ? 'EUR' : 'USD'),
    has_mobile_money: momo,
  };
}
