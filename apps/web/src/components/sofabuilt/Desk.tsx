"use client";

import { useEffect, useRef, useState } from "react";
import { ApiError, api } from "@/lib/api";
import { Icon } from "@/components/LineIcon";
import type { SbDict } from "@/dictionaries/sofabuilt";
import { SbCheckout } from "./SbCheckout";

type Door = "idea" | "premium";
type Platform = "wordpress" | "shopify" | "chrome";
// What the desk offers (owner, 2026-10-05: WordPress and Shopify). Chrome stays built, not shown.
const OFFERED: Platform[] = ["wordpress", "shopify"];
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
  care_trial_months?: number;
  launch: Record<string, { label: string; eur: number }>;
  delivery_days: [number, number];
};
type Research = { name: string; slug: string; installs: number; rating: number; url: string };
type Option = { key: string; label: string; eur: number; max: number };
type Session = { id: string; door: Door; platform?: Platform; ready: boolean; scope: Scope | null; price: Price | null; research: Research[]; messages: Message[]; module_options?: Option[] };
type CatalogItem = { id: string; name: string; category: string; price: string; features: string[]; installs: number | null; reviews: number | null; own_from_eur: number };

const STORE = "sofabuilt.desk";
const MAX = 500;

/** The few icons the shared line set lacks, same stroke style. */
const EXTRA: Record<string, string> = {
  chat: "M4 5h16v11H9l-5 4z M8 9h8 M8 12h5",
  cart: "M3 4h2l2.4 10.5h10.2L20 7H6.2 M9 19.5h.01 M17 19.5h.01",
  card: "M3 6h18v12H3z M3 10h18",
  help: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6 M12 17h.01",
  dots: "M6 12h.01 M12 12h.01 M18 12h.01",
  x: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M9 9l6 6 M15 9l-6 6",
  ext: "M14 4h6v6 M20 4l-9 9 M18 14v6H4V6h6",
  wrench: "M14.7 6.3a4 4 0 0 0 5 5L12 19a2.1 2.1 0 0 1-3-3z M14.7 6.3 17 4",
  done: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M8 12l3 3 5-6",
};

function Ico({ name, className = "dk-ico" }: { name: string; className?: string }) {
  if (!EXTRA[name]) return <Icon name={name} className={className} />;
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={EXTRA[name]} />
    </svg>
  );
}

/** A picture for an answer option, guessed from its words; a plain spark when nothing fits. */
function optionIcon(text: string): string {
  const t = text.toLowerCase();
  if (/not sure|unsure|don.t know|weiß nicht|nicht sicher|maybe/.test(t)) return "help";
  if (/\bno\b|none|nein|keine|nothing/.test(t) && /pay|zahl/.test(t)) return "card";
  if (/pay|zahl|cart|buy|kauf|order|bestell|checkout/.test(t)) return "cart";
  if (/book|termin|appointment|calendar|date|time|schedule/.test(t)) return "calendar";
  if (/sign|survey|umfrage|member|mitglied|people|team|client|kunden|customer/.test(t)) return "team";
  if (/contact|kontakt|question|frage|chat|message|support/.test(t)) return "chat";
  if (/mail/.test(t)) return "mail";
  if (/shop|store|woo/.test(t)) return "store";
  if (/mix|all|both|everything|alles|beides/.test(t)) return "dots";
  if (/picture|image|photo|bild/.test(t)) return "image";
  if (/fast|speed|quick|schnell/.test(t)) return "spark";
  if (/site|website|seite/.test(t)) return "site";
  return "spark";
}

/** The desk's picture: a form being sent off. */
function DeskArt() {
  return (
    <svg className="dk-art" viewBox="0 0 280 170" aria-hidden="true">
      <circle cx="205" cy="95" r="70" fill="var(--accent-soft)" />
      <rect x="40" y="18" width="150" height="150" rx="12" fill="#fff" stroke="#dfe5f3" />
      <circle cx="55" cy="32" r="3.5" fill="#f87171" />
      <circle cx="66" cy="32" r="3.5" fill="#fbbf24" />
      <circle cx="77" cy="32" r="3.5" fill="#34d399" />
      <rect x="54" y="48" width="8" height="8" rx="2" fill="#93c5fd" />
      <rect x="70" y="47" width="100" height="10" rx="5" fill="var(--accent)" opacity=".75" />
      {[72, 96, 120].map((y) => (
        <g key={y}>
          <rect x="54" y={y} width="8" height="8" rx="2" fill="#c7d2fe" />
          <rect x="70" y={y - 2} width="100" height="12" rx="4" fill="#fff" stroke="#c7d2fe" />
        </g>
      ))}
      <path d="M232 70 L262 58 L250 92 L242 80 Z" fill="var(--accent)" />
      <path d="M242 80 L262 58" stroke="#fff" strokeWidth="2" />
      <path d="M240 86 C230 110 215 118 200 112" fill="none" stroke="var(--accent)" strokeWidth="2" strokeDasharray="4 5" />
    </svg>
  );
}

/**
 * The Sofabuilt desk (docs/specs/sofabuilt.md), in the owner's reference layout: the idea and
 * the assistant's questions on the left as tappable cards, scope, price, launch and similar
 * plugins on the right. The chat id lives in this browser so a reload continues it.
 */
export function Desk({ t, doors, locale, start, startPlatform }: { t: SbDict["desk"]; doors: SbDict["hero"]["doors"]; locale: string; start: Door | null; startPlatform: Platform }) {
  const [session, setSession] = useState<Session | null>(null);
  const [platform, setPlatform] = useState<Platform>(startPlatform);
  // A Chrome extension starts from an idea: there is no premium catalogue for it yet.
  const [door, setDoor] = useState<Door | null>(startPlatform === "chrome" ? "idea" : start);
  const [catalog, setCatalog] = useState<CatalogItem[]>([]);
  const [text, setText] = useState("");
  const [picked, setPicked] = useState<Record<number, string>>({});
  const [launch, setLaunch] = useState<Record<string, boolean>>({});
  const [more, setMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);
  const de = locale === "de";
  const eur = (n: number) => (de ? `${n.toLocaleString("de-AT")} €` : `€${n.toLocaleString("en-IE")}`);
  const num = (n: number) => n.toLocaleString(de ? "de-AT" : "en-IE");
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
        if (s.platform) setPlatform(s.platform);
      })
      .catch(() => {
        try {
          localStorage.removeItem(STORE);
        } catch {}
      });
  }, []);

  useEffect(() => {
    if (door === "premium" && platform !== "chrome" && catalog.length === 0) {
      api<{ items: CatalogItem[] }>(`/desk/catalog?platform=${platform}`).then((r) => setCatalog(r.items)).catch(() => {});
    }
  }, [door, platform, catalog.length]);

  useEffect(() => {
    end.current?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, [session?.messages.length, busy]);

  const messages = session?.messages ?? [];
  const last = messages[messages.length - 1];
  const questions = !busy && last?.role === "assistant" ? (last.meta?.questions ?? []) : [];

  // The chat as exchanges: what the customer said and what came back.
  const exchanges: { ask: Message; answer: Message | null }[] = [];
  for (const m of messages) {
    if (m.role === "customer") exchanges.push({ ask: m, answer: null });
    else if (exchanges.length) exchanges[exchanges.length - 1].answer = m;
    else exchanges.push({ ask: { ...m, body: "" }, answer: m });
  }
  const current = exchanges[exchanges.length - 1];
  const earlier = exchanges.slice(0, -1);

  async function send(message: string) {
    const body = message.trim();
    if (body.length < 2 || busy) return;
    setBusy(true);
    setError(null);
    try {
      let s = session;
      if (!s) {
        s = await api<Session>("/desk", { method: "POST", body: JSON.stringify({ door: door ?? "idea", locale, platform }) });
        try {
          localStorage.setItem(STORE, s.id);
        } catch {}
      }
      setSession({ ...s, messages: [...s.messages, { id: -1, role: "customer", body }] });
      setText("");
      setPicked({});
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

  /** The tapped answers and the typed text become one message. */
  function sendAnswers() {
    const answers = questions.flatMap((q, i) => (picked[i] ? [`${q.q} ${picked[i]}`] : []));
    send([...answers, text.trim()].filter(Boolean).join("\n"));
  }

  /** The customer adds or removes a part; the API prices it, no model call. */
  async function togglePart(key: string, on: boolean) {
    if (!session?.scope || busy) return;
    const current = session.scope.modules.map((m) => ({ key: m.key, qty: m.qty }));
    const modules = on ? [...current, { key, qty: 1 }] : current.filter((m) => m.key !== key);
    try {
      setSession(await api<Session>(`/desk/${session.id}/modules`, { method: "POST", body: JSON.stringify({ modules }) }));
    } catch {
      setError(t.unavailable);
    }
  }

  function reset() {
    try {
      localStorage.removeItem(STORE);
    } catch {}
    setSession(null);
    setDoor(platform === "chrome" ? "idea" : null);
    setPicked({});
    setError(null);
  }

  const canSend = !busy && (text.trim().length >= 2 || Object.values(picked).some(Boolean));
  const price = session?.price ?? null;
  const total = (price?.build_eur ?? 0) + Object.entries(price?.launch ?? {}).reduce((s, [k, l]) => s + (launch[k] ? l.eur : 0), 0);

  return (
    <div className="dk">
      <section className="dk-main">
        <div className="dk-card dk-head">
          <div>
            <p className="dk-eyebrow">{t.eyebrow}</p>
            <h1>{t.title}</h1>
            <p className="dk-lede">{t.lede}</p>
          </div>
          <DeskArt />
        </div>

        <div className="dk-card dk-body" aria-live="polite">
          <div className="dk-platforms" role="tablist">
            {OFFERED.map((p) => (
              <button
                key={p}
                type="button"
                role="tab"
                aria-selected={platform === p}
                className={`dk-platform${platform === p ? " is-on" : ""}`}
                disabled={!!session}
                onClick={() => {
                  setPlatform(p);
                  setCatalog([]);
                  setDoor(p === "chrome" ? "idea" : null);
                }}
              >
                <Ico name={p === "chrome" ? "site" : p === "shopify" ? "cart" : "store"} />
                {t.platforms[p]}
              </button>
            ))}
          </div>
          {!door && (
            <>
              <p className="dk-q-title">{t.chooseDoor}</p>
              <div className="dk-options">
                {doors.map((d) => (
                  <button key={d.key} type="button" className="dk-option dk-option-tall" onClick={() => setDoor(d.key as Door)}>
                    <span className="dk-option-ico">
                      <Ico name={d.key === "idea" ? "bulb" : "store"} />
                    </span>
                    <b>{d.h}</b>
                    <small>{d.p}</small>
                  </button>
                ))}
              </div>
            </>
          )}

          {door === "premium" && messages.length === 0 && (
            <div className="dk-cat">
              <p className="dk-q-title">{platform === "shopify" ? t.catalogTitleShopify : t.catalogTitle}</p>
              <p className="dk-muted">{t.catalogLede}</p>
              <div className="dk-cat-grid">
                {catalog.map((c) => (
                  <button key={c.id} type="button" className="dk-option dk-cat-item" disabled={busy} onClick={() => send(fill(t.pick, { name: c.name }))}>
                    <small className="dk-cat-cat">{c.category}</small>
                    <b>{c.name}</b>
                    <small>{fill(t.perYear, { price: c.price })}</small>
                    {c.installs ? <small>{fill(t.installs, { n: num(c.installs) })}</small> : null}
                    {c.reviews ? <small>{fill(t.reviews, { n: num(c.reviews) })}</small> : null}
                    <span className="dk-cat-own">{fill(t.ownFrom, { price: eur(c.own_from_eur) })}</span>
                  </button>
                ))}
              </div>
            </div>
          )}

          {earlier.length > 0 && (
            <details className="dk-earlier">
              <summary>{t.exchanges}</summary>
              {earlier.map((x) => (
                <div key={x.ask.id} className="dk-earlier-item">
                  {x.ask.body && <b>{x.ask.body}</b>}
                  {x.answer && <p>{x.answer.body}</p>}
                </div>
              ))}
            </details>
          )}

          {current && (
            <div className="dk-answer">
              <span className="dk-answer-ico">
                <Ico name="bulb" />
              </span>
              <div>
                {current.ask.body && <h2>{current.ask.body}</h2>}
                {current.answer ? <p>{current.answer.body}</p> : <p className="dk-thinking">{t.thinking}</p>}
              </div>
            </div>
          )}

          {session?.scope && (
            <div className="dk-scope">
              <div className="dk-card-head">
                <h3>{t.scopeTitle}</h3>
                {session.scope.name && <span className="dk-pill">{session.scope.name}</span>}
              </div>
              {session.scope.purpose && <p className="dk-purpose">{session.scope.purpose}</p>}
              <ul className="dk-features">
                {session.scope.features.map((f) => (
                  <li key={f}>
                    <Ico name="done" className="dk-feat-ico" />
                    {f}
                  </li>
                ))}
              </ul>
              {session.scope.not_included.length > 0 && (
                <div className="dk-out">
                  <b>{t.notIncluded}</b>
                  <ul>
                    {session.scope.not_included.map((f) => (
                      <li key={f}>
                        <Ico name="x" className="dk-out-ico" />
                        {f}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {session.scope.requires?.wordpress ? (
                <p className="dk-small">
                  {fill(t.requires, { wp: session.scope.requires.wordpress, php: session.scope.requires.php })}
                  {session.scope.requires.woocommerce ? ` · ${t.requiresWoo}` : ""}
                </p>
              ) : null}
            </div>
          )}

          {questions.map((q, i) => (
            <div key={q.q} className="dk-q">
              <p className="dk-q-title">
                <span className="dk-num">{i + 1}</span>
                {q.q}
              </p>
              <div className="dk-options">
                {q.options.map((o) => {
                  const on = picked[i] === o;
                  return (
                    <button key={o} type="button" className={`dk-option${on ? " is-on" : ""}`} aria-pressed={on} onClick={() => setPicked({ ...picked, [i]: on ? "" : o })}>
                      <span className="dk-option-ico">
                        <Ico name={optionIcon(o)} />
                      </span>
                      <span className="dk-option-label">{o}</span>
                      <span className="dk-radio" aria-hidden="true">
                        {on && <Ico name="check" className="dk-radio-tick" />}
                      </span>
                    </button>
                  );
                })}
              </div>
            </div>
          ))}

          {error && <p className="dk-error">{error}</p>}

          {door && (
            <form
              className="dk-compose"
              onSubmit={(e) => {
                e.preventDefault();
                sendAnswers();
              }}
            >
              <div className="dk-textarea">
                <textarea
                  value={text}
                  onChange={(e) => setText(e.target.value.slice(0, MAX))}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && !e.shiftKey) {
                      e.preventDefault();
                      if (canSend) sendAnswers();
                    }
                  }}
                  placeholder={
                    door === "premium" || messages.length
                      ? platform === "shopify" ? t.placeholderPremiumShopify : t.placeholderPremium
                      : platform === "chrome" ? t.placeholderChrome : platform === "shopify" ? t.placeholderShopify : t.placeholder
                  }
                  rows={3}
                />
                <small>
                  {text.length} / {MAX}
                </small>
              </div>
              <div className="dk-actions">
                <button type="submit" className="dk-send" disabled={!canSend}>
                  {busy ? t.thinking : t.sendIdea} <Ico name="arrow" />
                </button>
                {session && (
                  <button type="button" className="dk-link" onClick={reset}>
                    {t.newChat}
                  </button>
                )}
              </div>
            </form>
          )}
          <div ref={end} />
        </div>
      </section>

      <aside className="dk-side">
        {!(price && session?.scope) && (
          <div className="dk-card">
            <div className="dk-card-head">
              <h3>{t.priceTitle}</h3>
            </div>
            <p className="dk-muted">{t.priceEmpty}</p>
          </div>
        )}
        {price && session?.scope && (
          <div className="dk-card dk-pricecard">
            <div className="dk-card-head">
              <h3>{t.priceTitle}</h3>
              <span className="dk-pill dk-pill-grey">EUR</span>
            </div>
            <p className="dk-sub">{t.parts}</p>
            <p className="dk-hint">{t.partsHint}</p>
            <div className="dk-parts">
              {price.lines.map((l) => (
                <label key={l.key} className={`dk-part is-on${l.key === "base" ? " is-fixed" : ""}`}>
                  <input type="checkbox" checked disabled={l.key === "base" || busy} onChange={() => togglePart(l.key, false)} />
                  <span>
                    {l.label}
                    {l.qty > 1 ? ` × ${l.qty}` : ""}
                  </span>
                  <b>{eur(l.eur)}</b>
                </label>
              ))}
              {more &&
                (session.module_options ?? [])
                  .filter((o) => !price.lines.some((l) => l.key === o.key))
                  .map((o) => (
                    <label key={o.key} className="dk-part">
                      <input type="checkbox" checked={false} disabled={busy} onChange={() => togglePart(o.key, true)} />
                      <span>{o.label}</span>
                      <b>+{eur(o.eur)}</b>
                    </label>
                  ))}
            </div>
            <button type="button" className="dk-more" onClick={() => setMore(!more)}>
              {more ? t.showLess : `+ ${t.addMore}`}
            </button>
            <div className="dk-subtotal">
              <span>{t.build}</span>
              <b>{eur(price.build_eur)}</b>
            </div>
            <p className="dk-sub dk-sub-launch">
              <Ico name="rocket" className="dk-launch-ico" />
              {t.launchTitle}
            </p>
            <div className="dk-parts">
              {Object.entries(price.launch).map(([k, l]) => (
                <label key={k} className={`dk-part${launch[k] ? " is-on" : ""}`}>
                  <input type="checkbox" checked={!!launch[k]} onChange={(e) => setLaunch({ ...launch, [k]: e.target.checked })} />
                  <span>{l.label}</span>
                  <b>+{eur(l.eur)}</b>
                </label>
              ))}
            </div>
            <div className="dk-total">
              <span>{t.total}</span>
              <b>{eur(total)}</b>
            </div>
            <div className="dk-meta">
              <span>
                <Ico name="clock" className="dk-meta-ico" />
                {fill(t.delivery, { lo: price.delivery_days[0], hi: price.delivery_days[1] })}
              </span>
              <span>
                <Ico name="wrench" className="dk-meta-ico" />
                {fill(t.care, { price: eur(price.care_monthly_eur) })}
              </span>
            </div>
            {price.too_big && <p className="dk-error">{t.tooBig}</p>}
          </div>
        )}

        {session?.ready && price && !price.too_big && (
          <div className="dk-card dk-order">
            <p className="dk-ready">
              <Ico name="done" className="dk-feat-ico" />
              {t.ready}
            </p>
            <SbCheckout sessionId={session.id} total={total} picked={launch} care={{ monthly: price.care_monthly_eur, trialMonths: price.care_trial_months ?? 3 }} t={t.checkout} locale={locale} />
          </div>
        )}

        {(session?.research.length ?? 0) > 0 && (
          <div className="dk-card">
            <div className="dk-card-head">
              <h3>{t.research}</h3>
              <Ico name="ext" className="dk-meta-ico" />
            </div>
            <ul className="dk-research">
              {session!.research.map((r) => (
                <li key={r.slug}>
                  <a href={r.url} target="_blank" rel="noopener noreferrer">
                    {r.name}
                  </a>
                  <small>{fill(t.researchMeta, { n: num(r.installs), r: r.rating })}</small>
                </li>
              ))}
            </ul>
          </div>
        )}
        <p className="dk-small dk-mail">
          {t.mail} <a href="mailto:developerweb@codemenschen.at">developerweb@codemenschen.at</a>
        </p>
      </aside>
    </div>
  );
}
