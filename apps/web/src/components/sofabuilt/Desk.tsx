"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, api } from "@/lib/api";
import type { SbDict } from "@/dictionaries/sofabuilt";

type Door = "idea" | "premium";
type Question = { q: string; options: string[] };
type Message = { id: number; role: "customer" | "assistant"; body: string; meta?: { questions?: Question[] } | null };
type Scope = {
  name: string;
  purpose: string;
  features: string[];
  not_included: string[];
  requires: { woocommerce: boolean; wordpress: string; php: string };
  modules: { key: string; qty: number; why: string }[];
};
type Price = {
  lines: { key: string; label: string; qty: number; eur: number }[];
  build_eur: number;
  too_big: boolean;
  care_monthly_eur: number;
  launch: Record<string, { label: string; eur: number }>;
  delivery_days: [number, number];
};
type Research = { name: string; slug: string; installs: number; rating: number; url: string };
type Session = { id: string; door: Door; ready: boolean; scope: Scope | null; price: Price | null; research: Research[]; messages: Message[] };
type CatalogItem = { id: string; name: string; category: string; price: string; features: string[]; installs: number | null; own_from_eur: number };

const STORE = "sofabuilt.desk";

/**
 * The Sofabuilt desk (docs/specs/sofabuilt.md): the chat on the left, the scope and the price on
 * the right, both kept by the API. The chat id lives in this browser so a reload continues it.
 */
export function Desk({ t, doors, locale, start }: { t: SbDict["desk"]; doors: SbDict["hero"]["doors"]; locale: string; start: Door | null }) {
  const [session, setSession] = useState<Session | null>(null);
  const [door, setDoor] = useState<Door | null>(start);
  const [catalog, setCatalog] = useState<CatalogItem[]>([]);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);
  const eur = (n: number) => (locale === "de" ? `${n.toLocaleString("de-AT")} €` : `€${n.toLocaleString("en-IE")}`);
  const fill = (s: string, v: Record<string, string | number>) => s.replace(/\{(\w+)\}/g, (_, k) => String(v[k] ?? ""));

  useEffect(() => {
    let id: string | null = null;
    try {
      id = localStorage.getItem(STORE);
    } catch {}
    if (!id) return;
    api<Session>(`/desk/${id}`)
      .then((s) => {
        setSession(s);
        setDoor(s.door);
      })
      .catch(() => {
        try {
          localStorage.removeItem(STORE);
        } catch {}
      });
  }, []);

  useEffect(() => {
    if (door === "premium" && catalog.length === 0) {
      api<{ items: CatalogItem[] }>("/desk/catalog").then((r) => setCatalog(r.items)).catch(() => {});
    }
  }, [door, catalog.length]);

  useEffect(() => {
    end.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, [session?.messages.length, busy]);

  async function send(message: string) {
    const body = message.trim();
    if (body.length < 2 || busy) return;
    setBusy(true);
    setError(null);
    try {
      let s = session;
      if (!s) {
        s = await api<Session>("/desk", { method: "POST", body: JSON.stringify({ door: door ?? "idea", locale }) });
        try {
          localStorage.setItem(STORE, s.id);
        } catch {}
      }
      // Show the customer's line at once; the reply replaces the whole thread.
      setSession({ ...s, messages: [...s.messages, { id: -1, role: "customer", body }] });
      setText("");
      const next = await api<Session>(`/desk/${s.id}/messages`, { method: "POST", body: JSON.stringify({ text: body }) });
      setSession(next);
    } catch (e) {
      const err = e instanceof ApiError ? (e.body as { error?: string } | null)?.error : null;
      setError(err === "limit" ? t.limit : err === "turnstile" ? t.bot : t.unavailable);
      if (e instanceof ApiError && (e.body as Session | null)?.messages) setSession(e.body as Session);
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    try {
      localStorage.removeItem(STORE);
    } catch {}
    setSession(null);
    setDoor(null);
    setError(null);
  }

  const messages = session?.messages ?? [];
  const last = messages[messages.length - 1];
  const chips = !busy && last?.role === "assistant" ? (last.meta?.questions ?? []) : [];

  return (
    <div className="sb-desk">
      <section className="sb-desk-chat" aria-live="polite">
        {!door && (
          <div className="sb-desk-doors">
            <p className="sb-desk-q">{t.chooseDoor}</p>
            {doors.map((d) => (
              <button key={d.key} type="button" className={`sb-door sb-door-${d.key}`} onClick={() => setDoor(d.key as Door)}>
                <span className="sb-door-icon" aria-hidden="true">{d.key === "idea" ? "💡" : "🔁"}</span>
                <h2>{d.h}</h2>
                <p>{d.p}</p>
              </button>
            ))}
          </div>
        )}

        {door === "premium" && messages.length === 0 && (
          <div className="sb-cat">
            <h2>{t.catalogTitle}</h2>
            <p>{t.catalogLede}</p>
            <div className="sb-cat-grid">
              {catalog.map((c) => (
                <button key={c.id} type="button" className="sb-cat-item" disabled={busy} onClick={() => send(fill(t.pick, { name: c.name }))}>
                  <span className="sb-cat-cat">{c.category}</span>
                  <strong>{c.name}</strong>
                  <span className="sb-cat-meta">{fill(t.perYear, { price: c.price })}</span>
                  {c.installs ? <span className="sb-cat-meta">{fill(t.installs, { n: c.installs.toLocaleString(locale === "de" ? "de-AT" : "en-IE") })}</span> : null}
                  <span className="sb-cat-own">{fill(t.ownFrom, { price: eur(c.own_from_eur) })}</span>
                </button>
              ))}
            </div>
          </div>
        )}

        {door && (
          <>
            <div className="sb-thread">
              {messages.map((m, i) => (
                <div key={m.id === -1 ? `pending-${i}` : m.id} className={`sb-msg sb-msg-${m.role}`}>
                  {m.body}
                </div>
              ))}
              {busy && <div className="sb-msg sb-msg-assistant sb-msg-busy">{t.thinking}</div>}
              <div ref={end} />
            </div>
            {chips.length > 0 && (
              <div className="sb-chips">
                {chips.map((q) => (
                  <div key={q.q} className="sb-chip-row">
                    <span>{q.q}</span>
                    <div>
                      {q.options.map((o) => (
                        <button key={o} type="button" onClick={() => send(o)}>
                          {o}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
            {error && <p className="sb-desk-error">{error}</p>}
            <form
              className="sb-compose"
              onSubmit={(e) => {
                e.preventDefault();
                send(text);
              }}
            >
              <textarea
                value={text}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    send(text);
                  }
                }}
                placeholder={door === "premium" ? t.placeholderPremium : t.placeholder}
                maxLength={2000}
                rows={2}
              />
              <button type="submit" className="sb-btn" disabled={busy || text.trim().length < 2}>
                {t.send}
              </button>
            </form>
            {session && (
              <button type="button" className="sb-link" onClick={reset}>
                {t.newChat}
              </button>
            )}
          </>
        )}
      </section>

      <aside className="sb-desk-side">
        <div className="sb-side-card">
          <h3>{t.scopeTitle}</h3>
          {!session?.scope ? (
            <p className="sb-muted">{t.scopeEmpty}</p>
          ) : (
            <>
              <p className="sb-scope-name">{session.scope.name}</p>
              <p className="sb-muted">{session.scope.purpose}</p>
              {session.scope.features.length > 0 && (
                <>
                  <h4>{t.features}</h4>
                  <ul>{session.scope.features.map((f) => <li key={f}>{f}</li>)}</ul>
                </>
              )}
              {session.scope.not_included.length > 0 && (
                <>
                  <h4>{t.notIncluded}</h4>
                  <ul className="sb-out">{session.scope.not_included.map((f) => <li key={f}>{f}</li>)}</ul>
                </>
              )}
              <p className="sb-small">
                {fill(t.requires, { wp: session.scope.requires.wordpress, php: session.scope.requires.php })}
                {session.scope.requires.woocommerce ? ` · ${t.requiresWoo}` : ""}
              </p>
            </>
          )}
        </div>

        {session?.price && (
          <div className="sb-side-card">
            <h3>{t.priceTitle}</h3>
            <table className="sb-lines">
              <tbody>
                {session.price.lines.map((l) => (
                  <tr key={l.key}>
                    <td>
                      {l.label}
                      {l.qty > 1 ? ` × ${l.qty}` : ""}
                    </td>
                    <td>{eur(l.eur)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td>{t.build}</td>
                  <td>{eur(session.price.build_eur)}</td>
                </tr>
              </tfoot>
            </table>
            <p className="sb-small">{fill(t.delivery, { lo: session.price.delivery_days[0], hi: session.price.delivery_days[1] })}</p>
            <p className="sb-small">{fill(t.care, { price: eur(session.price.care_monthly_eur) })}</p>
            <h4>{t.launchTitle}</h4>
            <ul className="sb-launch">
              {Object.entries(session.price.launch).map(([k, l]) => (
                <li key={k}>
                  <span>{l.label}</span>
                  <span>{eur(l.eur)}</span>
                </li>
              ))}
            </ul>
            {session.price.too_big && <p className="sb-warn">{t.tooBig}</p>}
            {session.ready && !session.price.too_big && (
              <div className="sb-ready">
                <p>{t.ready}</p>
                <a className="sb-btn" href={`mailto:developerweb@codemenschen.at?subject=${encodeURIComponent(`Sofabuilt offer ${session.id}`)}`}>
                  {t.order}
                </a>
                <p className="sb-small">{t.orderHint}</p>
              </div>
            )}
          </div>
        )}

        {(session?.research.length ?? 0) > 0 && (
          <div className="sb-side-card">
            <h3>{t.research}</h3>
            <ul className="sb-research">
              {session!.research.map((r) => (
                <li key={r.slug}>
                  <a href={r.url} target="_blank" rel="noopener noreferrer">
                    {r.name}
                  </a>
                  <span>
                    {fill(t.installs, { n: r.installs.toLocaleString(locale === "de" ? "de-AT" : "en-IE") })} · {fill(t.rating, { r: r.rating })}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
        <p className="sb-small">
          {t.mail} <a href="mailto:developerweb@codemenschen.at">developerweb@codemenschen.at</a>
        </p>
      </aside>
    </div>
  );
}
