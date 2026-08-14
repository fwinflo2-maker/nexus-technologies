/**
 * i18n des dashboards Personal & Business — 7 langues (fr, en, es, pt, de, ar, zh).
 *
 * Les pages d'authentification et le landing utilisent déjà `data/translations.ts`.
 * Ce module couvre la navigation, les états UI et les KPI des dashboards,
 * de sorte que le sélecteur de langue (LanguageSwitcher, présent dans le topbar)
 * modifie réellement l'interface dans les 7 langues.
 *
 * Usage :
 *   const t = useDashT();
 *   t('nav.send')  // → 'Envoyer' / 'Send' / 'Enviar' …
 */
import { useI18n } from '../context/I18nContext';

export type LangCode = 'fr' | 'en' | 'es' | 'pt' | 'de' | 'ar' | 'zh';

export const DASH_LANGS: { code: LangCode; flag: string; name: string }[] = [
  { code: 'fr', flag: '🇫🇷', name: 'Français' },
  { code: 'en', flag: '🇬🇧', name: 'English' },
  { code: 'es', flag: '🇪🇸', name: 'Español' },
  { code: 'pt', flag: '🇵🇹', name: 'Português' },
  { code: 'de', flag: '🇩🇪', name: 'Deutsch' },
  { code: 'ar', flag: '🇸🇦', name: 'العربية' },
  { code: 'zh', flag: '🇨🇳', name: '中文' },
];

type Dict = Record<string, string>;

const fr: Dict = {
  // Navigation
  'nav.dashboard': 'Tableau de bord', 'nav.wallet': 'Portefeuille', 'nav.send': 'Envoyer',
  'nav.receive': 'Recevoir', 'nav.convert': 'Convertir', 'nav.history': 'Historique',
  'nav.notifications': 'Notifications', 'nav.treasury': 'Trésorerie', 'nav.payments': 'Paiements',
  'nav.approvals': 'Approbations', 'nav.beneficiaries': 'Bénéficiaires', 'nav.reconciliation': 'Rapprochement',
  'nav.team': 'Équipe & Rôles', 'nav.reporting': 'Reporting', 'nav.kyc': 'KYC / KYB',
  'nav.agents': 'Nexus Core', 'nav.settings': 'Paramètres', 'nav.system': 'Système',
  'nav.personal': 'Personnel', 'nav.business': 'Business',
  // États communs
  'common.loading': 'Chargement…', 'common.retry': 'Réessayer', 'common.error': 'Erreur de chargement',
  'common.noData': 'Aucune donnée pour le moment', 'common.empty': 'Aucun élément',
  'common.cancel': 'Annuler', 'common.confirm': 'Confirmer', 'common.save': 'Enregistrer',
  'common.back': 'Retour', 'common.refresh': 'Actualiser', 'common.create': 'Créer',
  'common.execute': 'Exécuter', 'common.approve': 'Approuver', 'common.reject': 'Rejeter',
  'common.submit': 'Soumettre', 'common.search': 'Rechercher', 'common.all': 'Tous',
  'common.active': 'Actifs', 'common.view': 'Voir',
  // Statuts
  'status.draft': 'Brouillon', 'status.pending_approval': "En attente d'approbation",
  'status.approved': 'Approuvé', 'status.executing': 'Exécution', 'status.completed': 'Terminé',
  'status.failed': 'Échoué', 'status.rejected': 'Rejeté', 'status.cancelled': 'Annulé',
  'status.pending': 'En attente', 'status.processing': 'En cours', 'status.matched': 'Rapproché',
  'status.unmatched': 'Non rapproché', 'status.discrepancy': 'Écart détecté', 'status.resolved': 'Résolu',
  'status.active': 'Actif', 'status.inactive': 'Inactif', 'status.verified': 'Vérifié',
  'status.unverified': 'Non vérifié',
  // KPI Business
  'biz.total_assets': 'Total des actifs', 'biz.available': 'Disponible', 'biz.pending': 'En attente',
  'biz.in_transit': 'En transit', 'biz.settlement': 'Règlement', 'biz.payables': 'À payer (payables)',
  'biz.volume_30d': 'Volume 30 j', 'biz.fees_30d': 'Frais 30 j', 'biz.success_rate': 'Taux de réussite',
  'biz.avg_exec': "Temps d'exécution moy.", 'biz.cash_flow': 'Flux de trésorerie',
  'biz.inflows': 'Entrées', 'biz.outflows': 'Sorties', 'biz.providers_perf': 'Performance des providers',
  'biz.treasury_by_currency': 'Trésorerie par devise', 'biz.financial_console': 'Console financière',
  'biz.liquidity': 'Liquidité totale', 'biz.available_now': 'Disponible immédiatement',
  'biz.fx_exposure': 'Exposition FX',
  // KPI Personal
  'pers.total_balance': 'Solde total', 'pers.available_balance': 'Solde disponible',
  'pers.pending': 'En attente', 'pers.in_transit': 'En transit', 'pers.held': 'Réservé',
  'pers.settlement': 'Règlement', 'pers.recent_activity': 'Activité récente', 'pers.quick_actions': 'Actions rapides',
};

const en: Dict = {
  'nav.dashboard': 'Dashboard', 'nav.wallet': 'Wallet', 'nav.send': 'Send', 'nav.receive': 'Receive',
  'nav.convert': 'Convert', 'nav.history': 'History', 'nav.notifications': 'Notifications',
  'nav.treasury': 'Treasury', 'nav.payments': 'Payments', 'nav.approvals': 'Approvals',
  'nav.beneficiaries': 'Beneficiaries', 'nav.reconciliation': 'Reconciliation', 'nav.team': 'Team & Roles',
  'nav.reporting': 'Reporting', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': 'Settings',
  'nav.system': 'System', 'nav.personal': 'Personal', 'nav.business': 'Business',
  'common.loading': 'Loading…', 'common.retry': 'Retry', 'common.error': 'Failed to load',
  'common.noData': 'No data yet', 'common.empty': 'No items', 'common.cancel': 'Cancel',
  'common.confirm': 'Confirm', 'common.save': 'Save', 'common.back': 'Back', 'common.refresh': 'Refresh',
  'common.create': 'Create', 'common.execute': 'Execute', 'common.approve': 'Approve', 'common.reject': 'Reject',
  'common.submit': 'Submit', 'common.search': 'Search', 'common.all': 'All', 'common.active': 'Active', 'common.view': 'View',
  'status.draft': 'Draft', 'status.pending_approval': 'Pending approval', 'status.approved': 'Approved',
  'status.executing': 'Executing', 'status.completed': 'Completed', 'status.failed': 'Failed',
  'status.rejected': 'Rejected', 'status.cancelled': 'Cancelled', 'status.pending': 'Pending',
  'status.processing': 'Processing', 'status.matched': 'Matched', 'status.unmatched': 'Unmatched',
  'status.discrepancy': 'Discrepancy', 'status.resolved': 'Resolved', 'status.active': 'Active',
  'status.inactive': 'Inactive', 'status.verified': 'Verified', 'status.unverified': 'Unverified',
  'biz.total_assets': 'Total assets', 'biz.available': 'Available', 'biz.pending': 'Pending',
  'biz.in_transit': 'In transit', 'biz.settlement': 'Settlement', 'biz.payables': 'Payables',
  'biz.volume_30d': 'Volume 30d', 'biz.fees_30d': 'Fees 30d', 'biz.success_rate': 'Success rate',
  'biz.avg_exec': 'Avg. execution time', 'biz.cash_flow': 'Cash flow', 'biz.inflows': 'Inflows',
  'biz.outflows': 'Outflows', 'biz.providers_perf': 'Provider performance', 'biz.treasury_by_currency': 'Treasury by currency',
  'biz.financial_console': 'Financial console', 'biz.liquidity': 'Total liquidity', 'biz.available_now': 'Available now',
  'biz.fx_exposure': 'FX exposure',
  'pers.total_balance': 'Total balance', 'pers.available_balance': 'Available balance', 'pers.pending': 'Pending',
  'pers.in_transit': 'In transit', 'pers.held': 'Held', 'pers.settlement': 'Settlement',
  'pers.recent_activity': 'Recent activity', 'pers.quick_actions': 'Quick actions',
};

const es: Dict = {
  'nav.dashboard': 'Panel', 'nav.wallet': 'Cartera', 'nav.send': 'Enviar', 'nav.receive': 'Recibir',
  'nav.convert': 'Convertir', 'nav.history': 'Historial', 'nav.notifications': 'Notificaciones',
  'nav.treasury': 'Tesorería', 'nav.payments': 'Pagos', 'nav.approvals': 'Aprobaciones',
  'nav.beneficiaries': 'Beneficiarios', 'nav.reconciliation': 'Conciliación', 'nav.team': 'Equipo y roles',
  'nav.reporting': 'Informes', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': 'Ajustes',
  'nav.system': 'Sistema', 'nav.personal': 'Personal', 'nav.business': 'Empresa',
  'common.loading': 'Cargando…', 'common.retry': 'Reintentar', 'common.error': 'Error al cargar',
  'common.noData': 'Aún no hay datos', 'common.empty': 'Sin elementos', 'common.cancel': 'Cancelar',
  'common.confirm': 'Confirmar', 'common.save': 'Guardar', 'common.back': 'Volver', 'common.refresh': 'Actualizar',
  'common.create': 'Crear', 'common.execute': 'Ejecutar', 'common.approve': 'Aprobar', 'common.reject': 'Rechazar',
  'common.submit': 'Enviar', 'common.search': 'Buscar', 'common.all': 'Todos', 'common.active': 'Activos', 'common.view': 'Ver',
  'status.draft': 'Borrador', 'status.pending_approval': 'Pendiente de aprobación', 'status.approved': 'Aprobado',
  'status.executing': 'Ejecutando', 'status.completed': 'Completado', 'status.failed': 'Fallido',
  'status.rejected': 'Rechazado', 'status.cancelled': 'Cancelado', 'status.pending': 'Pendiente',
  'status.processing': 'Procesando', 'status.matched': 'Conciliado', 'status.unmatched': 'Sin conciliar',
  'status.discrepancy': 'Discrepancia', 'status.resolved': 'Resuelto', 'status.active': 'Activo',
  'status.inactive': 'Inactivo', 'status.verified': 'Verificado', 'status.unverified': 'Sin verificar',
  'biz.total_assets': 'Activos totales', 'biz.available': 'Disponible', 'biz.pending': 'Pendiente',
  'biz.in_transit': 'En tránsito', 'biz.settlement': 'Liquidación', 'biz.payables': 'Por pagar',
  'biz.volume_30d': 'Volumen 30 d', 'biz.fees_30d': 'Comisiones 30 d', 'biz.success_rate': 'Tasa de éxito',
  'biz.avg_exec': 'Tiempo medio de ejecución', 'biz.cash_flow': 'Flujo de caja', 'biz.inflows': 'Entradas',
  'biz.outflows': 'Salidas', 'biz.providers_perf': 'Rendimiento de proveedores', 'biz.treasury_by_currency': 'Tesorería por divisa',
  'biz.financial_console': 'Consola financiera', 'biz.liquidity': 'Liquidez total', 'biz.available_now': 'Disponible ahora',
  'biz.fx_exposure': 'Exposición FX',
  'pers.total_balance': 'Saldo total', 'pers.available_balance': 'Saldo disponible', 'pers.pending': 'Pendiente',
  'pers.in_transit': 'En tránsito', 'pers.held': 'Retenido', 'pers.settlement': 'Liquidación',
  'pers.recent_activity': 'Actividad reciente', 'pers.quick_actions': 'Acciones rápidas',
};

const pt: Dict = {
  'nav.dashboard': 'Painel', 'nav.wallet': 'Carteira', 'nav.send': 'Enviar', 'nav.receive': 'Receber',
  'nav.convert': 'Converter', 'nav.history': 'Histórico', 'nav.notifications': 'Notificações',
  'nav.treasury': 'Tesouraria', 'nav.payments': 'Pagamentos', 'nav.approvals': 'Aprovações',
  'nav.beneficiaries': 'Beneficiários', 'nav.reconciliation': 'Conciliação', 'nav.team': 'Equipa e funções',
  'nav.reporting': 'Relatórios', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': 'Definições',
  'nav.system': 'Sistema', 'nav.personal': 'Pessoal', 'nav.business': 'Empresa',
  'common.loading': 'A carregar…', 'common.retry': 'Tentar novamente', 'common.error': 'Erro ao carregar',
  'common.noData': 'Sem dados por enquanto', 'common.empty': 'Sem itens', 'common.cancel': 'Cancelar',
  'common.confirm': 'Confirmar', 'common.save': 'Guardar', 'common.back': 'Voltar', 'common.refresh': 'Atualizar',
  'common.create': 'Criar', 'common.execute': 'Executar', 'common.approve': 'Aprovar', 'common.reject': 'Rejeitar',
  'common.submit': 'Submeter', 'common.search': 'Pesquisar', 'common.all': 'Todos', 'common.active': 'Ativos', 'common.view': 'Ver',
  'status.draft': 'Rascunho', 'status.pending_approval': 'A aguardar aprovação', 'status.approved': 'Aprovado',
  'status.executing': 'Em execução', 'status.completed': 'Concluído', 'status.failed': 'Falhado',
  'status.rejected': 'Rejeitado', 'status.cancelled': 'Cancelado', 'status.pending': 'Pendente',
  'status.processing': 'A processar', 'status.matched': 'Conciliado', 'status.unmatched': 'Não conciliado',
  'status.discrepancy': 'Divergência', 'status.resolved': 'Resolvido', 'status.active': 'Ativo',
  'status.inactive': 'Inativo', 'status.verified': 'Verificado', 'status.unverified': 'Não verificado',
  'biz.total_assets': 'Ativos totais', 'biz.available': 'Disponível', 'biz.pending': 'Pendente',
  'biz.in_transit': 'Em trânsito', 'biz.settlement': 'Liquidação', 'biz.payables': 'A pagar',
  'biz.volume_30d': 'Volume 30 d', 'biz.fees_30d': 'Taxas 30 d', 'biz.success_rate': 'Taxa de sucesso',
  'biz.avg_exec': 'Tempo médio de execução', 'biz.cash_flow': 'Fluxo de caixa', 'biz.inflows': 'Entradas',
  'biz.outflows': 'Saídas', 'biz.providers_perf': 'Desempenho de fornecedores', 'biz.treasury_by_currency': 'Tesouraria por moeda',
  'biz.financial_console': 'Consola financeira', 'biz.liquidity': 'Liquidez total', 'biz.available_now': 'Disponível agora',
  'biz.fx_exposure': 'Exposição FX',
  'pers.total_balance': 'Saldo total', 'pers.available_balance': 'Saldo disponível', 'pers.pending': 'Pendente',
  'pers.in_transit': 'Em trânsito', 'pers.held': 'Retido', 'pers.settlement': 'Liquidação',
  'pers.recent_activity': 'Atividade recente', 'pers.quick_actions': 'Ações rápidas',
};

const de: Dict = {
  'nav.dashboard': 'Übersicht', 'nav.wallet': 'Wallet', 'nav.send': 'Senden', 'nav.receive': 'Empfangen',
  'nav.convert': 'Umwandeln', 'nav.history': 'Verlauf', 'nav.notifications': 'Benachrichtigungen',
  'nav.treasury': 'Treasury', 'nav.payments': 'Zahlungen', 'nav.approvals': 'Genehmigungen',
  'nav.beneficiaries': 'Empfänger', 'nav.reconciliation': 'Abgleich', 'nav.team': 'Team & Rollen',
  'nav.reporting': 'Berichte', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': 'Einstellungen',
  'nav.system': 'System', 'nav.personal': 'Privat', 'nav.business': 'Geschäftlich',
  'common.loading': 'Wird geladen…', 'common.retry': 'Erneut versuchen', 'common.error': 'Fehler beim Laden',
  'common.noData': 'Noch keine Daten', 'common.empty': 'Keine Einträge', 'common.cancel': 'Abbrechen',
  'common.confirm': 'Bestätigen', 'common.save': 'Speichern', 'common.back': 'Zurück', 'common.refresh': 'Aktualisieren',
  'common.create': 'Erstellen', 'common.execute': 'Ausführen', 'common.approve': 'Genehmigen', 'common.reject': 'Ablehnen',
  'common.submit': 'Einreichen', 'common.search': 'Suchen', 'common.all': 'Alle', 'common.active': 'Aktiv', 'common.view': 'Ansehen',
  'status.draft': 'Entwurf', 'status.pending_approval': 'Genehmigung ausstehend', 'status.approved': 'Genehmigt',
  'status.executing': 'Wird ausgeführt', 'status.completed': 'Abgeschlossen', 'status.failed': 'Fehlgeschlagen',
  'status.rejected': 'Abgelehnt', 'status.cancelled': 'Storniert', 'status.pending': 'Ausstehend',
  'status.processing': 'In Bearbeitung', 'status.matched': 'Abgeglichen', 'status.unmatched': 'Nicht abgeglichen',
  'status.discrepancy': 'Abweichung', 'status.resolved': 'Gelöst', 'status.active': 'Aktiv',
  'status.inactive': 'Inaktiv', 'status.verified': 'Verifiziert', 'status.unverified': 'Unverifiziert',
  'biz.total_assets': 'Gesamtvermögen', 'biz.available': 'Verfügbar', 'biz.pending': 'Ausstehend',
  'biz.in_transit': 'Unterwegs', 'biz.settlement': 'Abwicklung', 'biz.payables': 'Verbindlichkeiten',
  'biz.volume_30d': 'Volumen 30 T', 'biz.fees_30d': 'Gebühren 30 T', 'biz.success_rate': 'Erfolgsquote',
  'biz.avg_exec': 'Durchschn. Ausführungszeit', 'biz.cash_flow': 'Cashflow', 'biz.inflows': 'Zuflüsse',
  'biz.outflows': 'Abflüsse', 'biz.providers_perf': 'Provider-Leistung', 'biz.treasury_by_currency': 'Treasury nach Währung',
  'biz.financial_console': 'Finanzkonsole', 'biz.liquidity': 'Gesamtliquidität', 'biz.available_now': 'Jetzt verfügbar',
  'biz.fx_exposure': 'FX-Exposure',
  'pers.total_balance': 'Gesamtsaldo', 'pers.available_balance': 'Verfügbarer Saldo', 'pers.pending': 'Ausstehend',
  'pers.in_transit': 'Unterwegs', 'pers.held': 'Reserviert', 'pers.settlement': 'Abwicklung',
  'pers.recent_activity': 'Letzte Aktivität', 'pers.quick_actions': 'Schnellaktionen',
};

const ar: Dict = {
  'nav.dashboard': 'لوحة التحكم', 'nav.wallet': 'المحفظة', 'nav.send': 'إرسال', 'nav.receive': 'استلام',
  'nav.convert': 'تحويل', 'nav.history': 'السجل', 'nav.notifications': 'الإشعارات',
  'nav.treasury': 'الخزينة', 'nav.payments': 'المدفوعات', 'nav.approvals': 'الموافقات',
  'nav.beneficiaries': 'المستفيدون', 'nav.reconciliation': 'المطابقة', 'nav.team': 'الفريق والأدوار',
  'nav.reporting': 'التقارير', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': 'الإعدادات',
  'nav.system': 'النظام', 'nav.personal': 'شخصي', 'nav.business': 'الأعمال',
  'common.loading': 'جارٍ التحميل…', 'common.retry': 'إعادة المحاولة', 'common.error': 'خطأ في التحميل',
  'common.noData': 'لا توجد بيانات بعد', 'common.empty': 'لا توجد عناصر', 'common.cancel': 'إلغاء',
  'common.confirm': 'تأكيد', 'common.save': 'حفظ', 'common.back': 'رجوع', 'common.refresh': 'تحديث',
  'common.create': 'إنشاء', 'common.execute': 'تنفيذ', 'common.approve': 'موافقة', 'common.reject': 'رفض',
  'common.submit': 'إرسال', 'common.search': 'بحث', 'common.all': 'الكل', 'common.active': 'نشط', 'common.view': 'عرض',
  'status.draft': 'مسودة', 'status.pending_approval': 'بانتظار الموافقة', 'status.approved': 'تمت الموافقة',
  'status.executing': 'قيد التنفيذ', 'status.completed': 'مكتمل', 'status.failed': 'فشل',
  'status.rejected': 'مرفوض', 'status.cancelled': 'ملغى', 'status.pending': 'قيد الانتظار',
  'status.processing': 'قيد المعالجة', 'status.matched': 'مطابق', 'status.unmatched': 'غير مطابق',
  'status.discrepancy': 'فرق مكتشف', 'status.resolved': 'تم الحل', 'status.active': 'نشط',
  'status.inactive': 'غير نشط', 'status.verified': 'تم التحقق', 'status.unverified': 'غير محقق',
  'biz.total_assets': 'إجمالي الأصول', 'biz.available': 'متاح', 'biz.pending': 'قيد الانتظار',
  'biz.in_transit': 'قيد النقل', 'biz.settlement': 'التسوية', 'biz.payables': 'المستحقات الدائنة',
  'biz.volume_30d': 'حجم 30 يوم', 'biz.fees_30d': 'الرسوم 30 يوم', 'biz.success_rate': 'معدل النجاح',
  'biz.avg_exec': 'متوسط وقت التنفيذ', 'biz.cash_flow': 'التدفق النقدي', 'biz.inflows': 'التدفقات الداخلة',
  'biz.outflows': 'التدفقات الخارجة', 'biz.providers_perf': 'أداء المزودين', 'biz.treasury_by_currency': 'الخزينة حسب العملة',
  'biz.financial_console': 'الكونسول المالي', 'biz.liquidity': 'إجمالي السيولة', 'biz.available_now': 'متاح الآن',
  'biz.fx_exposure': 'التعرض للعملات',
  'pers.total_balance': 'الرصيد الإجمالي', 'pers.available_balance': 'الرصيد المتاح', 'pers.pending': 'قيد الانتظار',
  'pers.in_transit': 'قيد النقل', 'pers.held': 'محجوز', 'pers.settlement': 'التسوية',
  'pers.recent_activity': 'النشاط الأخير', 'pers.quick_actions': 'إجراءات سريعة',
};

const zh: Dict = {
  'nav.dashboard': '仪表盘', 'nav.wallet': '钱包', 'nav.send': '发送', 'nav.receive': '收款',
  'nav.convert': '兑换', 'nav.history': '历史记录', 'nav.notifications': '通知',
  'nav.treasury': '财资管理', 'nav.payments': '付款', 'nav.approvals': '审批',
  'nav.beneficiaries': '收款人', 'nav.reconciliation': '对账', 'nav.team': '团队与角色',
  'nav.reporting': '报表', 'nav.kyc': 'KYC / KYB', 'nav.agents': 'Nexus Core', 'nav.settings': '设置',
  'nav.system': '系统', 'nav.personal': '个人', 'nav.business': '企业',
  'common.loading': '加载中…', 'common.retry': '重试', 'common.error': '加载失败',
  'common.noData': '暂无数据', 'common.empty': '没有条目', 'common.cancel': '取消',
  'common.confirm': '确认', 'common.save': '保存', 'common.back': '返回', 'common.refresh': '刷新',
  'common.create': '创建', 'common.execute': '执行', 'common.approve': '批准', 'common.reject': '拒绝',
  'common.submit': '提交', 'common.search': '搜索', 'common.all': '全部', 'common.active': '活跃', 'common.view': '查看',
  'status.draft': '草稿', 'status.pending_approval': '待审批', 'status.approved': '已批准',
  'status.executing': '执行中', 'status.completed': '已完成', 'status.failed': '失败',
  'status.rejected': '已拒绝', 'status.cancelled': '已取消', 'status.pending': '待处理',
  'status.processing': '处理中', 'status.matched': '已匹配', 'status.unmatched': '未匹配',
  'status.discrepancy': '存在差异', 'status.resolved': '已解决', 'status.active': '活跃',
  'status.inactive': '停用', 'status.verified': '已验证', 'status.unverified': '未验证',
  'biz.total_assets': '总资产', 'biz.available': '可用', 'biz.pending': '待处理',
  'biz.in_transit': '在途', 'biz.settlement': '结算', 'biz.payables': '应付',
  'biz.volume_30d': '30天交易量', 'biz.fees_30d': '30天费用', 'biz.success_rate': '成功率',
  'biz.avg_exec': '平均执行时间', 'biz.cash_flow': '现金流', 'biz.inflows': '流入',
  'biz.outflows': '流出', 'biz.providers_perf': '服务商表现', 'biz.treasury_by_currency': '按币种财资',
  'biz.financial_console': '财务控制台', 'biz.liquidity': '总流动性', 'biz.available_now': '当前可用',
  'biz.fx_exposure': '外汇敞口',
  'pers.total_balance': '总余额', 'pers.available_balance': '可用余额', 'pers.pending': '待处理',
  'pers.in_transit': '在途', 'pers.held': '冻结', 'pers.settlement': '结算',
  'pers.recent_activity': '最近动态', 'pers.quick_actions': '快捷操作',
};

const DICTS: Record<LangCode, Dict> = { fr, en, es, pt, de, ar, zh };

/** Hook : renvoie la fonction de traduction des dashboards pour la langue active. */
export function useDashT(): (key: string) => string {
  const { lang } = useI18n();
  const dict = DICTS[(lang as LangCode) in DICTS ? (lang as LangCode) : 'fr'];
  return (key: string) => dict[key] ?? fr[key] ?? key;
}

/** Traduction hors hook (statuts, utilitaires) — lit la langue persistée. */
export function dashTranslate(key: string): string {
  let lang: LangCode = 'fr';
  try {
    const saved = localStorage.getItem('nexus_lang') as LangCode | null;
    if (saved && saved in DICTS) lang = saved;
  } catch {
    // SSR / sandbox : fr par défaut.
  }
  return DICTS[lang][key] ?? fr[key] ?? key;
}
