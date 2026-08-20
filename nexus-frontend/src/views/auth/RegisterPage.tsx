import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { countries } from '../../data/countries';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import './AuthPages.css';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useI18n } from '../../context/I18nContext';
import { useAuth } from '../../context/AuthContext';
import { apiRegister } from '../../api/client';
import { ParticlesBackground } from '../../components/ParticlesBackground';
import { EASE } from '../../components/anim/Premium';

const authEnter = {
  hidden: { opacity: 0, y: 24, scale: 0.99 },
  visible: (i: number = 0) => ({ opacity: 1, y: 0, scale: 1, transition: { duration: 0.5, ease: EASE, delay: i * 0.06 } }),
};

interface RegisterPageProps {
  onSwitchToLogin: () => void;
  onBackHome: () => void;
}

interface PersonalData {
  firstName: string;
  lastName: string;
  birthDate: string;
  country: string;
  email: string;
  phone: string;
  phoneCode: string;
  password: string;
}

interface BusinessData {
  companyName: string;
  legalForm: string;
  companyCountry: string;
  registrationNumber: string;
  industry: string;
  companySize: string;
  website: string;
  companyEmail: string;
  phone: string;
  phoneCode: string;
  password: string;
}

const emptyPersonal: PersonalData = {
  firstName: '', lastName: '', birthDate: '', country: 'France', email: '', phone: '', phoneCode: '+33', password: '',
};

const emptyBusiness: BusinessData = {
  companyName: '', legalForm: '', companyCountry: 'France', registrationNumber: '', industry: '', companySize: '', website: '', companyEmail: '', phone: '', phoneCode: '+33', password: '',
};

const legalForms = ['SARL', 'SA', 'SAS', 'SASU', 'EURL', 'Entreprise individuelle', 'Coopérative', 'ONL', 'GIE', 'Autre'];

// Formes juridiques par pays (code ISO-2) — pour l'inscription entreprise.
// Vérifiées par sources publiques (droit OHADA, registres nationaux, guides
// officiels de création d'entreprise). Pour les pays absents → liste générique.
// Afrique francophone = droit OHADA (17 États) : formes communes harmonisées.
const OHADA_FORMS = [
  'Établissement',
  'Entreprise individuelle',
  'SARL',
  'SARLU',
  'SA',
  'SAS',
  'SASU',
  'SNC',
  'SCS',
  'GIE',
  'Société en participation',
  'Société coopérative',
];

const LEGAL_FORMS_BY_COUNTRY: Record<string, string[]> = {
  // ── France ──
  FR: ['SARL', 'SAS', 'SASU', 'SA', 'EURL', 'SNC', 'SCS', 'SCI', 'Entreprise individuelle', 'Micro-entreprise', 'Société civile'],

  // ── Afrique francophone — droit OHADA (17 États membres) ──
  // Congo-Brazzaville, RDC, Cameroun, Gabon, Sénégal, Côte d'Ivoire, etc.
  CG: OHADA_FORMS,
  CD: OHADA_FORMS,
  CM: OHADA_FORMS,
  GA: OHADA_FORMS,
  SN: OHADA_FORMS,
  CI: OHADA_FORMS,
  TG: OHADA_FORMS,
  BJ: OHADA_FORMS,
  BF: OHADA_FORMS,
  ML: OHADA_FORMS,
  NE: OHADA_FORMS,
  TD: OHADA_FORMS,
  CF: OHADA_FORMS,
  GQ: OHADA_FORMS,
  GN: OHADA_FORMS,
  GW: OHADA_FORMS,
  KM: OHADA_FORMS,

  // ── Maghreb (droit local, pas OHADA) ──
  MA: ['Entreprise individuelle', 'Auto-entrepreneur', 'SARL', 'SARL AU', 'SAS', 'SA', 'SNC', 'SCS', 'SCA', 'Société en participation', 'GIE', 'Société coopérative'],
  DZ: ['Entreprise individuelle', 'EURL', 'SARL', 'SPA', 'SAS', 'SNC', 'SCS', 'GIE', 'Société en participation'],
  TN: ['Entreprise individuelle', 'SARL', 'SA', 'SUARL', 'SNC', 'SCS', 'Société coopérative'],

  // ── États-Unis ──
  US: ['LLC', 'Corporation (Inc.)', 'S-Corp', 'C-Corp', 'LLP', 'General Partnership', 'Sole Proprietorship', 'Nonprofit (501c3)'],

  // ── Royaume-Uni ──
  GB: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'LLP', 'Sole Trader', 'Partnership', 'Community Interest Company'],

  // ── Europe de l'Ouest ──
  DE: ['GmbH', 'UG (haftungsbeschränkt)', 'AG', 'Einzelunternehmen', 'GbR', 'OHG', 'KG'],
  BE: ['SPRL', 'SA', 'SCRL', 'SNC', 'SCS', 'Société simple'],
  CH: ['GmbH', 'AG', 'Einzelunternehmen', 'Kollektivgesellschaft', 'Kommanditgesellschaft'],
  ES: ['Sociedad Limitada (SL)', 'Sociedad Anónima (SA)', 'Sociedad Civil', 'Comunidad de Bienes', 'Autónomo'],
  IT: ['SRL', 'SPA', 'SNC', 'SAS', 'Ditta Individuale'],
  PT: ['Sociedade por Quotas (Lda)', 'Sociedade Anónima (SA)', 'Empresário em Nome Individual'],
  NL: ['BV', 'NV', 'VOF', 'Eenmanszaak', 'Coöperatie'],
  LU: ['SARL', 'SA', 'SCS', 'Sole Proprietorship'],
  IE: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'LLP', 'Sole Trader', 'Partnership'],
  AT: ['GmbH', 'AG', 'OG', 'KG', 'Einzelunternehmen'],
  SE: ['Aktiebolag (AB)', 'Handelsbolag', 'Kommanditbolag', 'Enskild firma', 'Ekonomisk förening'],
  DK: ['Anpartsselskab (ApS)', 'Aktieselskab (A/S)', 'Enkeltmandsvirksomhed', 'Interessentskab', 'Kommanditselskab'],
  FI: ['Osakeyhtiö (Oy)', 'Avoin yhtiö (Ay)', 'Kommandiittiyhtiö (Ky)', 'Toiminimi'],
  NO: ['Aksjeselskap (AS)', 'Allmennaksjeselskap (ASA)', 'Enkeltpersonforetak', 'Ansvarlig selskap (ANS)'],
  PL: ['Spółka z o.o.', 'S.A.', 'Spółka komandytowa', 'Spółka jawna', 'Jednoosobowa działalność'],
  GR: ['Α.Ε. (AE)', 'Ε.Π.Ε. (EPE)', 'Μονοπρόσωπη ΙΚΕ', 'Ο.Ε. (OE)', 'Ατομική Επιχείρηση'],

  // ── Amériques / autres ──
  CA: ['Corporation (Inc.)', 'Limited (Ltd)', 'Sole Proprietorship', 'Partnership', 'General Partnership', 'Cooperative'],
  BR: ['Sociedade Limitada (LTDA)', 'Sociedade Anônima (SA)', 'Empresa Individual (EI)', 'MEI', 'Sociedade Simples'],
  MX: ['Sociedad Anónima (SA)', 'Sociedad de Responsabilidad Limitada (SRL)', 'Persona Física con Actividad Empresarial', 'Sociedad Civil'],
  AR: ['Sociedad de Responsabilidad Limitada (SRL)', 'Sociedad Anónima (SA)', 'Monotributo', 'Sociedad Simple'],
  CO: ['Sociedad por Acciones Simplificada (SAS)', 'Sociedad Limitada (LTDA)', 'Sociedad Anónima (SA)', 'Persona Natural'],
  CL: ['Sociedad por Acciones (SpA)', 'Sociedad de Responsabilidad Limitada (Ltda)', 'Sociedad Anónima (SA)', 'Empresa Individual de Responsabilidad Limitada (EIRL)'],

  // ── Afrique anglophone ──
  NG: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'Business Name', 'Limited Liability Partnership (LLP)'],
  KE: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'Sole Proprietorship', 'Partnership', 'Limited Liability Partnership (LLP)'],
  GH: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'Sole Proprietorship', 'Partnership', 'Company Limited by Guarantee'],
  ZA: ['Private Company (Pty) Ltd', 'Public Company (Ltd)', 'Sole Proprietor', 'Partnership', 'Non-Profit Company (NPC)'],
  TZ: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'Sole Proprietorship', 'Partnership'],
  UG: ['Private Limited (Ltd)', 'Public Limited (PLC)', 'Sole Proprietorship', 'Partnership'],
  ET: ['Private Limited Company (PLC)', 'Share Company (SC)', 'Sole Proprietorship'],
  EG: ['LLC', 'Joint Stock Company (JSC)', 'Sole Proprietorship', 'Partnership'],

  // ── Asie ──
  IN: ['Private Limited (Pvt Ltd)', 'Public Limited (Ltd)', 'LLP', 'Sole Proprietorship', 'Partnership'],
  CN: ['有限责任公司 (LLC)', '股份有限公司 (Co., Ltd)', '合伙企业 (Partnership)', '个体工商户 (Sole Proprietor)'],
  JP: ['株式会社 (Kabushiki Kaisha)', '合同会社 (Godo Gaisha)', '合名会社 (Gomei Gaisha)', '個人事業主 (Sole Proprietor)'],
  SG: ['Private Limited (Pte Ltd)', 'Sole Proprietorship', 'Partnership', 'Limited Liability Partnership (LLP)'],
  MY: ['Sendirian Berhad (Sdn Bhd)', 'Berhad (Bhd)', 'Sole Proprietorship', 'Partnership'],
  TH: ['Limited Company', 'Public Limited Company', 'Sole Proprietorship', 'Partnership'],
  VN: ['Công ty TNHH (LLC)', 'Công ty Cổ phần (JSC)', 'Doanh nghiệp tư nhân (Sole Proprietor)'],
  PH: ['Corporation', 'Partnership', 'Sole Proprietorship'],
  ID: ['Perseroan Terbatas (PT)', 'Commanditaire Vennootschap (CV)', 'Firma', 'Usaha Dagang (UD)'],
  PK: ['Private Limited (Pvt Ltd)', 'Public Limited (Ltd)', 'Sole Proprietorship', 'Partnership'],
  BD: ['Private Limited (Pvt Ltd)', 'Public Limited (Ltd)', 'Sole Proprietorship', 'Partnership'],

  // ── Moyen-Orient ──
  AE: ['LLC', 'Free Zone Company (FZ-LLC)', 'Sole Establishment', 'Public Joint Stock Company (PJSC)'],
  SA: ['Limited Liability Company (LLC)', 'Joint Stock Company (JSC)', 'Sole Establishment', 'Partnership'],
  QA: ['Limited Liability Company (LLC)', 'Joint Stock Company (QSC)', 'Sole Establishment'],
  KW: ['Limited Liability Company (WLL)', 'Joint Stock Company (KSC)', 'Sole Proprietorship'],
  TR: ['Anonim Şirket (A.Ş.)', 'Limited Şirket (Ltd. Şti.)', 'Şahıs Şirketi', 'Komandit Şirket'],
  IL: ['Private Limited Company (Ltd)', 'Partnership', 'Sole Proprietorship'],

  // ── Australie / Océanie ──
  AU: ['Proprietary Limited (Pty Ltd)', 'Public Limited (Ltd)', 'Sole Trader', 'Partnership', 'Trust'],
  NZ: ['Limited (Ltd)', 'Sole Trader', 'Partnership', 'Limited Partnership'],

  // ── Europe de l'Est ──
  RU: ['ООО (OOO)', 'АО (AO)', 'ИП (IP)', 'Полное товарищество'],
  UA: ['ТОВ (LLC)', 'ПрАТ (PJSC)', 'ФОП (Sole Proprietor)'],
  RO: ['Societate cu Răspundere Limitată (SRL)', 'Societate pe Acțiuni (SA)', 'Persoană Fizică Autorizată (PFA)'],
  HU: ['Korlátolt Felelősségű Társaság (Kft)', 'Részvénytársaság (Zrt)', 'Egyéni vállalkozó'],
  CZ: ['Společnost s ručením omezeným (s.r.o.)', 'Akciová společnost (a.s.)', 'Živnostník (Sole Proprietor)'],
  SK: ['Spoločnosť s ručením obmedzeným (s.r.o.)', 'Akciová spoločnosť (a.s.)', 'Živnostník (Sole Proprietor)'],
  BG: ['ЕООД (EOOD)', 'ООД (OOD)', 'АД (AD)', 'Едноличен търговец (ET)'],
  HR: ['Društvo s ograničenom odgovornošću (d.o.o.)', 'Dioničko društvo (d.d.)', 'Obrt (Sole Proprietor)'],
  RS: ['Društvo s ograničenom odgovornošću (d.o.o.)', 'Akcionarsko društvo (a.d.)', 'Preduzetnik (Sole Proprietor)'],
  LT: ['Uždaroji akcinė bendrovė (UAB)', 'Akcinė bendrovė (AB)', 'Individuali įmonė (IĮ)'],
  EE: ['Osaühing (OÜ)', 'Aktsiaselts (AS)', 'Füüsilisest isikust ettevõtja (FIE)'],
  LV: ['Sabiedrība ar ierobežotu atbildību (SIA)', 'Akciju sabiedrība (AS)', 'Individuālais komersants'],
};

/** Résout le code ISO du pays depuis son nom, puis retourne les formes juridiques adaptées. */
function legalFormsForCountry(countryName: string): string[] {
  const match = countries.find(c => c.name === countryName);
  const code = match?.code ?? '';
  return LEGAL_FORMS_BY_COUNTRY[code] ?? legalForms;
}

const emailValid = (e: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);

export function RegisterPage({ onSwitchToLogin, onBackHome }: RegisterPageProps) {
  const [searchParams] = useSearchParams();
  const initialType = searchParams.get('type') === 'business' ? 'business' : 'personal';
  const [accountType, setAccountType] = useState<'personal' | 'business'>(initialType);
  const [step, setStep] = useState(1);
  const [personal, setPersonal] = useState<PersonalData>(emptyPersonal);
  const [business, setBusiness] = useState<BusinessData>(emptyBusiness);
  const [visible, setVisible] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const { t } = useI18n();
  const { refreshSession } = useAuth();

  useEffect(() => {
    const type = searchParams.get('type');
    if (type === 'business' || type === 'personal') {
      setAccountType(type);
      setStep(1);
    }
  }, [searchParams]);

  const isBusiness = accountType === 'business';
  const steps = isBusiness
    ? [t('reg_step_1_business'), t('reg_step_2_business'), t('reg_step_2'), t('reg_step_3')]
    : [t('reg_step_1'), t('reg_step_2'), t('reg_step_3')];
  const totalSteps = steps.length;

  const password = isBusiness ? business.password : personal.password;
  const strength = password.length === 0 ? 0 : password.length < 8 ? 1 : password.length < 14 ? 2 : 3;
  const strengthLabels = ['', t('login_strength_short'), t('login_strength_ok'), t('login_strength_strong')] as const;
  const strengthColors = ['transparent', 'var(--danger)', 'var(--warning)', 'var(--success)'] as const;

  const switchType = (t: 'personal' | 'business') => {
    setAccountType(t);
    setStep(1);
    setError('');
  };

  const next = () => setStep(s => Math.min(s + 1, totalSteps));
  const back = () => setStep(s => Math.max(s - 1, 1));

  const validateStep = (): string => {
    if (isBusiness) {
      if (step === 1) {
        if (!business.companyName) return t('reg_err_company_name');
        if (!business.legalForm) return t('reg_err_legal_form');
      }
      if (step === 2) {
        if (!business.registrationNumber) return t('reg_err_registration');
      }
      if (step === 3) {
        if (!emailValid(business.companyEmail)) return t('reg_err_company_email');
        if (business.phone.trim().length < 6) return t('reg_err_phone');
      }
      if (step === 4) {
        if (business.password.length < 8) return t('reg_err_password_min');
      }
    } else {
      if (step === 1) {
        if (!personal.firstName || !personal.lastName) return t('reg_err_names');
        if (!personal.birthDate) return t('reg_err_birth');
      }
      if (step === 2) {
        if (!emailValid(personal.email)) return t('reg_err_email');
        if (personal.phone.trim().length < 6) return t('reg_err_phone');
      }
      if (step === 3) {
        if (personal.password.length < 8) return t('reg_err_password_min');
      }
    }
    return '';
  };

  const handleNext = () => {
    const err = validateStep();
    if (err) { setError(err); return; }
    setError('');
    next();
  };

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const err = validateStep();
    if (err) { setError(err); return; }
    setError('');
    setLoading(true);
    const name = isBusiness ? business.companyName : `${personal.firstName} ${personal.lastName}`.trim();
    const email = isBusiness ? business.companyEmail : personal.email;
    const phone = isBusiness ? business.phone : personal.phone;
    const phoneCode = isBusiness ? business.phoneCode : personal.phoneCode;
    const pw = isBusiness ? business.password : personal.password;

    // Riche profil : envoie toutes les infos collectées pour l'admin.
    const countryName = isBusiness ? business.companyCountry : personal.country;
    const countryCode = countries.find((c) => c.name === countryName)?.code ?? '';
    try {
      const resp = await apiRegister({
        full_name: name,
        email,
        password: pw,
        account_type: accountType,
        phone_code: phoneCode,
        phone,
        // Personnes physiques
        birth_date: !isBusiness ? personal.birthDate : undefined,
        country_of_residence: countryCode || undefined,
        // Entreprises
        company_name: isBusiness ? business.companyName : undefined,
        legal_form: isBusiness ? business.legalForm : undefined,
        company_registration_number: isBusiness ? business.registrationNumber : undefined,
        industry: isBusiness ? business.industry : undefined,
        company_size: isBusiness ? business.companySize : undefined,
        website: isBusiness ? business.website : undefined,
      });
      if (!resp.success) {
        setError(resp.error ?? 'Erreur lors de l\'inscription.');
        return;
      }
      // Revalide la session via le contexte React (le user est déjà retourné
      // par /api/register — aucune re-requête, fiable sur tous les navigateurs).
      await refreshSession();
      // Point d'entrée universel : la redirection finale (superadmin → /admin)
      // est gérée par DashboardLayout, sans course de timing navigateur.
      navigate('/dashboard', { replace: true });
    } catch {
      // Filet de sécurité : ne jamais laisser le formulaire bloqué en « envoi ».
      setError('Erreur lors de l\'inscription. Veuillez réessayer.');
    } finally {
      setLoading(false);
    }
  }

  const p = (field: keyof PersonalData, value: string) => setPersonal(prev => ({ ...prev, [field]: value }));
  const b = (field: keyof BusinessData, value: string) => setBusiness(prev => ({ ...prev, [field]: value }));

  const countryOptions = (
    <select className="form-control" value={isBusiness ? business.companyCountry : personal.country} onChange={e => {
      const val = e.target.value;
      if (isBusiness) {
        // Changement de pays : la forme juridique précédente peut ne plus être valide.
        setBusiness(prev => ({ ...prev, companyCountry: val, legalForm: '' }));
      } else {
        p('country', val);
      }
    }}>
      {countries.map(c => <option key={c.code} value={c.name}>{c.name} ({c.dial})</option>)}
    </select>
  );

  return (
    <div className="auth-page">
      <ParticlesBackground />
      <div className="auth-card">
        <div className="auth-glow" aria-hidden="true" />
        <div className="auth-card-inner">
          {/* Formulaire */}
          <div className="auth-form-side">
            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={0} className="auth-topbar">
              <button className="auth-back" onClick={onBackHome}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                {t('auth_back')}
              </button>
              <LanguageSwitcher />
            </motion.div>

            <motion.h1 variants={authEnter} initial="hidden" animate="visible" custom={1} className="auth-title">{t('reg_title')}</motion.h1>
            <motion.p variants={authEnter} initial="hidden" animate="visible" custom={2} className="auth-subtitle">{t('reg_subtitle')}</motion.p>

            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={3} className="account-type-selector">
              <motion.button type="button" className={`account-type ${accountType === 'personal' ? 'selected' : ''}`} onClick={() => switchType('personal')}
                whileHover={{ y: -3 }} whileTap={{ scale: 0.97 }} transition={{ type: 'spring', stiffness: 300, damping: 17 }}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <div className="account-type-text">
                  <span className="account-type-title">{t('reg_personal')}</span>
                  <span className="account-type-desc">{t('reg_personal_sub')}</span>
                </div>
              </motion.button>
              <motion.button type="button" className={`account-type ${accountType === 'business' ? 'selected' : ''}`} onClick={() => switchType('business')}
                whileHover={{ y: -3 }} whileTap={{ scale: 0.97 }} transition={{ type: 'spring', stiffness: 300, damping: 17 }}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                <div className="account-type-text">
                  <span className="account-type-title">{t('reg_business')}</span>
                  <span className="account-type-desc">{t('reg_business_sub')}</span>
                </div>
              </motion.button>
            </motion.div>

            {/* Stepper */}
            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={4} className="stepper">
              {steps.map((label, i) => {
                const n = i + 1;
                const active = n === step;
                const done = n < step;
                return (
                  <motion.div
                    key={label} className={`stepper-item ${active ? 'active' : ''} ${done ? 'done' : ''}`}
                    animate={{ opacity: active ? 1 : done ? 0.6 : 0.5, scale: active ? 1.04 : 1 }}
                    transition={{ type: 'spring', stiffness: 260, damping: 18 }}
                  >
                    <motion.div
                      className="stepper-dot"
                      layout
                      animate={{ scale: active ? 1.18 : 1, transition: { type: 'spring', stiffness: 300, damping: 15 } }}
                    >
                      {done ? <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><path d="M20 6L9 17l-5-5"/></svg> : n}
                    </motion.div>
                    <span className="stepper-label">{label}</span>
                  </motion.div>
                );
              })}
            </motion.div>

            <motion.form variants={authEnter} initial="hidden" animate="visible" custom={5} className="auth-form" onSubmit={handleSubmit} noValidate>
              <AnimatePresence mode="wait">
              <motion.div key={`${accountType}-${step}`}
                initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -12 }}
                transition={{ duration: 0.28, ease: EASE }}
                style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              {isBusiness && step === 1 && (
                <>
                  <div>
                    <label htmlFor="companyName" className="form-label">{t('reg_company_name')}</label>
                    <input id="companyName" type="text" className="form-control" placeholder={t('reg_company_name_ph')} value={business.companyName} onChange={e => b('companyName', e.target.value)} autoFocus />
                  </div>
                  <div className="form-grid-2">
                    <div>
                      <label htmlFor="legalForm" className="form-label">{t('reg_legal_form')}</label>
                      <select id="legalForm" className="form-control" value={business.legalForm} onChange={e => b('legalForm', e.target.value)}>
                        <option value="">{t('reg_select')}</option>
                        {legalFormsForCountry(business.companyCountry).map(f => <option key={f} value={f}>{f}</option>)}
                      </select>
                      <small className="form-hint">Adapté au pays : {business.companyCountry}</small>
                    </div>
                    <div>
                      <label htmlFor="companyCountry" className="form-label">{t('reg_company_country')}</label>
                      {countryOptions}
                    </div>
                  </div>
                </>
              )}

              {isBusiness && step === 2 && (
                <>
                  <div>
                    <label htmlFor="registrationNumber" className="form-label">{t('reg_registration')}</label>
                    <input id="registrationNumber" type="text" className="form-control" placeholder={t('reg_registration_ph')} value={business.registrationNumber} onChange={e => b('registrationNumber', e.target.value)} autoFocus />
                  </div>
                  <div className="form-grid-2">
                    <div>
                      <label htmlFor="industry" className="form-label">{t('reg_industry')}</label>
                      <input id="industry" type="text" className="form-control" placeholder={t('reg_industry_ph')} value={business.industry} onChange={e => b('industry', e.target.value)} />
                    </div>
                    <div>
                      <label htmlFor="companySize" className="form-label">{t('reg_company_size')}</label>
                      <select id="companySize" className="form-control" value={business.companySize} onChange={e => b('companySize', e.target.value)}>
                        <option value="">{t('reg_select')}</option>
                        <option value="1-10">{t('reg_size_1_10')}</option>
                        <option value="11-50">{t('reg_size_11_50')}</option>
                        <option value="51-200">{t('reg_size_51_200')}</option>
                        <option value="200+">{t('reg_size_200')}</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label htmlFor="website" className="form-label">{t('reg_website')}</label>
                    <input id="website" type="url" className="form-control" placeholder={t('reg_website_ph')} value={business.website} onChange={e => b('website', e.target.value)} />
                  </div>
                </>
              )}

              {isBusiness && step === 3 && (
                <>
                  <div>
                    <label htmlFor="companyEmail" className="form-label">{t('reg_company_email')}</label>
                    <input id="companyEmail" type="email" className="form-control" placeholder={t('reg_company_email_ph')} value={business.companyEmail} onChange={e => b('companyEmail', e.target.value)} autoFocus autoComplete="email" />
                    <div style={{ fontSize: '0.72rem', color: 'var(--text-faint)', marginTop: 4 }}>{t('reg_company_email_hint')}</div>
                  </div>
                  <div>
                    <label htmlFor="phone" className="form-label">{t('reg_contact_phone')}</label>
                    <div className="phone-prefix">
                      <select className="form-control phone-code" value={business.phoneCode} onChange={e => b('phoneCode', e.target.value)} aria-label={t('login_phone_code_label')}>
                        {countries.map(c => <option key={c.code} value={c.dial}>{c.dial} {c.code}</option>)}
                      </select>
                      <input id="phone" type="tel" className="form-control phone-number" placeholder={t('login_phone_placeholder')} value={business.phone} onChange={e => b('phone', e.target.value)} autoComplete="tel" />
                    </div>
                  </div>
                </>
              )}

              {!isBusiness && step === 1 && (
                <>
                  <div className="form-grid-2">
                    <div>
                      <label htmlFor="firstName" className="form-label">{t('reg_first_name')}</label>
                      <input id="firstName" type="text" className="form-control" placeholder={t('reg_first_name_ph')} value={personal.firstName} onChange={e => p('firstName', e.target.value)} autoFocus autoComplete="given-name" />
                    </div>
                    <div>
                      <label htmlFor="lastName" className="form-label">{t('reg_last_name')}</label>
                      <input id="lastName" type="text" className="form-control" placeholder={t('reg_last_name_ph')} value={personal.lastName} onChange={e => p('lastName', e.target.value)} autoComplete="family-name" />
                    </div>
                  </div>
                  <div className="form-grid-2">
                    <div>
                      <label htmlFor="birthDate" className="form-label">{t('reg_birth')}</label>
                      <input id="birthDate" type="date" className="form-control" value={personal.birthDate} onChange={e => p('birthDate', e.target.value)} />
                    </div>
                    <div>
                      <label htmlFor="country" className="form-label">{t('reg_country')}</label>
                      {countryOptions}
                    </div>
                  </div>
                </>
              )}

              {!isBusiness && step === 2 && (
                <>
                  <div>
                    <label htmlFor="email" className="form-label">{t('reg_email')}</label>
                    <input id="email" type="email" className="form-control" placeholder={t('reg_email_ph')} value={personal.email} onChange={e => p('email', e.target.value)} autoFocus autoComplete="email" />
                  </div>
                  <div>
                    <label htmlFor="phone" className="form-label">{t('reg_phone')}</label>
                    <div className="phone-prefix">
                      <select className="form-control phone-code" value={personal.phoneCode} onChange={e => p('phoneCode', e.target.value)} aria-label={t('login_phone_code_label')}>
                        {countries.map(c => <option key={c.code} value={c.dial}>{c.dial} {c.code}</option>)}
                      </select>
                      <input id="phone" type="tel" className="form-control phone-number" placeholder={t('login_phone_placeholder')} value={personal.phone} onChange={e => p('phone', e.target.value)} autoComplete="tel" />
                    </div>
                  </div>
                </>
              )}

              {(step === (isBusiness ? 4 : 3)) && (
                <div>
                  <label htmlFor="password" className="form-label">{t('reg_password')}</label>
                  <div className="password-wrapper">
                    <input id="password" type={visible ? 'text' : 'password'} className="form-control" style={{ paddingRight: '2.75rem' }} placeholder={t('reg_password_ph')} value={password} onChange={e => isBusiness ? b('password', e.target.value) : p('password', e.target.value)} autoComplete="new-password" />
                    <button type="button" className="password-toggle" onClick={() => setVisible(v => !v)} aria-label={visible ? t('login_hide') : t('login_show')}>
                      {visible ? <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg> : <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>}
                    </button>
                  </div>
                  {password.length > 0 && (
                    <div style={{ marginTop: 8 }}>
                      <div style={{ display: 'flex', gap: 4, marginBottom: 6 }}>
                        {[1, 2, 3].map(n => <div key={n} style={{ height: 3, flex: 1, borderRadius: 2, background: strength >= n ? strengthColors[strength] : 'rgba(255,255,255,0.09)', transition: 'background 0.2s ease' }} />)}
                      </div>
                      <div style={{ fontSize: '0.75rem', color: strength === 0 ? 'var(--text-faint)' : strengthColors[strength] }}>
                        {strength === 0 ? t('reg_password_hint') : `${strengthLabels[strength]} · ${password.length} ${password.length > 1 ? t('login_chars_plural') : t('login_chars_singular')}`}
                      </div>
                    </div>
                  )}
                </div>
              )}

              </motion.div>
              </AnimatePresence>
              {error && <div className="auth-error">{error}</div>}

              <div className="wizard-actions">
                {step > 1 && (
                  <motion.button type="button" className="btn btn-ghost" onClick={back}
                    whileHover={{ x: -3 }} whileTap={{ scale: 0.96 }} transition={{ type: 'spring', stiffness: 300, damping: 17 }}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    {t('reg_previous')}
                  </motion.button>
                )}
                {step < totalSteps ? (
                  <motion.button type="button" className="btn btn-primary" onClick={handleNext} style={{ flex: 1 }}
                    whileHover={{ scale: 1.02, y: -1 }} whileTap={{ scale: 0.97 }} transition={{ type: 'spring', stiffness: 300, damping: 17 }}>
                    {t('reg_continue')}
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                  </motion.button>
                ) : (
                  <motion.button type="submit" className="btn btn-glow btn-block btn-lg" disabled={loading}
                    whileHover={{ scale: 1.02, y: -1 }} whileTap={{ scale: 0.97 }} transition={{ type: 'spring', stiffness: 300, damping: 17 }}>
                    {loading ? <><span className="spinner" /> {t('reg_sending')}</> : t(isBusiness ? 'reg_submit_business' : 'reg_submit_personal')}
                  </motion.button>
                )}
              </div>
            </motion.form>

            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={6} className="auth-terms">
              {t('reg_terms_prefix')} <a href="#">{t('reg_terms_terms')}</a> {t('reg_terms_and')} <a href="#">{t('reg_terms_privacy')}</a>
            </motion.div>

            <motion.p variants={authEnter} initial="hidden" animate="visible" custom={7} className="auth-footer">
              {t('login_no_account')} <button className="auth-link-button" onClick={onSwitchToLogin}>{t('login_submit')}</button>
            </motion.p>
          </div>

          {/* Panneau latéral */}
          <aside className="auth-panel-side">
            <motion.div variants={authEnter} initial="hidden" animate="visible" custom={1} className="trust-panel-badge">
              <span className="pulse-dot success" />
              {t(isBusiness ? 'reg_panel_badge_biz' : 'reg_panel_badge')}
            </motion.div>
            <motion.h3 variants={authEnter} initial="hidden" animate="visible" custom={2} className="trust-panel-title">
              <span style={{ color: 'var(--text-heading)' }}>{t(isBusiness ? 'reg_panel_biz_title_1' : 'reg_panel_title_1')}</span>
              <br />
              <span className="gradient-text">{t(isBusiness ? 'reg_panel_biz_title_2' : 'reg_panel_title_2')}</span>
            </motion.h3>
            <motion.p variants={authEnter} initial="hidden" animate="visible" custom={3} style={{ fontSize: '0.875rem', color: 'var(--text-muted)', lineHeight: 1.6 }}>
              {isBusiness ? t('reg_panel_text_biz') : t('reg_panel_text')}
            </motion.p>
            <motion.div className="trust-panel-items" style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem', marginTop: '1rem' }}
              initial="hidden" animate="visible" variants={{ hidden: {}, visible: { transition: { staggerChildren: 0.12 } } }}>
              <motion.div className="trust-panel-item" variants={authEnter} custom={1} whileHover={{ x: 6, transition: { type: 'spring', stiffness: 260, damping: 20 } }}>
                <motion.div className="trust-panel-icon" whileHover={{ scale: 1.12 }} transition={{ type: 'spring', stiffness: 300, damping: 15 }}>
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </motion.div>
                <div>
                  <h4>{t('reg_trust_1_title')}</h4>
                  <p>{t('reg_trust_1_text')}</p>
                </div>
              </motion.div>
              <motion.div className="trust-panel-item" variants={authEnter} custom={2} whileHover={{ x: 6, transition: { type: 'spring', stiffness: 260, damping: 20 } }}>
                <motion.div className="trust-panel-icon" whileHover={{ scale: 1.12 }} transition={{ type: 'spring', stiffness: 300, damping: 15 }}>
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/></svg>
                </motion.div>
                <div>
                  <h4>{t(isBusiness ? 'reg_trust_2_title_biz' : 'reg_trust_2_title')}</h4>
                  <p>{t(isBusiness ? 'reg_trust_2_text_biz' : 'reg_trust_2_text')}</p>
                </div>
              </motion.div>
            </motion.div>
          </aside>
        </div>

        <p className="auth-note">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          {t('auth_note')}
        </p>
      </div>
    </div>
  );
}
