import { useEffect, useRef, useState, type ReactNode } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiSupportConversations, apiSupportMessages, apiSupportSendMessage, apiSupportUnread, apiSupportBot,
  apiSupportUpload, apiSupportCreateConversation, apiSupportSetStatus,
  SUPPORT_ATTACHMENT_ACCEPT, isSupportImageUrl, validateSupportAttachment,
  type SupportConversation, type SupportMessage,
} from '../../api/client';
import { useI18n } from '../../context/I18nContext';
import { localeFor, useDashT } from '../../data/dashboard-i18n';
import { EASE } from '../anim/Premium';

interface BotMsg { sender: 'customer' | 'bot'; body: string; quickReplies?: string[]; }

type AttachErrorKey =
  | 'chat.attach.too_large'
  | 'chat.attach.unsupported'
  | 'chat.attach.upload_failed';

/** Affiche le gras **…** et les retours à la ligne (réponses bot), sans HTML brut. */
function ChatRichBody({ text }: { text: string }) {
  if (!text.trim()) return null;
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

function ChatAttachment({ url, name, label }: { url: string; name: string | null; label: string }) {
  const title = name?.trim() || label;
  if (isSupportImageUrl(url) || isSupportImageUrl(name ?? '')) {
    return (
      <a href={url} target="_blank" rel="noreferrer" className="chat-attach-img-link" title={title}>
        <img src={url} alt={title} className="chat-attach-img" loading="lazy" />
      </a>
    );
  }
  return (
    <a href={url} target="_blank" rel="noreferrer" className="chat-attach" download={name ?? undefined}>
      📎 {title}
    </a>
  );
}

/** Widget de chat support (client). Flux : d'abord un bot pré-ticket, qui
 * escalade vers un ticket + agent humain si le bot ne sait pas répondre ou si
 * l'utilisateur demande un agent. */
export default function SupportChatWidget() {
  const t = useDashT();
  const { lang } = useI18n();
  const locale = localeFor(lang);
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'bot' | 'conv'>('bot');
  const [activeConv, setActiveConv] = useState<SupportConversation | null>(null);
  const [convMessages, setConvMessages] = useState<SupportMessage[]>([]);
  const [botHistory, setBotHistory] = useState<BotMsg[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [ending, setEnding] = useState(false);
  const [unread, setUnread] = useState(0);
  const [file, setFile] = useState<File | null>(null);
  const [filePreview, setFilePreview] = useState<string | null>(null);
  /** Clé i18n — re-traduite à chaque changement de langue. */
  const [attachError, setAttachError] = useState<AttachErrorKey | null>(null);
  const lastMsgId = useRef(0);
  const scrollRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);

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
  }, [convMessages, botHistory, filePreview, attachError]);

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

  useEffect(() => {
    if (!file || !file.type.startsWith('image/')) {
      setFilePreview(null);
      return;
    }
    const url = URL.createObjectURL(file);
    setFilePreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  function clearFile() {
    setFile(null);
    setFilePreview(null);
    if (fileRef.current) fileRef.current.value = '';
  }

  function onPickFile(f: File | null) {
    setAttachError(null);
    if (!f) {
      clearFile();
      return;
    }
    const check = validateSupportAttachment(f);
    if (check === 'too_large') {
      clearFile();
      setAttachError('chat.attach.too_large');
      return;
    }
    if (check === 'unsupported') {
      clearFile();
      setAttachError('chat.attach.unsupported');
      return;
    }
    setFile(f);
  }

  function historyPayload(msgs: BotMsg[]) {
    return msgs.map((m) => ({ sender: m.sender, body: m.body }));
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
    const res = await apiSupportBot(q, prior, lang);
    setSending(false);
    if (!res.success || !res.data) return;
    const r = res.data;
    const botMsg = r.reply ?? (r.escalate ? t('chat.escalate') : '');
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
    const res = await apiSupportBot(text, prior, lang);
    setSending(false);
    if (!res.success || !res.data) return;
    const r = res.data;
    const botMsg = r.reply ?? (r.escalate ? t('chat.escalate') : '');
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
    clearFile();
    setAttachError(null);
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

  function resetToFreshBot() {
    setMode('bot');
    setActiveConv(null);
    setConvMessages([]);
    setBotHistory([]);
    setDraft('');
    clearFile();
    setAttachError(null);
    lastMsgId.current = 0;
  }

  /** Ferme le ticket côté serveur (si ouvert), puis repart sur un bot propre. */
  async function endConversation() {
    if (ending) return;
    setEnding(true);
    try {
      if (mode === 'conv' && activeConv) {
        const res = await apiSupportSetStatus(activeConv.id, 'closed');
        if (!res.success) return;
      }
      resetToFreshBot();
    } finally {
      setEnding(false);
    }
  }

  async function sendConv() {
    const text = draft.trim();
    if ((!text && !file) || !activeConv || sending) return;
    setAttachError(null);
    setSending(true);

    let attName: string | undefined;
    let attUrl: string | undefined;
    const pendingFile = file;

    if (pendingFile) {
      const up = await apiSupportUpload(pendingFile, { conversation_id: activeConv.id });
      if (!up.success || !up.data?.url) {
        setAttachError('chat.attach.upload_failed');
        setSending(false);
        return;
      }
      attName = up.data.name;
      attUrl = up.data.url;
    }

    const body = text;
    setDraft('');
    clearFile();

    const optimistic: SupportMessage = {
      id: Date.now(),
      conversation_id: activeConv.id,
      customer_id: null,
      agent_id: null,
      is_bot: false,
      is_internal: false,
      body: body || (attName ? `📎 ${attName}` : ''),
      attachment_name: attName ?? null,
      attachment_url: attUrl ?? null,
      read_at: null,
      created_at: new Date().toISOString(),
      customer_name: t('chat.you'),
      agent_name: null,
    };
    setConvMessages((prev) => [...prev, optimistic]);

    const res = await apiSupportSendMessage(activeConv.id, body, {
      attachment_name: attName,
      attachment_url: attUrl,
    });
    if (!res.success) {
      setAttachError('chat.attach.upload_failed');
      setConvMessages((prev) => prev.filter((m) => m.id !== optimistic.id));
    }
    setSending(false);
  }

  const title =
    mode === 'bot'
      ? t('chat.title.bot')
      : agentConnected && agentLabel
        ? `${agentLabel} · ${t('chat.title.agent')}`
        : t('chat.connecting');

  return (
    <>
      <motion.button
        className="chat-fab"
        onClick={() => setOpen((o) => !o)}
        whileHover={{ scale: 1.08 }} whileTap={{ scale: 0.92 }}
        transition={{ type: 'spring', stiffness: 300, damping: 17 }}
        aria-label={t('chat.fab.aria')}
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
          >
            <div className="chat-panel-head">
              {mode === 'conv' && activeConv && (
                <button
                  className="chat-back"
                  onClick={() => { setMode('bot'); setActiveConv(null); setConvMessages([]); setBotHistory([]); clearFile(); setAttachError(null); }}
                  aria-label={t('chat.back')}
                >
                  ←
                </button>
              )}
              <div className="chat-title">
                <span className={`chat-live${mode === 'conv' && !agentConnected ? ' pending' : ''}`} />
                {title}
              </div>
              {(mode === 'conv' || botHistory.length > 0) && (
                <button
                  type="button"
                  className="chat-end"
                  onClick={() => void endConversation()}
                  disabled={ending}
                  aria-label={t('chat.end.aria')}
                  title={t('chat.end.aria')}
                >
                  {t('chat.end')}
                </button>
              )}
              <button className="chat-close" onClick={() => setOpen(false)} aria-label={t('chat.close')}>✕</button>
            </div>

            {mode === 'conv' && !agentConnected && (
              <div className="chat-connecting" role="status">
                {t('chat.connecting_banner')}
              </div>
            )}
            {mode === 'conv' && agentConnected && agentLabel && (
              <div className="chat-connected" role="status">
                {t('chat.agent_connected', { name: agentLabel })}
              </div>
            )}

            <div className="chat-panel-body" ref={scrollRef}>
              {mode === 'bot' ? (
                <div className="chat-thread">
                  {[{ sender: 'bot' as const, body: t('chat.welcome'), quickReplies: [t('chat.quick.send'), t('chat.quick.balance'), t('chat.quick.kyc'), t('chat.quick.fees'), t('chat.quick.agent')] }, ...botHistory].map((m, i) => (
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
                      <ChatRichBody text={m.body} />
                      {m.attachment_url && (
                        <div className="chat-attach-wrap">
                          <ChatAttachment
                            url={m.attachment_url}
                            name={m.attachment_name}
                            label={t('chat.attach.file')}
                          />
                        </div>
                      )}
                      <div className="chat-bubble-time">{new Date(m.created_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}</div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {(file || attachError) && mode === 'conv' && (
              <div className="chat-attach-bar">
                {attachError && <div className="chat-attach-error" role="alert">{t(attachError)}</div>}
                {file && (
                  <div className="chat-attach-preview">
                    {filePreview ? (
                      <img src={filePreview} alt="" className="chat-attach-preview-img" />
                    ) : (
                      <span className="chat-attach-preview-icon" aria-hidden>📎</span>
                    )}
                    <span className="chat-attach-preview-name" title={file.name}>{file.name}</span>
                    <button
                      type="button"
                      className="chat-attach-remove"
                      onClick={() => { clearFile(); setAttachError(null); }}
                      aria-label={t('chat.attach.remove')}
                      title={t('chat.attach.remove')}
                    >
                      ✕
                    </button>
                  </div>
                )}
              </div>
            )}

            <div className="chat-panel-input">
              {mode === 'conv' && (
                <button
                  type="button"
                  className="chat-file-btn"
                  onClick={() => fileRef.current?.click()}
                  title={t('chat.attach')}
                  aria-label={t('chat.attach')}
                  disabled={sending}
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </button>
              )}
              <input
                ref={fileRef}
                type="file"
                hidden
                accept={SUPPORT_ATTACHMENT_ACCEPT}
                onChange={(e) => onPickFile(e.target.files?.[0] ?? null)}
              />
              <input
                placeholder={
                  mode === 'bot'
                    ? t('chat.placeholder.bot')
                    : agentConnected
                      ? t('chat.placeholder.conv')
                      : t('chat.placeholder.waiting')
                }
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) void (mode === 'bot' ? sendBot() : sendConv()); }}
              />
              <button
                type="button"
                onClick={() => void (mode === 'bot' ? sendBot() : sendConv())}
                disabled={sending || (!draft.trim() && !file)}
                aria-label={t('chat.send')}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
