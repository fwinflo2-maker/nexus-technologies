import type { ControlClientDetail } from '../api/client';

/** Palette dossier Nexus (RGB pour jsPDF). */
const INK = [10, 16, 24] as const;
const PLATE = [18, 26, 38] as const;
const BRASS = [212, 176, 106] as const;
const STEEL = [157, 176, 200] as const;
const PAPER = [244, 241, 234] as const;
const MINT = [93, 202, 165] as const;

const ISO_NUM_CUR: Record<string, string> = {
  '978': 'EUR',
  '840': 'USD',
  '950': 'XAF',
  '952': 'XOF',
  '826': 'GBP',
};

const COUNTRY_NAME: Record<string, string> = {
  FR: 'France', US: 'États-Unis', GB: 'Royaume-Uni', CM: 'Cameroun', CG: 'Congo',
};

type JsPdfDoc = import('jspdf').jsPDF;

function countryLabel(code: string | null): string {
  return (code && COUNTRY_NAME[code]) || code || '—';
}

function currencyLabel(code: unknown): string {
  const raw = String(code ?? '').trim();
  if (!raw) return '—';
  if (/^[A-Z]{3}$/.test(raw)) return raw;
  return ISO_NUM_CUR[raw] ?? raw;
}

function money(v: string | number, cur: string): string {
  const n = Number(v);
  const label = currencyLabel(cur);
  if (!isFinite(n)) return `— ${label}`;
  return `${n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${label}`;
}

function fmtDate(raw: unknown): string {
  if (!raw) return '—';
  const d = new Date(String(raw));
  if (Number.isNaN(d.getTime())) return String(raw);
  return d.toLocaleString('fr-FR', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    ACTIVE: 'Actif', PENDING: 'En attente', SUSPENDED: 'Suspendu', CLOSED: 'Clôturé',
    completed: 'Complété', processing: 'En cours', failed: 'Échoué', pending: 'En attente',
  };
  return map[status] ?? status;
}

function txRows(client: ControlClientDetail): string[][] {
  return [...client.transactions]
    .sort((a, b) => String(b.created_at ?? '').localeCompare(String(a.created_at ?? '')))
    .map((tx) => [
      fmtDate(tx.created_at),
      String(tx.label ?? tx.type ?? 'Opération'),
      String(tx.description ?? tx.id ?? '—'),
      money(String(tx.amount ?? '0'), String(tx.currency ?? '')),
      statusLabel(String(tx.status ?? '—')),
      String(tx.provider ?? '—'),
    ]);
}

function drawBrandHeader(doc: JsPdfDoc, title: string, fileId: string, subtitle: string): number {
  const w = doc.internal.pageSize.getWidth();
  doc.setFillColor(...INK);
  doc.rect(0, 0, w, 36, 'F');
  doc.setFillColor(...BRASS);
  doc.rect(0, 36, w, 1.2, 'F');

  doc.setTextColor(...PAPER);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(16);
  doc.text('NEXUS', 14, 14);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(...STEEL);
  doc.text('CONTROL · DOCUMENT OFFICIEL', 14, 20);

  doc.setTextColor(...PAPER);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(13);
  doc.text(title, 14, 30);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...BRASS);
  doc.text(fileId, w - 14, 14, { align: 'right' });
  doc.setTextColor(...STEEL);
  doc.text(subtitle, w - 14, 20, { align: 'right' });

  return 44;
}

function drawClientBlock(doc: JsPdfDoc, client: ControlClientDetail, fileId: string, y: number): number {
  const w = doc.internal.pageSize.getWidth();
  doc.setFillColor(...PLATE);
  doc.roundedRect(14, y, w - 28, 34, 2, 2, 'F');
  doc.setDrawColor(...BRASS);
  doc.setLineWidth(0.3);
  doc.roundedRect(14, y, w - 28, 34, 2, 2, 'S');

  doc.setTextColor(...PAPER);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.text(client.full_name, 18, y + 10);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(9);
  doc.setTextColor(...STEEL);
  doc.text(`${client.email}${client.phone ? ` · ${client.phone}` : ''}`, 18, y + 17);
  doc.text(
    `${fileId} · ${statusLabel(client.status)} · KYC ${client.kyc_level} · ${client.account_type === 'business' ? 'Entreprise' : 'Personnel'}`,
    18, y + 24,
  );
  doc.text(`Pays : ${countryLabel(client.country_of_residence)} · Membre depuis ${fmtDate(client.created_at).slice(0, 10)}`, 18, y + 30);

  return y + 42;
}

function drawBalances(doc: JsPdfDoc, client: ControlClientDetail, y: number): number {
  const cols = (['EUR', 'USD', 'XAF'] as const).map((cur) => ({
    cur,
    val: money(client.balances[cur], cur),
  }));
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(9);
  doc.setTextColor(...BRASS);
  doc.text('SOLDES AU COMPTANT', 14, y);

  let x = 14;
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  cols.forEach(({ cur, val }) => {
    doc.setTextColor(...STEEL);
    doc.text(cur, x, y + 8);
    doc.setTextColor(...MINT);
    doc.setFont('helvetica', 'bold');
    doc.text(val, x, y + 14);
    doc.setFont('helvetica', 'normal');
    x += 58;
  });

  return y + 22;
}

function drawFooter(doc: JsPdfDoc, pageNumber: number): void {
  const w = doc.internal.pageSize.getWidth();
  const h = doc.internal.pageSize.getHeight();
  const total = doc.getNumberOfPages();

  doc.setDrawColor(...BRASS);
  doc.setLineWidth(0.2);
  doc.line(14, h - 16, w - 14, h - 16);

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(7);
  doc.setTextColor(...STEEL);
  doc.text(
    'Document confidentiel — usage interne Nexus Technologies. Ne pas diffuser sans autorisation.',
    14, h - 10,
  );
  doc.text(`Page ${pageNumber} / ${total}`, w - 14, h - 10, { align: 'right' });
  doc.text(`Généré le ${fmtDate(new Date().toISOString())}`, w - 14, h - 6, { align: 'right' });
}

function tableTheme() {
  return {
    theme: 'grid' as const,
    styles: {
      font: 'helvetica',
      fontSize: 8,
      cellPadding: 3,
      lineColor: [40, 50, 65] as [number, number, number],
      lineWidth: 0.1,
      textColor: [30, 35, 45] as [number, number, number],
    },
    headStyles: {
      fillColor: [...INK] as [number, number, number],
      textColor: [...BRASS] as [number, number, number],
      fontStyle: 'bold' as const,
      halign: 'left' as const,
    },
    alternateRowStyles: { fillColor: [248, 246, 241] as [number, number, number] },
    margin: { left: 14, right: 14 },
  };
}

async function loadPdf() {
  const [{ jsPDF }, autoTableMod] = await Promise.all([
    import('jspdf'),
    import('jspdf-autotable'),
  ]);
  return { jsPDF, autoTable: autoTableMod.default };
}

function addTransactionsTable(
  doc: JsPdfDoc,
  autoTable: typeof import('jspdf-autotable').default,
  client: ControlClientDetail,
  startY: number,
): number {
  const rows = txRows(client);
  autoTable(doc, {
    ...tableTheme(),
    startY,
    head: [['Date', 'Opération', 'Référence', 'Montant', 'Statut', 'Provider']],
    body: rows.length > 0 ? rows : [['—', 'Aucune opération enregistrée', '—', '—', '—', '—']],
    columnStyles: {
      0: { cellWidth: 28 },
      3: { halign: 'right' as const },
      4: { cellWidth: 22 },
    },
    didDrawPage: (data) => drawFooter(doc, data.pageNumber),
  });
  return (doc as JsPdfDoc & { lastAutoTable?: { finalY: number } }).lastAutoTable?.finalY ?? startY + 20;
}

/** Relevé PDF de toutes les transactions du client. */
export async function downloadTransactionsStatementPdf(client: ControlClientDetail, fileId: string): Promise<void> {
  const { jsPDF, autoTable } = await loadPdf();
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

  let y = drawBrandHeader(doc, 'Relevé de transactions', fileId, 'Historique complet des opérations');
  y = drawClientBlock(doc, client, fileId, y);
  y = drawBalances(doc, client, y + 4);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text(`Journal des opérations (${client.transactions.length})`, 14, y + 6);
  addTransactionsTable(doc, autoTable, client, y + 10);

  doc.save(`nexus-releve-${fileId}.pdf`);
}

/** Ouvre la boîte d'impression du relevé transactions. */
export async function printTransactionsStatement(client: ControlClientDetail, fileId: string): Promise<void> {
  const { jsPDF, autoTable } = await loadPdf();
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

  let y = drawBrandHeader(doc, 'Relevé de transactions', fileId, 'Historique complet des opérations');
  y = drawClientBlock(doc, client, fileId, y);
  y = drawBalances(doc, client, y + 4);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text(`Journal des opérations (${client.transactions.length})`, 14, y + 6);
  addTransactionsTable(doc, autoTable, client, y + 10);

  doc.autoPrint();
  window.open(doc.output('bloburl'), '_blank');
}

/** Dossier client complet en PDF (identité, soldes, moyens, journal). */
export async function downloadClientDossierPdf(client: ControlClientDetail, fileId: string): Promise<void> {
  const { jsPDF, autoTable } = await loadPdf();
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

  let y = drawBrandHeader(doc, 'Dossier client', fileId, 'Registre Super Admin');
  y = drawClientBlock(doc, client, fileId, y);

  // Identité
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text('Identité & profil', 14, y + 8);

  const isBusiness = client.account_type === 'business';
  const identityRows: string[][] = [
    ['Type de compte', isBusiness ? 'Entreprise' : 'Personnel'],
    ['Pays de résidence', countryLabel(client.country_of_residence)],
    ['Téléphone', client.phone ?? '—'],
    ['Adresse', client.address ?? '—'],
    ['Ville', client.city ?? '—'],
    ['Code postal', client.postal_code ?? '—'],
  ];
  if (isBusiness) {
    identityRows.push(
      ['Raison sociale', client.company_name ?? client.full_name],
      ['Forme juridique', client.legal_form ?? '—'],
      ['Immatriculation', client.company_registration_number ?? '—'],
      ['Secteur', client.industry ?? '—'],
      ['Taille', client.company_size ?? '—'],
      ['Site web', client.website ?? '—'],
    );
  } else {
    identityRows.push(
      ['Date de naissance', client.birth_date ?? '—'],
      ['Genre', client.gender ?? '—'],
    );
  }

  autoTable(doc, {
    ...tableTheme(),
    startY: y + 12,
    head: [['Champ', 'Valeur']],
    body: identityRows,
    columnStyles: { 0: { cellWidth: 52, fontStyle: 'bold' } },
    didDrawPage: (data) => drawFooter(doc, data.pageNumber),
  });

  y = (doc as JsPdfDoc & { lastAutoTable?: { finalY: number } }).lastAutoTable?.finalY ?? y + 40;
  y += 8;
  y = drawBalances(doc, client, y);

  // Moyens de paiement
  if (client.accounts.length > 0) {
    if (y > 240) { doc.addPage(); y = 20; }
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor(...INK);
    doc.text('Moyens de paiement', 14, y + 6);

    autoTable(doc, {
      ...tableTheme(),
      startY: y + 10,
      head: [['Libellé', 'Type', 'Titulaire', 'Pays', 'Statut']],
      body: client.accounts.map((a) => [
        a.label,
        `${a.kind}${a.is_default ? ' · défaut' : ''}`,
        a.holder_name ?? '—',
        countryLabel(a.country),
        a.status,
      ]),
      didDrawPage: (data) => drawFooter(doc, data.pageNumber),
    });
    y = (doc as JsPdfDoc & { lastAutoTable?: { finalY: number } }).lastAutoTable?.finalY ?? y + 30;
  }

  // Journal
  if (y > 220) { doc.addPage(); y = 20; }
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...INK);
  doc.text(`Journal des opérations (${client.transactions.length})`, 14, y + 8);
  addTransactionsTable(doc, autoTable, client, y + 12);

  doc.save(`nexus-dossier-${fileId}.pdf`);
}
