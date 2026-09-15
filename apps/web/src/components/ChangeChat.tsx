"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { api, ApiError } from "@/lib/api";
import { eur, type Dict, type Locale } from "@/lib/i18n";

/** One line of the thread, as GET /me/projects/{id}/messages returns it. */
export interface ChatMessage {
  id: number;
  role: "customer" | "assistant" | "system" | "operator";
  body: string;
  change_request_id: number | null;
  created_at: string;
  meta: {
    type?: "card" | "declined" | "limit" | "payment" | "paid" | "started" | "result" | "failed";
    questions?: { q: string; options: string[] }[];
    card?: { items: { text: string }[]; mode: "free" | "paid" | "care"; round: number; price_eur: number; free_rounds_left: number };
    confirmed?: boolean;
    checkout_url?: string | null;
    reason?: string;
    summary?: string;
    items?: { text: string; done: boolean; note: string }[];
    preview_url?: string | null;
  };
}

export interface ChatProject {
  id: string;
  status: string;
  preview_url?: string | null;
  change_request_mode?: "free" | "paid" | "care" | "none";
  runs: { stage: string; attempt: number; status: string; started_at: string | null; finished_at: string | null }[];
  change_requests?: { id: number; status: string }[];
}

const WORKING = ["FIXING", "TESTING"];
const STEP_STAGES = ["revise", "test", "release"] as const;

/**
 * The change chat on the project page (docs/specs/change-chat.md). The customer talks, the
 * assistant asks back and writes a checklist, "Umsetzen" starts the round. What the pipeline does
 * next arrives as system messages; while a round runs, its steps are read from the project's runs.
 */
export function ChangeChat({
  locale,
  d,
  token,
  project,
  onChanged,
  onApprove,
}: {
  locale: Locale;
  d: Dict;
  token: string;
  project: ChatProject;
  onChanged: () => void;
  onApprove: () => void;
}) {
  const t = d.project.chat;
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState<string | null>(null); // the text on its way, shown at once
  const [notice, setNotice] = useState<string | null>(null);
  const [waiver, setWaiver] = useState(false); // FAGG § 18 for a paid round, never pre-ticked
  const [confirming, setConfirming] = useState(false);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const threadRef = useRef<HTMLDivElement>(null);
  const lastId = messages.length ? messages[messages.length - 1].id : 0;

  const merge = useCallback((incoming: ChatMessage[]) => {
    setMessages((old) => {
      const byId = new Map(old.map((m) => [m.id, m]));
      incoming.forEach((m) => byId.set(m.id, m));
      return [...byId.values()].sort((a, b) => a.id - b.id);
    });
  }, []);

  const load = useCallback(
    () =>
      api<{ messages: ChatMessage[] }>(`/me/projects/${project.id}/messages`, { token })
        .then((res) => merge(res.messages))
        .catch(() => {
          /* the next poll tries again */
        }),
    [project.id, token, merge],
  );

  useEffect(() => {
    load();
  }, [load]);

  // Fast while a round runs, slow otherwise. A reply from the team can arrive at any time.
  const working = WORKING.includes(project.status);
  useEffect(() => {
    const timer = setInterval(() => void load(), working ? 5_000 : 15_000);
    return () => clearInterval(timer);
  }, [load, working]);

  // Project status (and with it the progress steps) follows the thread: a new system line means
  // the pipeline moved, so the page reloads the project too.
  const lastSystem = [...messages].reverse().find((m) => m.role === "system")?.id ?? 0;
  useEffect(() => {
    if (lastSystem) onChanged();
    // onChanged is a fresh closure each render; the id is the signal
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastSystem]);

  // The thread scrolls inside its own box, to the newest line; the page itself stays where it is.
  useEffect(() => {
    const box = threadRef.current;
    if (box) box.scrollTop = box.scrollHeight;
  }, [lastId, sending]);

  const send = async (body: string) => {
    const text = body.trim();
    if (!text || sending) return;
    setSending(text);
    setDraft("");
    setNotice(null);
    try {
      const res = await api<{ messages: ChatMessage[] }>(`/me/projects/${project.id}/messages`, {
        method: "POST",
        token,
        body: JSON.stringify({ body: text }),
      });
      merge(res.messages);
    } catch (e) {
      const saved = e instanceof ApiError && e.status === 503 ? (e.body as { messages?: ChatMessage[] } | null)?.messages : null;
      if (saved) merge(saved);
      else setDraft(text); // not stored: give the text back
      setNotice(t.unavailable);
    } finally {
      setSending(null);
    }
  };

  const confirm = async () => {
    setConfirming(true);
    setNotice(null);
    try {
      const res = await api<{ checkout_url: string | null }>(`/me/projects/${project.id}/messages/confirm`, {
        method: "POST",
        token,
        body: JSON.stringify({ fagg_waiver: waiver }),
      });
      if (res.checkout_url) {
        window.location.href = res.checkout_url;
        return;
      }
      setWaiver(false);
      await load();
      onChanged();
    } catch (e) {
      setNotice(e instanceof ApiError && e.status === 503 ? d.checkout.staging : d.checkout.errors.generic);
      await load();
    } finally {
      setConfirming(false);
    }
  };

  // Only the newest card, with nothing said after it, can be confirmed.
  const talk = messages.filter((m) => m.role === "customer" || m.role === "assistant");
  const openCardId = (() => {
    const last = talk[talk.length - 1];
    return last?.meta.type === "card" && !last.meta.confirmed && !last.change_request_id ? last.id : null;
  })();
  const roundRunning = project.change_requests?.some((c) => c.status === "in_progress") ?? false;
  // The steps hang under the line that started the work: "started" for a free round, "paid" for a
  // paid one. Only the newest, and only until that round has its result.
  const progressId = (() => {
    const start = [...messages].reverse().find((m) => m.meta.type === "started" || m.meta.type === "paid");
    if (!start) return null;
    const ended = messages.some((m) => m.id > start.id && ["result", "failed", "declined"].includes(m.meta.type ?? ""));
    return ended ? null : start.id;
  })();
  const mode = project.change_request_mode ?? "none";

  return (
    <div className="card chat">
      <h3>{t.title}</h3>
      {messages.length === 0 && <p className="small muted" style={{ margin: 0 }}>{t.intro}</p>}

      <div className="chat-thread" aria-live="polite" ref={threadRef}>
        {messages.map((m) => (
          <Message
            key={m.id}
            m={m}
            d={d}
            locale={locale}
            project={project}
            open={m.id === openCardId}
            paid={messages.some((x) => x.meta.type === "paid" && x.change_request_id === m.change_request_id)}
            showProgress={m.id === progressId}
            latest={m.id === talk[talk.length - 1]?.id}
            waiver={waiver}
            setWaiver={setWaiver}
            confirming={confirming || roundRunning}
            onConfirm={confirm}
            onChangeSomething={() => inputRef.current?.focus()}
            onPick={(o) => void send(o)}
            onApprove={onApprove}
            sending={!!sending}
          />
        ))}
        {sending && (
          <>
            <div className="chat-msg chat-customer">
              <span className="chat-who">{t.you}</span>
              <div className="chat-bubble"><p style={{ margin: 0, whiteSpace: "pre-wrap" }}>{sending}</p></div>
            </div>
            <p className="small muted chat-thinking">{t.thinking}</p>
          </>
        )}
      </div>

      {roundRunning && <p className="small muted" style={{ margin: 0 }}>{t.busy}</p>}
      {!roundRunning && mode === "none" && <p className="small muted" style={{ margin: 0 }}>{t.none}</p>}
      {notice && <p className="note" style={{ margin: 0 }}>{notice}</p>}

      <form
        className="chat-compose"
        onSubmit={(e) => {
          e.preventDefault();
          void send(draft);
        }}
      >
        <textarea
          ref={inputRef}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && !e.shiftKey) {
              e.preventDefault();
              void send(draft);
            }
          }}
          placeholder={t.placeholder}
          rows={2}
          maxLength={2000}
        />
        <button className="btn btn-primary" type="submit" disabled={!!sending || !draft.trim()}>
          {sending ? t.sending : t.send}
        </button>
      </form>
    </div>
  );
}

function Message({
  m,
  d,
  locale,
  project,
  open,
  paid,
  showProgress,
  latest,
  waiver,
  setWaiver,
  confirming,
  onConfirm,
  onChangeSomething,
  onPick,
  onApprove,
  sending,
}: {
  m: ChatMessage;
  d: Dict;
  locale: Locale;
  project: ChatProject;
  open: boolean;
  paid: boolean;
  showProgress: boolean;
  latest: boolean;
  waiver: boolean;
  setWaiver: (v: boolean) => void;
  confirming: boolean;
  onConfirm: () => void;
  onChangeSomething: () => void;
  onPick: (option: string) => void;
  onApprove: () => void;
  sending: boolean;
}) {
  const t = d.project.chat;
  const who = m.role === "customer" ? t.you : m.role === "operator" ? t.team : t.assistant;

  if (m.role === "system") {
    return (
      <div className={`chat-system${m.meta.type === "failed" || m.meta.type === "limit" ? " chat-system-warn" : ""}`}>
        <p style={{ margin: 0 }}>{m.body}</p>
        {showProgress && <Progress project={project} since={m.created_at} d={d} />}
        {m.meta.type === "payment" && m.meta.checkout_url && !paid && (
          <a className="btn btn-primary" href={m.meta.checkout_url}>{t.pay}</a>
        )}
        {m.meta.type === "result" && (
          <>
            {!!m.meta.items?.length && (
              <>
                <span className="small muted">{t.resultItems}</span>
                <ul className="chat-checklist">
                  {m.meta.items.map((i, n) => (
                    <li key={n} className={i.done ? "done" : "open"}>
                      <span aria-hidden>{i.done ? "✓" : "○"}</span>
                      <span>
                        {i.text}
                        {!i.done && <em className="muted"> · {t.notDone}</em>}
                        {i.note && <span className="muted small"> ({i.note})</span>}
                      </span>
                    </li>
                  ))}
                </ul>
              </>
            )}
            {!m.meta.items?.length && m.meta.summary && <p className="small" style={{ margin: 0 }}>{m.meta.summary}</p>}
            <div className="chat-actions">
              {m.meta.preview_url && (
                <a className="btn btn-ghost" href={m.meta.preview_url} target="_blank" rel="noopener noreferrer">{t.openPreview}</a>
              )}
              {project.status === "REVIEW" && (
                <button type="button" className="btn btn-primary" onClick={onApprove}>{t.approve}</button>
              )}
              <button type="button" className="btn btn-ghost" onClick={onChangeSomething}>{t.moreChanges}</button>
            </div>
          </>
        )}
        {m.meta.type === "declined" && (
          <>
            {m.meta.reason && <p className="small" style={{ margin: 0 }}>{m.meta.reason}</p>}
            <p className="small muted" style={{ margin: 0 }}>{t.declinedHint}</p>
          </>
        )}
      </div>
    );
  }

  const card = m.meta.type === "card" ? m.meta.card : undefined;
  return (
    <div className={`chat-msg chat-${m.role}`}>
      <span className="chat-who">{who}</span>
      <div className="chat-bubble">
        <p style={{ margin: 0, whiteSpace: "pre-wrap" }}>{m.body}</p>

        {!!m.meta.questions?.length && (
          <Questions questions={m.meta.questions} active={latest && !sending} d={d} onSend={onPick} />
        )}

        {m.meta.type === "declined" && (
          <p className="small" style={{ margin: 0 }}>
            <Link href={`/${locale}/create`}>{t.askQuote}</Link>
          </p>
        )}

        {card && (
          <div className="chat-card">
            <strong>{t.cardTitle.replace("{round}", String(card.round))}</strong>
            <span className="small muted">
              {card.mode === "care"
                ? t.cardCare
                : card.mode === "paid"
                  ? t.cardPaid.replace("{price}", eur(card.price_eur, locale))
                  : t.cardFree.replace("{left}", String(Math.max(0, card.free_rounds_left - 1)))}
            </span>
            <ol className="chat-items">
              {card.items.map((item, n) => <li key={n}>{item.text}</li>)}
            </ol>
            {m.meta.confirmed ? (
              <span className="small" style={{ color: "var(--valid)" }}>✓ {t.confirmed}</span>
            ) : open ? (
              <>
                {card.mode === "paid" && (
                  <label className="choice" style={{ alignItems: "flex-start" }}>
                    <input type="checkbox" checked={waiver} onChange={(e) => setWaiver(e.target.checked)} style={{ marginTop: 4 }} />
                    <span className="small">{d.checkout.waiverLabel}</span>
                  </label>
                )}
                <div className="chat-actions">
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={confirming || (card.mode === "paid" && !waiver)}
                    onClick={onConfirm}
                  >
                    {card.mode === "paid" ? t.confirmPaid : t.confirm}
                  </button>
                  <button type="button" className="btn btn-ghost" onClick={onChangeSomething}>{t.changeSomething}</button>
                </div>
              </>
            ) : (
              <span className="small muted">{t.superseded}</span>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Tap answers. One question sends on the tap; with two, the customer picks both and sends them
 * together, so the assistant does not answer half a reply. Older questions stay visible, inactive.
 */
function Questions({
  questions,
  active,
  d,
  onSend,
}: {
  questions: { q: string; options: string[] }[];
  active: boolean;
  d: Dict;
  onSend: (text: string) => void;
}) {
  const [picked, setPicked] = useState<Record<number, string>>({});
  const single = questions.length === 1;
  const complete = questions.every((_, i) => picked[i]);

  return (
    <>
      {questions.map((q, i) => (
        <div key={i} className="chat-question">
          <p className="small" style={{ margin: "0 0 6px", fontWeight: 600 }}>{q.q}</p>
          <div className="chat-options" role={single ? undefined : "radiogroup"} aria-label={q.q}>
            {q.options.map((o) => (
              <button
                key={o}
                type="button"
                className="chat-option"
                aria-pressed={picked[i] === o}
                disabled={!active}
                onClick={() => (single ? onSend(o) : setPicked((p) => ({ ...p, [i]: o })))}
              >
                {o}
              </button>
            ))}
          </div>
        </div>
      ))}
      {!single && active && (
        <div className="chat-actions">
          <button
            type="button"
            className="btn btn-primary"
            disabled={!complete}
            onClick={() => onSend(questions.map((q, i) => `${q.q} ${picked[i]}`).join("\n"))}
          >
            {d.project.chat.sendAnswers}
          </button>
        </div>
      )}
    </>
  );
}

/** The three steps of a round, read from the pipeline runs that started after it. */
function Progress({ project, since, d }: { project: ChatProject; since: string; d: Dict }) {
  const t = d.project.chat;
  const from = new Date(since).getTime() - 5_000;
  const runs = project.runs.filter((r) => r.started_at && new Date(r.started_at).getTime() >= from);
  if (!runs.length && !WORKING.includes(project.status)) return null;

  const stateOf = (stage: (typeof STEP_STAGES)[number]) => {
    const mine = runs.filter((r) => (stage === "test" ? r.stage === "test" || r.stage === "fix" : r.stage === stage));
    const later = STEP_STAGES.slice(STEP_STAGES.indexOf(stage) + 1);
    if (runs.some((r) => later.includes(r.stage as (typeof STEP_STAGES)[number]))) return "done";
    if (stage === "release" && mine.some((r) => r.status === "succeeded")) return "done";
    if (mine.some((r) => r.status === "running" || r.status === "queued")) return "running";
    if (stage === "revise" && !mine.length && WORKING.includes(project.status)) return "running";
    return mine.some((r) => r.status === "succeeded") ? "done" : "waiting";
  };
  const retry = runs.some((r) => r.stage === "fix");

  return (
    <ol className="chat-steps">
      {STEP_STAGES.map((stage) => {
        const state = stateOf(stage);
        return (
          <li key={stage} className={`chat-step chat-step-${state}`}>
            <span aria-hidden>{state === "done" ? "✓" : state === "running" ? "●" : "○"}</span>
            {t.steps[stage]}
            {stage === "test" && retry && <span className="muted"> ({t.stepRetry})</span>}
          </li>
        );
      })}
    </ol>
  );
}
