import { useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiSupportConversations, apiSupportMessages, apiSupportSendMessage, apiSupportUnread, apiSupportBot,
  apiSupportUpload, apiSupportCreateConversation,
  type SupportConversation, type SupportMessage,
} from '../../api/client';
import { EASE } from '../anim/Premium';

interface BotMsg { sender: 'customer' | 'bot'; body: string; }

/** Widget de chat support (client). Flux : d'abord un bot pré-ticket, qui
 * escalade vers un ticket + agent humain si le bot ne sait pas répondre ou si
 * l'utilisateur demande un agent. */
export default function SupportChatWidget() {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'bot' | 'conv'>('bot'); // conv = ticket ouvert
  const [activeConv, setActiveConv] = useState<SupportConversation | null>(null);
  const [convMessages, setConvMessages] = useState<SupportMessage[]>([]);
  const [botHistory, setBotHistory] = useState<BotMsg[]>([]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [unread, setUnread] = useState(0);
  const [file, setFile] = useState<File | null>(null);
  const lastMsgId = useRef(0);
  const scrollRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  // Notifications : badge non-lu sur le FAB.
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

  // Polling des messages du ticket ouvert.
  useEffect(() => {
    if (!activeConv || !open) return;
    let alive = true;
    const tick = async () => {
      const res = await apiSupportMessages(activeConv.id, lastMsgId.current);
      if (alive && res.success && res.data) {
        const newItems = res.data.items.filter((m) => m.id > lastMsgId.current && !m.is_internal);
        if (newItems.length) {
          setConvMessages((prev) => [...prev, ...newItems]);
          lastMsgId.current = Math.max(...res.data.items.map((m) => m.id));
        }
      }
    };
    tick();
    const iv = setInterval(tick, 2500);
    return () => { alive = false; clearInterval(iv); };
  }, [activeConv, open]);

  useEffect(() => { scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight }); }, [convMessages, botHistory]);

  // À l'ouverture : réinitialiser en mode bot (sauf si un ticket est déjà en cours).
  useEffect(() => {
    if (open) {
      setBotHistory([]);
      setConvMessages([]);
      setActiveConv(null);
      setMode('bot');
    }
  }, [open]);

  async function sendBot() {
    const text = draft.trim();
    if (!text || sending) return;
    setDraft('');
    setSending(true);
    const history = [...botHistory, { sender: 'customer' as const, body: text }];
    setBotHistory(history);
    const res = await apiSupportBot(text);
    setSending(false);
    if (!res.success || !res.data) return;
    const r = res.data;
    // Affiche la réponse du bot (même si escalate, on montre un message).
    const botMsg = r.reply ?? (r.escalate ? 'Je transmets votre demande à un agent humain. 📨' : '');
    if (botMsg) setBotHistory((h) => [...h, { sender: 'bot', body: botMsg }]);
    // Escalade → création du ticket avec tout l'historique.
    if (r.escalate) {
      await new Promise((resolve) => setTimeout(resolve, 600));
      const create = await apiSupportCreateConversation(r.subject || text, r.category, { history });
      if (create.success && create.data) {
        openConv(create.data.conversation.id, create.data.conversation);
      }
    }
  }

  async function openConv(id: number, conv?: SupportConversation) {
    setMode('conv');
    setConvMessages([]);
    lastMsgId.current = 0;
    const res = await apiSupportMessages(id);
    if (res.success && res.data) {
      setConvMessages(res.data.items.filter((m) => !m.is_internal));
      lastMsgId.current = res.data.items.reduce((a, m) => Math.max(a, m.id), 0);
    }
    if (conv) setActiveConv(conv);
    else {
      const list = await apiSupportConversations();
      setActiveConv(list.success ? list.data!.items.find((c) => c.id === id) ?? null : null);
    }
  }

  async function sendConv() {
    const text = draft.trim();
    if ((!text && !file) || !activeConv || sending) return;
    setDraft('');
    setSending(true);
    let attName: string | undefined;
    let attUrl: string | undefined;
    if (file) {
      const up = await apiSupportUpload(file);
      if (up.success && up.data) { attName = up.data.name; attUrl = up.data.url; }
    }
    setFile(null);
    if (text) {
      setConvMessages((prev) => [...prev, {
        id: Date.now(), conversation_id: activeConv.id, customer_id: null, agent_id: null, is_bot: false, is_internal: false,
        body: text, attachment_name: attName ?? null, attachment_url: attUrl ?? null, read_at: null,
        created_at: new Date().toISOString(), customer_name: 'Vous', agent_name: null,
      }]);
    } else if (attUrl) {
      setConvMessages((prev) => [...prev, {
        id: Date.now(), conversation_id: activeConv.id, customer_id: null, agent_id: null, is_bot: false, is_internal: false,
        body: `📎 ${attName}`, attachment_name: attName ?? null, attachment_url: attUrl ?? null, read_at: null,
        created_at: new Date().toISOString(), customer_name: 'Vous', agent_name: null,
      }]);
    }
    await apiSupportSendMessage(activeConv.id, text || '', { attachment_name: attName, attachment_url: attUrl });
    setSending(false);
  }

  return (
    <>
      {/* Bouton flottant + badge non-lu */}
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

      {/* Panneau */}
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
                <button className="chat-back" onClick={() => { setMode('bot'); setActiveConv(null); setConvMessages([]); }}>←</button>
              )}
              <div className="chat-title">
                <span className="chat-live" />
                {mode === 'bot' ? 'Assistant Nexus' : 'Ticket · assistance humaine'}
              </div>
              <button className="chat-close" onClick={() => setOpen(false)} aria-label="Fermer">✕</button>
            </div>

            <div className="chat-panel-body" ref={scrollRef}>
              {mode === 'bot' ? (
                <div className="chat-thread">
                  {[{ sender: 'bot', body: 'Bonjour 👋 Je suis l\'assistant Nexus. Décrivez votre besoin, ou écrivez « agent » pour être mis en relation avec un conseiller.' }, ...botHistory].map((m, i) => (
                    <div key={i} className={`chat-bubble ${m.sender === 'customer' ? 'mine' : 'bot'}`}>
                      <div className="chat-bubble-body">{m.body}</div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="chat-thread">
                  {convMessages.map((m) => (
                    <div key={m.id} className={`chat-bubble ${m.agent_id ? 'agent' : m.is_bot ? 'bot' : 'mine'}`}>
                      <div className="chat-bubble-body">
                        {m.body}
                        {m.attachment_url && (
                          <div style={{ marginTop: 6 }}>
                            <a href={m.attachment_url} target="_blank" rel="noreferrer" className="chat-attach">📎 {m.attachment_name ?? 'pièce jointe'}</a>
                          </div>
                        )}
                      </div>
                      <div className="chat-bubble-time">{new Date(m.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="chat-panel-input">
              {mode === 'conv' && (
                <button className="chat-file-btn" onClick={() => fileRef.current?.click()} title="Joindre un fichier" aria-label="Joindre un fichier">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </button>
              )}
              {file && <span className="chat-file-tag">{file.name} ✕</span>}
              <input ref={fileRef} type="file" hidden accept="image/*,.pdf,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              <input
                placeholder={mode === 'bot' ? 'Écrivez votre besoin…' : 'Écrivez votre message…'}
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) void (mode === 'bot' ? sendBot() : sendConv()); }}
              />
              <button onClick={() => void (mode === 'bot' ? sendBot() : sendConv())} disabled={sending || (!draft.trim() && !file)} aria-label="Envoyer">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
