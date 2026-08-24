import { useCallback, useEffect, useRef, useState } from 'react';
import {
  apiStaffChats, apiStaffCreateChat, apiStaffChatMessages, apiStaffChatSend, apiStaffDirectory,
  type InternalChat, type InternalChatMessage, type StaffDirectoryEntry,
} from '../../api/client';
import TechLoader from '../../components/anim/TechLoader';

/**
 * Messagerie interne Nexus — employés ⇄ superadmin.
 *
 * Liste des fils de discussion (avec non-lus), fil de messages avec
 * long-polling léger, composeur, et création d'une nouvelle discussion avec
 * choix des membres depuis l'annuaire du personnel. Les escalades support
 * apparaissent ici comme des fils liés à un ticket.
 */

const fmtTime = (iso: string) => {
  const d = new Date(iso.replace(' ', 'T') + (iso.includes('T') || iso.includes('Z') ? '' : 'Z'));
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
};

const fmtDate = (iso: string) => {
  const d = new Date(iso.replace(' ', 'T') + (iso.includes('T') || iso.includes('Z') ? '' : 'Z'));
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' });
};

export default function InternalChat({ myId }: { myId: number }) {
  const [chats, setChats] = useState<InternalChat[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [messages, setMessages] = useState<InternalChatMessage[]>([]);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [directory, setDirectory] = useState<StaffDirectoryEntry[]>([]);
  const lastMsgIdRef = useRef(0);
  const bottomRef = useRef<HTMLDivElement>(null);

  const loadChats = useCallback(async () => {
    const res = await apiStaffChats();
    if (res.success && res.data) {
      setChats(res.data.items);
      // Garde le fil actif s'il existe encore.
      setActiveId((cur) => (cur !== null && res.data!.items.some((c) => c.id === cur) ? cur : null));
    }
  }, []);

  const loadMessages = useCallback(async (chatId: number, afterId = 0) => {
    const res = await apiStaffChatMessages(chatId, afterId);
    if (res.success && res.data) {
      setMessages((prev) => {
        if (afterId === 0) return res.data!.items;
        const seen = new Set(prev.map((m) => m.id));
        return [...prev, ...res.data!.items.filter((m) => !seen.has(m.id))];
      });
      lastMsgIdRef.current = res.data.items.reduce((mx, m) => Math.max(mx, m.id), lastMsgIdRef.current);
    }
  }, []);

  // Chargement initial + rafraîchissement des fils.
  useEffect(() => {
    let alive = true;
    void (async () => {
      const res = await apiStaffChats();
      if (!alive) return;
      if (res.success && res.data) setChats(res.data.items);
      setLoading(false);
    })();
    const poll = window.setInterval(() => { if (!document.hidden) void loadChats(); }, 5000);
    return () => { alive = false; window.clearInterval(poll); };
  }, [loadChats]);

  // Messages du fil actif — long-polling léger.
  useEffect(() => {
    if (activeId === null) return;
    setMessages([]);
    lastMsgIdRef.current = 0;
    void loadMessages(activeId, 0);
    const poll = window.setInterval(() => {
      if (!document.hidden && activeId !== null) void loadMessages(activeId, lastMsgIdRef.current);
    }, 3000);
    return () => window.clearInterval(poll);
  }, [activeId, loadMessages]);

  // Défilement automatique vers le bas.
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [messages]);

  async function send() {
    const body = draft.trim();
    if (!body || activeId === null) return;
    setDraft('');
    const res = await apiStaffChatSend(activeId, body);
    if (res.success) {
      void loadMessages(activeId, lastMsgIdRef.current);
      void loadChats();
    } else {
      setError(res.error ?? 'Envoi impossible.');
      setDraft(body);
    }
  }

  const activeChat = chats.find((c) => c.id === activeId) ?? null;
  const totalUnread = chats.reduce((a, c) => a + c.unread, 0);

  return (
    <div className="card shine-sweep" style={{ padding: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
        <span style={{ fontSize: 13 }}>💬</span>
        <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-bright)' }}>
          MESSAGERIE INTERNE {totalUnread > 0 && <span style={{ color: 'var(--cyan2)', marginLeft: 6 }}>· {totalUnread} non lu(s)</span>}
        </span>
        <button
          onClick={() => { void (async () => { const d = await apiStaffDirectory(); if (d.success && d.data) setDirectory(d.data.items); })(); setShowNew(true); }}
          style={{
            marginLeft: 'auto', background: 'var(--cyan)', color: '#fff', border: 'none', borderRadius: 9,
            padding: '6px 13px', fontSize: 12, fontWeight: 700, cursor: 'pointer', fontFamily: 'inherit',
          }}
        >+ Nouvelle discussion</button>
      </div>

      {error && <div style={{ marginBottom: 10, fontSize: 12.5, color: 'var(--red)', fontWeight: 600 }}>⚠ {error}</div>}

      {loading ? (
        <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}><TechLoader label="Chargement des discussions" /></div>
      ) : chats.length === 0 ? (
        <div style={{ padding: '30px 16px', textAlign: 'center', color: 'var(--text-dim)', fontSize: 12.5 }}>
          Aucune discussion pour le moment. Ouvrez une nouvelle discussion avec un collègue, ou escaladez un ticket depuis la console support.
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: '250px 1fr', gap: 12, minHeight: 380 }}>
          {/* Liste des fils */}
          <div style={{ borderRight: '1px solid var(--border-soft)', paddingRight: 10, overflowY: 'auto', maxHeight: 420, display: 'flex', flexDirection: 'column', gap: 6 }}>
            {chats.map((c) => (
              <button
                key={c.id}
                onClick={() => setActiveId(c.id)}
                style={{
                  textAlign: 'left', background: c.id === activeId ? 'rgba(34,211,238,0.10)' : 'rgba(255,255,255,0.02)',
                  border: `1px solid ${c.id === activeId ? 'rgba(34,211,238,0.35)' : 'var(--border-soft)'}`,
                  borderRadius: 10, padding: '10px 12px', cursor: 'pointer', fontFamily: 'inherit', display: 'block', width: '100%',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 13 }}>{c.related_conversation_id ? '🚨' : '💬'}</span>
                  <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-bright)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1 }}>{c.title}</span>
                  {c.unread > 0 && (
                    <span style={{ background: 'var(--cyan)', color: '#04121c', fontSize: 10.5, fontWeight: 800, borderRadius: 20, padding: '1px 7px' }}>{c.unread}</span>
                  )}
                </div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 4, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {c.last_body ? `${c.last_sender ?? ''} : ${c.last_body}` : 'Nouvelle discussion'}
                </div>
                <div style={{ fontSize: 10, color: 'var(--text-faint)', marginTop: 3, fontFamily: 'var(--font-mono)' }}>{fmtDate(c.updated_at)} · {c.members.length} membre(s)</div>
              </button>
            ))}
          </div>

          {/* Fil actif */}
          {activeChat ? (
            <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
              <div style={{ borderBottom: '1px solid var(--border-soft)', paddingBottom: 10, marginBottom: 10 }}>
                <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-bright)' }}>{activeChat.title}</div>
                <div style={{ fontSize: 11, color: 'var(--text-dim)', marginTop: 2 }}>
                  {activeChat.members.map((m) => m.full_name).join(' · ')}
                  {activeChat.ticket_subject && (
                    <span style={{ color: 'var(--gold)', marginLeft: 8 }}>Ticket : {activeChat.ticket_subject}</span>
                  )}
                </div>
              </div>

              <div style={{ flex: 1, overflowY: 'auto', maxHeight: 300, display: 'flex', flexDirection: 'column', gap: 8, paddingRight: 4 }}>
                {messages.length === 0 && (
                  <div style={{ color: 'var(--text-faint)', fontSize: 12, textAlign: 'center', padding: 20 }}>Aucun message.</div>
                )}
                {messages.map((m) => {
                  const mine = m.sender_id === myId;
                  const system = Boolean(m.is_system);
                  if (system) {
                    return (
                      <div key={m.id} style={{ textAlign: 'center', fontSize: 11.5, color: 'var(--gold)', background: 'rgba(245,158,11,0.06)', border: '1px solid rgba(245,158,11,0.2)', borderRadius: 10, padding: '8px 12px', whiteSpace: 'pre-wrap' }}>
                        {m.body}
                      </div>
                    );
                  }
                  return (
                    <div key={m.id} style={{ display: 'flex', justifyContent: mine ? 'flex-end' : 'flex-start' }}>
                      <div style={{
                        maxWidth: '78%', padding: '8px 12px', borderRadius: 12, fontSize: 12.5, lineHeight: 1.55,
                        background: mine ? 'rgba(34,211,238,0.14)' : 'rgba(255,255,255,0.05)',
                        border: `1px solid ${mine ? 'rgba(34,211,238,0.3)' : 'var(--border-soft)'}`,
                        color: 'var(--text)',
                      }}>
                        {!mine && (
                          <div style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--cyan2)', marginBottom: 2 }}>
                            {m.sender_name} · <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--text-dim)' }}>{m.platform_role}</span>
                          </div>
                        )}
                        <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{m.body}</div>
                        <div style={{ fontSize: 10, color: 'var(--text-faint)', marginTop: 3, fontFamily: 'var(--font-mono)' }}>{fmtTime(m.created_at)}</div>
                      </div>
                    </div>
                  );
                })}
                <div ref={bottomRef} />
              </div>

              <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                <input
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void send(); } }}
                  placeholder="Écrivez votre message… (Entrée pour envoyer)"
                  style={{
                    flex: 1, background: 'rgba(255,255,255,0.04)', border: '1px solid var(--border-soft)', borderRadius: 10,
                    color: 'var(--text)', padding: '9px 13px', fontSize: 12.5, fontFamily: 'inherit', outline: 'none',
                  }}
                />
                <button
                  onClick={() => void send()}
                  disabled={draft.trim() === ''}
                  style={{
                    background: 'var(--cyan)', color: '#fff', border: 'none', borderRadius: 10, padding: '9px 18px',
                    fontSize: 12.5, fontWeight: 700, cursor: draft.trim() === '' ? 'not-allowed' : 'pointer', opacity: draft.trim() === '' ? 0.45 : 1, fontFamily: 'inherit',
                  }}
                >Envoyer</button>
              </div>
            </div>
          ) : (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-faint)', fontSize: 12.5 }}>
              Sélectionnez une discussion.
            </div>
          )}
        </div>
      )}

      {/* Modale nouvelle discussion */}
      {showNew && (
        <NewChatModal
          directory={directory}
          myId={myId}
          onClose={() => setShowNew(false)}
          onCreated={async (chatId) => {
            setShowNew(false);
            await loadChats();
            setActiveId(chatId);
          }}
        />
      )}
    </div>
  );
}

/** Modale de création d'une discussion : titre + membres (annuaire). */
function NewChatModal({
  directory, myId, onClose, onCreated,
}: {
  directory: StaffDirectoryEntry[];
  myId: number;
  onClose: () => void;
  onCreated: (chatId: number) => Promise<void>;
}) {
  const [title, setTitle] = useState('');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  async function create() {
    if (title.trim() === '') { setErr('Un titre est requis.'); return; }
    if (selected.size === 0) { setErr('Choisissez au moins un membre.'); return; }
    setBusy(true);
    const res = await apiStaffCreateChat({ title: title.trim(), member_ids: [...selected] });
    setBusy(false);
    if (res.success && res.data) {
      await onCreated(res.data.id);
    } else {
      setErr(res.error ?? 'Création impossible.');
    }
  }

  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 500, background: 'rgba(5,6,12,0.72)', display: 'flex', alignItems: 'center', justifyContent: 'center',
      padding: 20, backdropFilter: 'blur(6px)',
    }} onClick={onClose}>
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: '100%', maxWidth: 480, background: 'linear-gradient(160deg, rgba(20,23,38,0.98), rgba(11,13,22,0.98))',
          border: '1px solid var(--border-soft)', borderRadius: 16, padding: 22, boxShadow: '0 24px 70px rgba(0,0,0,0.55)',
        }}
      >
        <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-bright)', marginBottom: 6 }}>Nouvelle discussion interne</div>
        <div style={{ fontSize: 12, color: 'var(--text-dim)', marginBottom: 14 }}>Ouvrez un fil avec un ou plusieurs collègues Nexus.</div>

        <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>Titre</label>
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          placeholder="Ex. Coordination incident providers"
          autoFocus
          style={{
            width: '100%', boxSizing: 'border-box', background: 'rgba(255,255,255,0.04)', border: '1px solid var(--border-soft)',
            borderRadius: 10, color: 'var(--text)', padding: '9px 12px', fontSize: 12.5, fontFamily: 'inherit', outline: 'none', marginBottom: 12,
          }}
        />

        <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-dim)', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 6 }}>
          Membres ({selected.size})
        </label>
        <div style={{ maxHeight: 210, overflowY: 'auto', border: '1px solid var(--border-soft)', borderRadius: 10, padding: 6, display: 'flex', flexDirection: 'column', gap: 2 }}>
          {directory.filter((d) => d.id !== myId).map((d) => (
            <label key={d.id} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '7px 9px', borderRadius: 8, cursor: 'pointer', background: selected.has(d.id) ? 'rgba(34,211,238,0.08)' : 'transparent' }}>
              <input
                type="checkbox"
                checked={selected.has(d.id)}
                onChange={(e) => {
                  const next = new Set(selected);
                  if (e.target.checked) next.add(d.id); else next.delete(d.id);
                  setSelected(next);
                }}
                style={{ accentColor: 'var(--cyan)' }}
              />
              <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-bright)' }}>{d.full_name}</span>
              <span style={{ fontSize: 10.5, color: 'var(--text-dim)', fontFamily: 'var(--font-mono)', marginLeft: 'auto' }}>{d.platform_role}{d.department ? ` · ${d.department}` : ''}</span>
            </label>
          ))}
        </div>

        {err && <div style={{ marginTop: 10, fontSize: 12.5, color: 'var(--red)', fontWeight: 600 }}>⚠ {err}</div>}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
          <button
            onClick={onClose}
            style={{ background: 'rgba(255,255,255,0.06)', color: 'var(--text-mid)', border: '1px solid var(--border-soft)', borderRadius: 10, padding: '9px 16px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', fontFamily: 'inherit' }}
          >Annuler</button>
          <button
            onClick={() => void create()}
            disabled={busy}
            style={{ background: 'var(--cyan)', color: '#fff', border: 'none', borderRadius: 10, padding: '9px 18px', fontSize: 12.5, fontWeight: 700, cursor: busy ? 'not-allowed' : 'pointer', opacity: busy ? 0.5 : 1, fontFamily: 'inherit' }}
          >{busy ? '…' : 'Créer la discussion'}</button>
        </div>
      </div>
    </div>
  );
}
