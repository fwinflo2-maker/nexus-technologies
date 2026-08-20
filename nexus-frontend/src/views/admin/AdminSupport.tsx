import { useEffect, useRef, useState, type ClipboardEvent } from 'react';
import {
  apiSupportConversations, apiSupportMessages, apiSupportSendMessage, apiSupportSetStatus, apiSupportUpload,
  type SupportConversation, type SupportMessage,
} from '../../api/client';
import { Stat } from './adminUi';

function isImageAttachment(url: string | null, name: string | null): boolean {
  return /\.(png|jpe?g|gif|webp)(\?|$)/i.test(`${url ?? ''} ${name ?? ''}`);
}

/** Console support agent (Super Admin) : tickets, réponses, notes internes, pièces jointes, temps de réponse. */
export default function AdminSupport() {
  const [convs, setConvs] = useState<SupportConversation[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [activeConv, setActiveConv] = useState<SupportConversation | null>(null);
  const [messages, setMessages] = useState<SupportMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [internal, setInternal] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const lastMsgId = useRef(0);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = async () => {
    const res = await apiSupportConversations();
    if (res.success && res.data) setConvs(res.data.items);
  };
  useEffect(() => { void load(); }, []);

  useEffect(() => {
    if (!activeId) return;
    let alive = true;
    const tick = async () => {
      const res = await apiSupportMessages(activeId, lastMsgId.current);
      if (alive && res.success && res.data) {
        const items = res.data.items;
        const max = items.reduce((a, m) => Math.max(a, m.id), 0);
        if (max > lastMsgId.current) {
          setMessages((prev) => {
            const known = new Set(prev.map((m) => m.id));
            return [...prev, ...items.filter((m) => m.id > lastMsgId.current && !known.has(m.id))];
          });
          lastMsgId.current = max;
        }
      }
    };
    tick();
    const iv = setInterval(tick, 2500);
    return () => { alive = false; clearInterval(iv); };
  }, [activeId]);

  async function openConv(c: SupportConversation) {
    setActiveId(c.id); setActiveConv(c); setMessages([]); lastMsgId.current = 0;
    const res = await apiSupportMessages(c.id);
    if (res.success && res.data) {
      setMessages(res.data.items);
      lastMsgId.current = res.data.items.reduce((a, m) => Math.max(a, m.id), 0);
    }
  }

  async function send() {
    if (!activeId) return;
    let attName: string | undefined; let attUrl: string | undefined;
    if (file) {
      const up = await apiSupportUpload(file);
      if (up.success && up.data) { attName = up.data.name; attUrl = up.data.url; }
      setFile(null);
    }
    const body = draft.trim() || (attUrl ? `📎 ${attName}` : '');
    if (!body && !attUrl) return;
    setDraft('');
    setMessages((prev) => [...prev, {
      id: Date.now(), conversation_id: activeId, customer_id: null, agent_id: 1, is_bot: false, is_internal: internal,
      body, attachment_name: attName ?? null, attachment_url: attUrl ?? null, read_at: null,
      created_at: new Date().toISOString(), customer_name: null, agent_name: 'Vous',
    }]);
    await apiSupportSendMessage(activeId, body, { is_internal: internal, attachment_name: attName, attachment_url: attUrl });
  }

  function onPaste(e: ClipboardEvent) {
    const items = e.clipboardData?.items;
    if (!items) return;
    for (const item of Array.from(items)) {
      if (item.kind === 'file' && item.type.startsWith('image/')) {
        const blob = item.getAsFile();
        if (!blob) continue;
        e.preventDefault();
        const ext = blob.type === 'image/jpeg' ? 'jpg' : 'png';
        setFile(new File([blob], `capture-${Date.now()}.${ext}`, { type: blob.type || 'image/png' }));
        return;
      }
    }
  }

  async function setStatus(status: string) {
    if (!activeId) return;
    const res = await apiSupportSetStatus(activeId, status);
    if (res.success && res.data) {
      setActiveConv((c) => c ? { ...c, status: res.data!.status } : c);
      await load();
    }
  }

  const open = convs.filter((c) => c.status === 'open').length;
  const waiting = convs.filter((c) => c.status === 'waiting').length;
  const resolved = convs.filter((c) => c.status === 'resolved' || c.status === 'closed').length;
  const unreadTotal = convs.reduce((s, c) => s + (c.unread ?? 0), 0);

  // Temps de réponse moyen (agent) : écarts entre message client et réponse agent.
  function responseTime(): string {
    if (!activeId) return '—';
    let lastClient: Date | null = null; let firstAgent: Date | null = null;
    for (const m of messages) {
      if (m.customer_id && !m.is_internal) lastClient = new Date(m.created_at);
      if (m.agent_id && !m.is_internal && !firstAgent) firstAgent = new Date(m.created_at);
    }
    if (lastClient && firstAgent) {
      const sec = Math.max(0, Math.round((firstAgent.getTime() - lastClient.getTime()) / 1000));
      if (sec < 60) return `${sec}s`;
      return `${Math.round(sec / 60)}m`;
    }
    return '—';
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div className="g4">
        <Stat label="Conversations" value={convs.length} />
        <Stat label="En attente" value={open + waiting} tone="var(--gold)" />
        <Stat label="Non lues" value={unreadTotal} tone="var(--red)" />
        <Stat label="Résolues" value={resolved} tone="var(--green)" />
      </div>

      <div className="card" style={{ padding: 14, display: 'flex', gap: 14, minHeight: 480 }}>
        {/* Liste des conversations */}
        <div style={{ width: 300, borderRight: '1px solid var(--border-soft)', paddingRight: 12, overflowY: 'auto' }}>
          <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', marginBottom: 10 }}>🗂 Tickets</div>
          {convs.length === 0 ? (
            <div style={{ fontSize: 12, color: 'var(--text-dim)', padding: 12 }}>Aucun ticket pour le moment.</div>
          ) : convs.map((c) => (
            <button key={c.id} onClick={() => void openConv(c)}
              style={{ width: '100%', textAlign: 'left', padding: '10px 12px', borderRadius: 10, cursor: 'pointer', border: `1px solid ${activeId === c.id ? 'rgba(59,130,246,0.5)' : 'var(--border-soft)'}`, background: activeId === c.id ? 'rgba(59,130,246,0.1)' : 'var(--panel)', marginBottom: 8, display: 'flex', flexDirection: 'column', gap: 3 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-bright)' }}>{c.client_name}</span>
                <span className={`chat-status s-${c.status}`} style={{ fontSize: 9 }}>{c.status}</span>
              </div>
              <span style={{ fontSize: 11.5, color: 'var(--text-mid)' }}>{c.subject}</span>
              <span style={{ fontSize: 10, color: 'var(--text-dim)' }}>{c.client_email}</span>
              {(c.unread ?? 0) > 0 && <span style={{ alignSelf: 'flex-end', background: 'var(--red)', color: '#fff', borderRadius: 10, padding: '1px 7px', fontSize: 10 }}>{c.unread} non lu</span>}
            </button>
          ))}
        </div>

        {/* Fil de la conversation active */}
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
          {!activeId ? (
            <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-dim)', fontSize: 13 }}>Sélectionnez une conversation à gauche.</div>
          ) : (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingBottom: 10, borderBottom: '1px solid var(--border-soft)', marginBottom: 10 }}>
                <div>
                  <div style={{ fontWeight: 700, color: 'var(--text-bright)' }}>{activeConv?.subject}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-dim)' }}>{activeConv?.client_name} · {activeConv?.client_email} · ⏱ réponse {responseTime()}</div>
                </div>
                <div style={{ display: 'flex', gap: 6 }}>
                  {['waiting', 'resolved', 'closed', 'open'].map((s) => (
                    <button key={s} onClick={() => void setStatus(s)} style={{ fontSize: 10, padding: '4px 10px', borderRadius: 10, cursor: 'pointer', border: activeConv?.status === s ? '1px solid var(--cyan)' : '1px solid var(--border-soft)', background: activeConv?.status === s ? 'rgba(59,130,246,0.15)' : 'var(--panel)', color: 'var(--text-main)' }}>{s}</button>
                  ))}
                </div>
              </div>

              <div style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8 }}>
                {messages.map((m) => {
                  const mine = m.agent_id !== null;
                  const internalNote = m.is_internal;
                  return (
                    <div key={m.id} className={`chat-bubble ${internalNote ? 'internal' : mine ? 'mine' : m.is_bot ? 'bot' : 'agent'}`}>
                      <div className="chat-bubble-body">
                        {internalNote && <div style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 3, opacity: 0.7 }}>🔒 Note interne</div>}
                        {m.body}
                        {m.attachment_url && (
                          <div style={{ marginTop: 6 }}>
                            {isImageAttachment(m.attachment_url, m.attachment_name) ? (
                              <a href={m.attachment_url} target="_blank" rel="noreferrer" className="chat-attach-preview">
                                <img src={m.attachment_url} alt={m.attachment_name ?? 'Image'} loading="lazy" />
                                <span>{m.attachment_name ?? 'Image'}</span>
                              </a>
                            ) : (
                              <a href={m.attachment_url} target="_blank" rel="noreferrer" className="chat-attach">📎 {m.attachment_name ?? 'pièce jointe'}</a>
                            )}
                          </div>
                        )}
                      </div>
                      <div className="chat-bubble-time">{m.agent_name ?? m.customer_name} · {new Date(m.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}</div>
                    </div>
                  );
                })}
              </div>

              <div className="chat-panel-input" style={{ borderTop: '1px solid var(--border-soft)', marginTop: 10 }} onPaste={onPaste}>
                <button className="chat-file-btn" onClick={() => fileRef.current?.click()} title="Joindre un fichier ou une capture" type="button">📎</button>
                {file && (
                  <button type="button" className="chat-file-tag" onClick={() => setFile(null)} title="Retirer">
                    {file.name} ✕
                  </button>
                )}
                <input ref={fileRef} type="file" hidden accept="image/*,.pdf,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                <input
                  placeholder={internal ? 'Note interne (visible agents uniquement)…' : 'Répondre — Ctrl+V pour coller une capture…'}
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onPaste={onPaste}
                  onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) void send(); }}
                />
                <label className="chat-internal-toggle">
                  <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
                  <span>Interne</span>
                </label>
                <button onClick={() => void send()} disabled={!draft.trim() && !file} aria-label="Envoyer">➤</button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
