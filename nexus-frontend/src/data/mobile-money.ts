/**
 * Opérateurs Mobile Money par pays (code ISO-2).
 *
 * Source : AccountController::MOBILE_MONEY_OPERATORS (nexus-api).
 * Synchronisé avec le backend pour éviter les 409 à la création.
 *
 * Pour les données runtime, l'endpoint /api/accounts/operators est la source
 * de vérité ; ce fichier sert uniquement de cache statique pour les pays
 * les plus courants (CEEAC, UEMOA, EAC).
 */
export interface MobileMoneyOperator {
  name: string;
}

export const MOBILE_MONEY_OPERATORS: Record<string, MobileMoneyOperator[]> = {
  // ── CEEAC (Centre) ──────────────────────────────────────
  CG: [
    { name: 'Airtel Money' },
    { name: 'MTN Mobile Money' },
    { name: 'Moov Africa' },
  ],
  CD: [
    { name: 'Airtel Money' },
    { name: 'M-Pesa' },
    { name: 'Orange Money' },
    { name: 'Vodacom M-Pesa' },
  ],
  CM: [
    { name: 'MTN Mobile Money' },
    { name: 'Orange Money' },
  ],
  GA: [
    { name: 'Airtel Money' },
    { name: 'Moov Africa' },
  ],
  GQ: [{ name: 'MuniMovi' }],
  CF: [
    { name: 'Telecel Money' },
    { name: 'Orange Money' },
  ],
  TD: [
    { name: 'Airtel Money' },
    { name: 'Moov Africa' },
  ],

  // ── UEMOA (Ouest) ───────────────────────────────────────
  SN: [
    { name: 'Orange Money' },
    { name: 'Wave' },
    { name: 'Free Money' },
  ],
  CI: [
    { name: 'Orange Money' },
    { name: 'MTN Mobile Money' },
    { name: 'Moov Money' },
  ],
  ML: [
    { name: 'Orange Money' },
    { name: 'Moov Africa' },
  ],
  BF: [
    { name: 'Orange Money' },
    { name: 'Moov Africa' },
  ],
  NE: [
    { name: 'Airtel Money' },
    { name: 'Moov Africa' },
    { name: 'Zamani Telecom' },
  ],
  TG: [
    { name: 'Togocel Money' },
    { name: 'Moov Africa' },
  ],
  BJ: [
    { name: 'MTN Mobile Money' },
    { name: 'Moov Africa' },
  ],
  GW: [{ name: 'Orange Money' }],

  // ── CEDEAO (Autres Ouest) ───────────────────────────────
  NG: [
    { name: 'MTN Mobile Money' },
    { name: 'Airtel Money' },
  ],
  GH: [
    { name: 'MTN Mobile Money' },
    { name: 'Vodafone Cash' },
    { name: 'AirtelTigo Money' },
  ],

  // ── EAC (Est) ───────────────────────────────────────────
  KE: [
    { name: 'M-Pesa' },
    { name: 'Airtel Money' },
    { name: 'T-Kash' },
  ],
  TZ: [
    { name: 'M-Pesa' },
    { name: 'Airtel Money' },
    { name: 'Tigo Pesa' },
  ],
  UG: [
    { name: 'MTN Mobile Money' },
    { name: 'Airtel Money' },
  ],
  RW: [
    { name: 'MTN Mobile Money' },
    { name: 'Airtel Money' },
  ],
  ZM: [
    { name: 'MTN Mobile Money' },
    { name: 'Airtel Money' },
    { name: 'Zamtel Kwacha' },
  ],
  ET: [
    { name: 'Telebirr' },
    { name: 'M-Pesa' },
  ],

  // ── Afrique Australe ────────────────────────────────────
  ZA: [
    { name: 'MTN MoMo' },
    { name: 'Vodacom M-Pesa' },
  ],
  MZ: [
    { name: 'M-Pesa' },
    { name: 'e-Mola' },
  ],
  BW: [{ name: 'Orange Money' }],
  ZW: [{ name: 'EcoCash' }],

  // ── Maghreb ─────────────────────────────────────────────
  MA: [
    { name: 'Inwi Money' },
    { name: 'Orange Money' },
  ],
  TN: [
    { name: 'Orange Money' },
    { name: 'Ooredoo Money' },
  ],
};

/** Récupère la liste des opérateurs pour un code pays. */
export function getOperatorsForCountry(countryCode: string): MobileMoneyOperator[] {
  return MOBILE_MONEY_OPERATORS[countryCode.toUpperCase()] ?? [];
}
