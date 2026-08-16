import { useCallback, useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  apiSupportConversations, apiSupportCreateConversation, apiSupportMessages, apiSupportSendMessage,
  type SupportConversation, type SupportMessage,
} from '../../api/client';
import { EASE } from '../anim/Premium';

/** Widget de chat support flottant (côté client). Style Revolut/Intercom. */
export default function SupportChatWidget() {
  const [open, setOpen] = useState(false);
  const [conversations, setConversations] = useState<SupportConversation[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [messages, setMessages] = useState<SupportMessage[]>([]);
  const [composing, setComposing] = useState(false); // en train de créer un ticket
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('other');
  const [draft, setDraft] = useState('');
  const [loading, setLoading] = useState(false);
  const lastMsgId = useRef(0);
  const scrollRef = useRef<HTMLDivElement>(null);

  const loadConvs = useCallback(async () => {
    const res = await apiSupportConversations();
    if (res.success && res.data) setConversations(res.data.items);
  }, []);

  // Polling des messages actifs (long-polling simple).
  useEffect(() => {
    if (!activeId || !open) return;
    let alive = true;
    const tick = async () => {
      const res = await apiSupportMessages(activeId, lastMsgId.current);
      if (alive && res.success && res.data) {
        const newItems = res.data.items.filter((m) => m.id > lastMsgId.current);
        if (newItems.length) {
          setMessages((prev) => [...prev, ...newItems]);
          lastMsgId.current = Math.max(...res.data.items.map((m) => m.id));
        }
      }
    };
    tick();
    const iv = setInterval(tick, 2500);
    return () => { alive = false; clearInterval(iv); };
  }, [activeId, open]);

  useEffect(() => { scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight }); }, [messages]);

  useEffect(() => {
    if (open) void loadConvs();
    else setActiveId(null);
  }, [open, loadConvs]);

  async function openConv(id: number) {
    setActiveId(id);
    setComposing(false);
    setMessages([]);
    lastMsgId.current = 0;
    const res = await apiSupportMessages(id);
    if (res.success && res.data) {
      setMessages(res.data.items);
      lastMsgId.current = res.data.items.length ? Math.max(...res.data.items.map((m) => m.id)) : 0;
    }
  }

  async function createTicket() {
    if (!subject.trim()) return;
    setLoading(true);
    const res = await apiSupportCreateConversation(subject.trim(), category);
    setLoading(false);
    if (res.success && res.data) {
      await loadConvs();
      await openConv(res.data.conversation.id);
      setSubject('');
    }
  }

  async function send() {
    if (!draft.trim() || !activeId) return;
    const body = draft.trim();
    setDraft('');
    setMessages((prev) => [...prev, { id: Date.now(), conversation_id: activeId, customer_id: null, agent_id: null, is_bot: false, body, read_at: null, created_at: new Date().toISOString(), customer_name: 'Vous', agent_name: null }]);
    const res = await apiSupportSendMessage(activeId, body);
    if (res.success && res.data?.bot_reply) {
      // La réponse bot sera ramenée par le polling.
    }
  }

  const activeConv = conversations.find((c) => c.id === activeId);

  return (
    <>
      {/* Bouton flottant */}
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
            {/* En-tête */}
            <div className="chat-panel-head">
              {composing ? (
                <button className="chat-back" onClick={() => setComposing(false)}>←</button>
              ) : activeId && activeConv ? (
                <button className="chat-back" onClick={() => { setActiveId(null); setMessages([]); }}>←</button>
              ) : null}
              <div className="chat-title">
                <span className="chat-live" />
                Assistance Nexus
              </div>
              <button className="chat-close" onClick={() => setOpen(false)} aria-label="Fermer">✕</button>
            </div>

            <div className="chat-panel-body" ref={scrollRef}>
              {!activeId && !composing && (
                <div className="chat-home">
                  {conversations.length === 0 ? (
                    <div className="chat-empty">
                      <div className="chat-avatar">🤝</div>
                      <p>Comment pouvons-nous vous aider ?</p>
                      <button className="chat-cta" onClick={() => setComposing(true)}>Nouveau ticket</button>
                    </div>
                  ) : (
                    <div className="chat-conv-list">
                      <button className="chat-new" onClick={() => setComposing(true)}>＋ Nouveau ticket</button>
                      {conversations.map((c) => (
                        <button key={c.id} className="chat-conv" onClick={() => void openConv(c.id)}>
                          <span className="chat-conv-cat">{c.category}</span>
                          <span className="chat-conv-subject">{c.subject}</span>
                          <span className={`chat-status s-${c.status}`}>{c.status}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {composing && (
                <div className="chat-compose">
                  <label className="chat-label">Catégorie</label>
                  <select className="chat-input" value={category} onChange={(e) => setCategory(e.target.value)}>
                    <option value="account">Mon compte</option>
                    <option value="transfer">Transfert</option>
                    <option value="kyc">Vérification KYC</option>
                    <option value="billing">Facturation</option>
                    <option value="other">Autre</option>
                  </select>
                  <label className="chat-label">Sujet</label>
                  <input className="chat-input" placeholder="Décrivez votre demande…" value={subject} onChange={(e) => setSubject(e.target.value)} />
                  <button className="chat-cta" disabled={!subject.trim() || loading} onClick={() => void createTicket()}>
                    {loading ? 'Ouverture…' : 'Ouvrir le ticket'}
                  </button>
                </div>
              )}

              {activeId && (
                <div className="chat-thread">
                  {messages.map((m) => {
                    const mine = !m.is_bot && m.agent_id === null;
                    const isBot = !!m.is_bot;
                    return (
                      <div key={m.id} className={`chat-bubble ${mine ? 'mine' : isBot ? 'bot' : 'agent'}`}>
                        <div className="chat-bubble-body">{m.body}</div>
                        <div className="chat-bubble-time">{new Date(m.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {activeId && (
              <div className="chat-panel-input">
                <input
                  placeholder="Écrivez votre message…"
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) void send(); }}
                />
                <button onClick={() => void send()} disabled={!draft.trim()} aria-label="Envoyer">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
                </button>
              </div>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
}
