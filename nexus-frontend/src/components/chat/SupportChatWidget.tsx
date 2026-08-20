import { useEffect, useRef, useState, type ClipboardEvent, type ReactNode } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiSupportConversations, apiSupportMessages, apiSupportSendMessage, apiSupportUnread, apiSupportBot,
  apiSupportUpload, apiSupportCreateConversation,
  type SupportConversation, type SupportMessage,
} from '../../api/client';
import { EASE } from '../anim/Premium';

interface BotMsg { sender: 'customer' | 'bot'; body: string; quickReplies?: string[]; }

const CONNECTING_REPLY =
  'Je vous mets en relation avec un conseiller Nexus. Un membre de l’équipe support prendra en charge votre demande sous peu.';

const MAX_PENDING = 8;

/** Affiche le gras **…** et les retours à la ligne (réponses bot), sans HTML brut. */
function ChatRichBody({ text }: { text: string }) {
  const lines = text.split('\n');
  return (
    <div className="chat-bubble-body">
      {lines.map((line, i) => (
        <p key={i} className={`chat-bubble-line${line.trim() === '' ? ' is-gap' : ''}`}>
          {renderInlineMarkdown(line)}
        </p>
      ))}
    </div>
  );
}

function renderInlineMarkdown(line: string): ReactNode[] {
  const parts = line.split(/(\*\*[^*]+\*\*)/g);
  return parts.map((part, i) => {
    if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
      return <strong key={i}>{part.slice(2, -2)}</strong>;
    }
    return part === '' ? null : <span key={i}>{part}</span>;
  });
}

function bubbleClass(m: SupportMessage): string {
  if (m.is_bot) return 'bot';
  if (m.agent_id) return 'agent';
  return 'mine';
}

function isImageUrl(url: string | null | undefined, name?: string | null): boolean {
  const probe = `${url ?? ''} ${name ?? ''}`.toLowerCase();
  return /\.(png|jpe?g|gif|webp)(\?|$)/i.test(probe) || probe.includes('capture-');
}

function AttachmentBlock({ url, name }: { url: string; name: string | null }) {
  if (isImageUrl(url, name)) {
    return (
      <a href={url} target="_blank" rel="noreferrer" className="chat-attach-preview">
        <img src={url} alt={name ?? 'Capture'} loading="lazy" />
        <span>{name ?? 'Image'}</span>
      </a>
    );
  }
  return (
    <a href={url} target="_blank" rel="noreferrer" className="chat-attach">
      📎 {name ?? 'pièce jointe'}
    </a>
  );
}

/** Widget de chat support (client). Flux : d'abord un bot pré-ticket, qui
 * escalade vers un ticket + agent humain si le bot ne sait pas répondre ou si
 * l'utilisateur demande un agent. */
export default function SupportChatWidget() {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'bot' | 'conv'>('bot');
  const [activeConv, setActiveConv] = useState<SupportConversation | null>(null);
  const [convMessages, setConvMessages] = useState<SupportMessage[]>([]);
  const [botHistory, setBotHistory] = useState<BotMsg[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [unread, setUnread] = useState(0);
  const [files, setFiles] = useState<File[]>([]);
  const [previews, setPreviews] = useState<Record<string, string>>({});
  const [attachHint, setAttachHint] = useState<string | null>(null);
  const lastMsgId = useRef(0);
  const scrollRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const imageRef = useRef<HTMLInputElement>(null);

  const agentConnected = Boolean(activeConv?.assigned_to || activeConv?.assigned_name);
  const agentLabel = activeConv?.assigned_name?.trim() || null;

  useEffect(() => {
    let alive = true;
    const tick = async () => {
      const res = await apiSupportUnread();
      if (alive && res.success && res.data) setUnread(res.data.total);
    };
    tick();
    const iv = setInterval(tick, 4000);
    return () => { alive = false; clearInterval(iv); };
  }, [open]);

  // Polling messages + meta conversation (prise en charge agent).
  useEffect(() => {
    if (!activeConv || !open || mode !== 'conv') return;
    let alive = true;
    const tick = async () => {
      const res = await apiSupportMessages(activeConv.id, lastMsgId.current);
      if (!alive || !res.success || !res.data) return;
      if (res.data.conversation) {
        setActiveConv((prev) => (prev ? { ...prev, ...res.data!.conversation! } : res.data!.conversation!));
      }
      const newItems = res.data.items.filter((m) => m.id > lastMsgId.current && !m.is_internal);
      if (newItems.length) {
        setConvMessages((prev) => [...prev, ...newItems]);
        lastMsgId.current = Math.max(lastMsgId.current, ...newItems.map((m) => m.id));
      }
    };
    tick();
    const iv = setInterval(tick, 2500);
    return () => { alive = false; clearInterval(iv); };
  }, [activeConv?.id, open, mode]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [convMessages, botHistory]);

  // À l'ouverture : reprendre un ticket open/waiting, sinon mode bot.
  useEffect(() => {
    if (!open) return;
    let alive = true;
    (async () => {
      const list = await apiSupportConversations();
      if (!alive) return;
      const items = list.success && list.data ? list.data.items : [];
      const resume = items.find((c) => c.status === 'waiting' || c.status === 'open');
      if (resume) {
        await openConv(resume.id, resume);
        return;
      }
      setBotHistory([]);
      setConvMessages([]);
      setActiveConv(null);
      setMode('bot');
      lastMsgId.current = 0;
    })();
    return () => { alive = false; };
  }, [open]);

  function historyPayload(msgs: BotMsg[]) {
    return msgs.map((m) => ({ sender: m.sender, body: m.body }));
  }

  function fileKey(f: File): string {
    return `${f.name}|${f.size}|${f.lastModified}`;
  }

  function addFiles(incoming: FileList | File[] | null) {
    if (!incoming) return;
    const list = Array.from(incoming);
    if (list.length === 0) return;
    setFiles((prev) => {
      const next = [...prev];
      const newPreviews: Record<string, string> = {};
      for (const f of list) {
        if (next.length >= MAX_PENDING) break;
        const key = fileKey(f);
        const dup = next.some((x) => fileKey(x) === key);
        if (dup) continue;
        next.push(f);
        if (f.type.startsWith('image/')) {
          newPreviews[key] = URL.createObjectURL(f);
        }
      }
      if (Object.keys(newPreviews).length) {
        setPreviews((p) => ({ ...p, ...newPreviews }));
      }
      return next;
    });
    setAttachHint(null);
  }

  function removeFile(index: number) {
    setFiles((prev) => {
      const target = prev[index];
      if (target) {
        const key = fileKey(target);
        setPreviews((p) => {
          const url = p[key];
          if (url) URL.revokeObjectURL(url);
          const { [key]: _, ...rest } = p;
          return rest;
        });
      }
      return prev.filter((_, i) => i !== index);
    });
  }

  function clearFiles() {
    setPreviews((p) => {
      Object.values(p).forEach((url) => URL.revokeObjectURL(url));
      return {};
    });
    setFiles([]);
  }

  function onPaste(e: ClipboardEvent) {
    if (mode !== 'conv') return;
    const items = e.clipboardData?.items;
    if (!items) return;
    const pasted: File[] = [];
    for (const item of Array.from(items)) {
      if (item.kind === 'file' && item.type.startsWith('image/')) {
        const blob = item.getAsFile();
        if (!blob) continue;
        const ext = blob.type === 'image/jpeg' ? 'jpg' : blob.type === 'image/webp' ? 'webp' : 'png';
        const named = new File(
          [blob],
          `capture-${new Date().toISOString().replace(/[:.]/g, '-')}.${ext}`,
          { type: blob.type || 'image/png' },
        );
        pasted.push(named);
      }
    }
    if (pasted.length) {
      e.preventDefault();
      addFiles(pasted);
      setAttachHint('Capture collée — prête à envoyer.');
    }
  }

  async function escalateToAgent(subject: string, category: string, history: BotMsg[]) {
    const create = await apiSupportCreateConversation(subject, category, {
      history: historyPayload(history),
    });
    if (create.success && create.data) {
      await openConv(create.data.conversation.id, create.data.conversation);
    }
  }

  async function sendQuick(q: string) {
    if (sending) return;
    setDraft(q);
    setSending(true);
    const prior = historyPayload(botHistory);
    const history = [...botHistory, { sender: 'customer' as const, body: q }];
    setBotHistory(history);
    const res = await apiSupportBot(q, prior);
    setSending(false);
    if (!res.success || !res.data) return;
    const r = res.data;
    const botMsg = r.reply ?? (r.escalate ? CONNECTING_REPLY : '');
    if (botMsg) setBotHistory((h) => [...h, { sender: 'bot', body: botMsg, quickReplies: r.quick_replies ?? [] }]);
    if (r.escalate) {
      await new Promise((resolve) => setTimeout(resolve, 500));
      await escalateToAgent(r.subject || q, r.category, history);
    }
  }

  async function sendBot() {
    const text = draft.trim();
    if (!text || sending) return;
    setDraft('');
    setSending(true);
    const prior = historyPayload(botHistory);
    const history = [...botHistory, { sender: 'customer' as const, body: text }];
    setBotHistory(history);
    const res = await apiSupportBot(text, prior);
    setSending(false);
    if (!res.success || !res.data) return;
    const r = res.data;
    const botMsg = r.reply ?? (r.escalate ? CONNECTING_REPLY : '');
    if (botMsg) setBotHistory((h) => [...h, { sender: 'bot', body: botMsg, quickReplies: r.quick_replies ?? [] }]);
    if (r.escalate) {
      await new Promise((resolve) => setTimeout(resolve, 500));
      await escalateToAgent(r.subject || text, r.category, history);
    }
  }

  async function openConv(id: number, conv?: SupportConversation) {
    setMode('conv');
    setConvMessages([]);
    lastMsgId.current = 0;
    if (conv) setActiveConv(conv);
    const res = await apiSupportMessages(id);
    if (res.success && res.data) {
      setConvMessages(res.data.items.filter((m) => !m.is_internal));
      lastMsgId.current = res.data.items.reduce((a, m) => Math.max(a, m.id), 0);
      if (res.data.conversation) {
        setActiveConv((prev) => ({ ...(prev ?? conv ?? { id } as SupportConversation), ...res.data!.conversation! }));
      } else if (!conv) {
        const list = await apiSupportConversations();
        setActiveConv(list.success ? list.data!.items.find((c) => c.id === id) ?? null : null);
      }
    } else if (!conv) {
      const list = await apiSupportConversations();
      setActiveConv(list.success ? list.data!.items.find((c) => c.id === id) ?? null : null);
    }
  }

  function pushOptimistic(convId: number, body: string, attName?: string, attUrl?: string) {
    setConvMessages((prev) => [...prev, {
      id: Date.now() + Math.floor(Math.random() * 1000),
      conversation_id: convId,
      customer_id: null,
      agent_id: null,
      is_bot: false,
      is_internal: false,
      body,
      attachment_name: attName ?? null,
      attachment_url: attUrl ?? null,
      read_at: null,
      created_at: new Date().toISOString(),
      customer_name: 'Vous',
      agent_name: null,
    }]);
  }

  async function sendConv() {
    const text = draft.trim();
    if ((!text && files.length === 0) || !activeConv || sending) return;
    setDraft('');
    setSending(true);
    setAttachHint(null);

    const pending = [...files];
    clearFiles();

    const uploads: Array<{ name: string; url: string }> = [];
    for (const f of pending) {
      const up = await apiSupportUpload(f);
      if (up.success && up.data) {
        uploads.push(up.data);
      } else {
        setAttachHint(up.error ?? `Échec d'envoi : ${f.name}`);
      }
    }

    if (text === '' && uploads.length === 0) {
      setSending(false);
      return;
    }

    // Premier message : texte (+ 1ʳᵉ PJ si présente). Les PJ restantes partent
    // chacune dans un message dédié pour respecter 1 attachment / message.
    const first = uploads[0];
    const firstBody = text || (first ? `📎 ${first.name}` : '');
    pushOptimistic(activeConv.id, firstBody, first?.name, first?.url);
    await apiSupportSendMessage(activeConv.id, text || '', {
      attachment_name: first?.name,
      attachment_url: first?.url,
    });

    for (let i = 1; i < uploads.length; i++) {
      const u = uploads[i];
      pushOptimistic(activeConv.id, `📎 ${u.name}`, u.name, u.url);
      await apiSupportSendMessage(activeConv.id, '', {
        attachment_name: u.name,
        attachment_url: u.url,
      });
    }

    setSending(false);
  }

  const title =
    mode === 'bot'
      ? 'Assistant Nexus'
      : agentConnected && agentLabel
        ? `${agentLabel} · Support`
        : 'Mise en relation…';

  return (
    <>
      <motion.button
        className="chat-fab"
        onClick={() => setOpen((o) => !o)}
        whileHover={{ scale: 1.08 }} whileTap={{ scale: 0.92 }}
        transition={{ type: 'spring', stiffness: 300, damping: 17 }}
        aria-label="Assistance"
      >
        {open ? (
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
        ) : (
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        )}
        {!open && unread > 0 && <span className="chat-fab-badge">{unread}</span>}
      </motion.button>

      <AnimatePresence>
        {open && (
          <motion.div
            className="chat-panel"
            initial={{ opacity: 0, y: 24, scale: 0.96 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 24, scale: 0.96 }}
            transition={{ duration: 0.3, ease: EASE }}
            onPaste={onPaste}
          >
            <div className="chat-panel-head">
              {mode === 'conv' && activeConv && (
                <button
                  className="chat-back"
                  onClick={() => { setMode('bot'); setActiveConv(null); setConvMessages([]); setBotHistory([]); clearFiles(); }}
                >
                  ←
                </button>
              )}
              <div className="chat-title">
                <span className={`chat-live${mode === 'conv' && !agentConnected ? ' pending' : ''}`} />
                {title}
              </div>
              <button className="chat-close" onClick={() => setOpen(false)} aria-label="Fermer">✕</button>
            </div>

            {mode === 'conv' && !agentConnected && (
              <div className="chat-connecting" role="status">
                Mise en relation avec un conseiller… Merci de patienter.
              </div>
            )}
            {mode === 'conv' && agentConnected && agentLabel && (
              <div className="chat-connected" role="status">
                {agentLabel} du support client est connecté(e)
              </div>
            )}

            <div className="chat-panel-body" ref={scrollRef}>
              {mode === 'bot' ? (
                <div className="chat-thread">
                  {[{ sender: 'bot' as const, body: 'Bonjour. Je suis l’assistant Nexus. Décrivez votre besoin, ou demandez à parler à un conseiller.', quickReplies: ['Je veux envoyer de l\'argent', 'Question sur mon solde', 'Vérification KYC', 'Mes frais', 'Parler à un agent'] }, ...botHistory].map((m, i) => (
                    <div key={i} className={`chat-bubble ${m.sender === 'customer' ? 'mine' : 'bot'}`}>
                      <ChatRichBody text={m.body} />
                      {m.sender === 'bot' && m.quickReplies && m.quickReplies.length > 0 && (
                        <div className="chat-quick">
                          {m.quickReplies.map((q, j) => (
                            <button key={j} className="chat-quick-btn" onClick={() => sendQuick(q)}>{q}</button>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="chat-thread">
                  {convMessages.map((m) => (
                    <div key={m.id} className={`chat-bubble ${bubbleClass(m)}`}>
                      {!m.is_bot && m.agent_id && m.agent_name && (
                        <div className="chat-bubble-author">{m.agent_name}</div>
                      )}
                      {m.body && !(m.attachment_url && m.body === `📎 ${m.attachment_name}`) && (
                        <ChatRichBody text={m.body} />
                      )}
                      {m.attachment_url && (
                        <div style={{ marginTop: m.body ? 6 : 0 }}>
                          <AttachmentBlock url={m.attachment_url} name={m.attachment_name} />
                        </div>
                      )}
                      <div className="chat-bubble-time">{new Date(m.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {mode === 'conv' && files.length > 0 && (
              <div className="chat-pending-files" aria-label="Fichiers à envoyer">
                {files.map((f, i) => {
                  const preview = previews[fileKey(f)];
                  return (
                    <div key={fileKey(f)} className="chat-pending-file">
                      {preview ? (
                        <img src={preview} alt="" />
                      ) : (
                        <span className="chat-pending-icon">📄</span>
                      )}
                      <span className="chat-pending-name" title={f.name}>{f.name}</span>
                      <button type="button" onClick={() => removeFile(i)} aria-label={`Retirer ${f.name}`}>✕</button>
                    </div>
                  );
                })}
              </div>
            )}

            {mode === 'conv' && attachHint && (
              <div className="chat-attach-hint">{attachHint}</div>
            )}

            <div className="chat-panel-input">
              {mode === 'conv' && (
                <>
                  <button
                    className="chat-file-btn"
                    onClick={() => fileRef.current?.click()}
                    title="Joindre un fichier (PDF, image, texte)"
                    aria-label="Joindre un fichier"
                    type="button"
                  >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                  </button>
                  <button
                    className="chat-file-btn"
                    onClick={() => imageRef.current?.click()}
                    title="Ajouter une image ou capture d'écran"
                    aria-label="Ajouter une image ou capture"
                    type="button"
                  >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="M21 15l-5-5L5 19"/></svg>
                  </button>
                  <input
                    ref={fileRef}
                    type="file"
                    hidden
                    multiple
                    accept="image/*,.pdf,.txt,application/pdf,text/plain"
                    onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
                  />
                  <input
                    ref={imageRef}
                    type="file"
                    hidden
                    multiple
                    accept="image/*"
                    onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }}
                  />
                </>
              )}
              <input
                placeholder={
                  mode === 'bot'
                    ? 'Écrivez votre besoin…'
                    : agentConnected
                      ? 'Message, fichier ou Ctrl+V pour une capture…'
                      : 'Fichier / capture OK — un conseiller arrive…'
                }
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onPaste={onPaste}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) void (mode === 'bot' ? sendBot() : sendConv()); }}
              />
              <button onClick={() => void (mode === 'bot' ? sendBot() : sendConv())} disabled={sending || (!draft.trim() && files.length === 0)} aria-label="Envoyer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
