"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import { getToken } from "@/lib/token";
import { trackOnce } from "@/lib/analytics";
import { forget, rename } from "@/lib/history";
import type { Dict, Locale } from "@/lib/i18n";

interface Meta {
  kind?: string;
  /** How an ad was made: hybrid, claude, or codex alone (two steps, a minute or two). */
  mode?: string | null;
  photo_credit?: string | null;
  photo_credit_url?: string | null;
  id: string;
  status: "queued" | "building" | "ready" | "failed" | "expired";
  /** Which step a build is on: writing, auditing, repairing, photos. */
  stage?: string | null;
  created_at?: string;
  title: string | null;
  error: string | null;
  /** The one free change: how many are left, and whether the last one failed (and was given back). */
  revisions_left?: number;
  revision_failed?: boolean;
}

/**
 * The share page. Polls while the prototype builds, then shows it.
 *
 * The generated HTML is untrusted, so it is loaded from the API origin (api.appwerk, not this
 * origin) inside a sandbox that allows scripts but NOT same-origin. That gives the page a null
 * origin: its scripts run so the prototype feels alive, but they cannot reach this site's cookies
 * or localStorage. The API also sends a CSP that blocks every external request.
 */
/**
 * The steps in the order they run, with how much of a typical build each one is and how long it
 * usually takes. Measured on real builds (2026-09-10): study 90 to 130 s, writing 190 to 260 s,
 * audit about 12 s, a repair round 40 to 115 s, photos 2 s. The share is what the bar shows;
 * the seconds decide how fast it creeps within a step. It never reaches a step's end on its own:
 * the last few percent belong to the stage change, so a slow build slows the bar, it does not
 * lie and then stall at 100.
 */
const STEPS = [
  { key: "studying", share: 0.24, seconds: 110 },
  { key: "writing", share: 0.52, seconds: 220 },
  { key: "auditing", share: 0.06, seconds: 15 },
  { key: "repairing", share: 0.15, seconds: 90 },
  { key: "photos", share: 0.03, seconds: 5 },
] as const;

/** An ad by Codex alone: the website is read, then Codex renders both creatives at once (about 50 s). */
const CODEX_STEPS = [
  { key: "studying", share: 0.2, seconds: 25 },
  { key: "rendering", share: 0.8, seconds: 60 },
] as const;

type StepKey = (typeof STEPS)[number]["key"] | (typeof CODEX_STEPS)[number]["key"];
type Step = { key: StepKey; share: number; seconds: number };

/** Percent done, from the step the build is on and how long it has been on it. */
function progress(steps: readonly Step[], stage: StepKey | null, inStage: number): number {
  if (!stage) return 2;
  let done = 0;
  for (const s of steps) {
    if (s.key === stage) {
      return Math.round((done + s.share * Math.min(0.92, inStage / s.seconds)) * 100);
    }
    done += s.share;
  }

  return Math.round(done * 100);
}

const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`;

export function PrototypeView({ id, locale, d }: { id: string; locale: Locale; d: Dict }) {
  const p = d.proto;
  const [meta, setMeta] = useState<Meta | null>(null);
  // A one-second clock while it builds. The stage's own start is noted when the stage is first
  // seen: the API says which step, the page remembers since when.
  const [now, setNow] = useState(() => Date.now());
  const [stageSince, setStageSince] = useState<{ stage: string | null; at: number } | null>(null);
  // Bumped when a change is sent, so the polling starts again for the rebuild.
  const [round, setRound] = useState(0);

  useEffect(() => {
    let stop = false;
    const tick = async () => {
      try {
        const m = await api<Meta>(`/prototypes/${id}`);
        if (stop) return;
        setMeta(m);
        const at = Date.now();
        setStageSince((prev) => (prev && prev.stage === (m.stage ?? null) ? prev : { stage: m.stage ?? null, at }));
        // The list in the visitor's browser only knows the sentence they typed. The build knows
        // what it called itself, and an expired one has nothing left to open.
        if (m.status === "ready") rename(id, m.title);
        if (m.status === "expired") forget(id);
        if (m.status === "queued" || m.status === "building") setTimeout(tick, 3000);
      } catch {
        if (!stop) setMeta({ id, status: "failed", title: null, error: null });
      }
    };
    tick();
    return () => {
      stop = true;
    };
  }, [id, round]);

  const building = meta?.status === "queued" || meta?.status === "building";
  const readyKind = meta?.status === "ready" ? (meta.kind ?? "site") : null;
  useEffect(() => {
    if (readyKind) trackOnce(`proto-view-${id}`, "prototype_view", { kind: readyKind });
  }, [readyKind, id]);
  useEffect(() => {
    if (!building) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [building]);

  if (!meta) return <p className="est-empty">{p.building}</p>;

  if (building && meta.stage === "revising") {
    const since = Math.max(0, Math.floor((now - (stageSince?.at ?? now)) / 1000));

    return (
      <div aria-live="polite" style={{ maxWidth: 560 }}>
        <p className="est-empty" style={{ marginBottom: 6 }}>{p.revise.working}</p>
        <p className="small muted" style={{ margin: 0 }}>{p.revise.elapsed.replace("{t}", clock(since))}</p>
      </div>
    );
  }

  if (building) {
    // "One moment" for four minutes reads as broken, and one line of text for four minutes reads
    // as stuck. The visitor is watching: they get the steps, the one it is on, a bar that moves
    // every second and a clock, so a long build looks like a long build and not like a dead page.
    const codex = meta.mode === "codex";
    const steps: readonly Step[] = codex ? CODEX_STEPS : STEPS;
    const labels = codex ? p.codexStages : p.stages;
    const names = codex ? p.codexSteps : p.steps;
    const key = meta.stage && meta.stage in labels ? (meta.stage as StepKey) : null;
    const stage = key ? labels[key as keyof typeof labels] : null;
    const started = meta.created_at ? Date.parse(meta.created_at) : (stageSince?.at ?? now);
    const elapsed = Math.max(0, Math.floor((now - started) / 1000));
    const inStage = Math.max(0, (now - (stageSince?.at ?? now)) / 1000);
    const pct = progress(steps, key, inStage);
    const at = key ? steps.findIndex((s) => s.key === key) : -1;

    return (
      <div aria-live="polite" style={{ maxWidth: 560 }}>
        <p className="est-empty" style={{ marginBottom: 6 }}>{p.building}</p>
        <div
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={pct}
          style={{ height: 8, borderRadius: 999, background: "var(--border)", overflow: "hidden" }}
        >
          <div
            style={{
              width: `${pct}%`,
              height: "100%",
              background: "var(--accent, #2f4bd6)",
              transition: "width 1s linear",
            }}
          />
        </div>
        <p className="small muted" style={{ margin: "6px 0 14px" }}>
          {pct}% · {(codex ? p.codexElapsed : p.elapsed).replace("{t}", clock(elapsed))}
        </p>
        <ol style={{ listStyle: "none", margin: 0, padding: 0, display: "grid", gap: 6 }}>
          {steps.map((s, i) => {
            const state = at < 0 ? "todo" : i < at ? "done" : i === at ? "now" : "todo";

            return (
              <li
                key={s.key}
                style={{
                  display: "flex",
                  gap: 10,
                  alignItems: "baseline",
                  opacity: state === "todo" ? 0.45 : 1,
                  fontWeight: state === "now" ? 600 : 400,
                }}
              >
                <span style={{ width: 18, textAlign: "center" }} aria-hidden="true">
                  {state === "done" ? "✓" : state === "now" ? "●" : "○"}
                </span>
                <span>
                  {names[s.key as keyof typeof names]}
                  {state === "now" && stage && (
                    <span className="small muted" style={{ display: "block", fontWeight: 400 }}>{stage}</span>
                  )}
                </span>
              </li>
            );
          })}
        </ol>
      </div>
    );
  }
  if (meta.status === "expired") return <p className="est-empty">{p.expired}</p>;
  if (meta.status === "failed") {
    return (
      <div>
        <p className="est-empty">
          {meta.error?.startsWith("site-unreadable: ")
            ? p.siteUnreadable.replace("{domain}", meta.error.slice("site-unreadable: ".length))
            : p.failed}
        </p>
        <Link href={`/${locale}/prototype`}>{p.another}</Link>
      </div>
    );
  }

  return (
    <div>
      <div
        style={{
          display: "flex",
          gap: 12,
          flexWrap: "wrap",
          alignItems: "center",
          justifyContent: "space-between",
          marginBottom: 16,
        }}
      >
        <p className="est-empty" style={{ margin: 0 }}>
          {p.shareHint}
        </p>
        <div style={{ display: "flex", gap: 12 }}>
          <Link className="lang-toggle" href={`/${locale}/create?from=${id}`} onClick={() => trackOnce(`make-real-${id}`, "cta_click", { cta: "prototype_make_real", kind: meta.kind ?? null })}>
            {p.makeReal[(meta.kind ?? "site") as keyof typeof p.makeReal] ?? p.makeReal.site}
          </Link>
          <Link className="lang-toggle" href={`/${locale}/prototype`} onClick={() => trackOnce(`another-${id}`, "cta_click", { cta: "prototype_another" })}>
            {p.another}
          </Link>
        </div>
      </div>
      {/* An app is shown in a phone and a website in a window. Squeezing a 1120px landing page
          into 390px would be as wrong as hanging one app screen across a desktop. */}
      {meta.kind === "app" ? (
        <div className="device-stage">
          <div>
            <div className="device">
              <iframe
                title={meta.title ?? "Prototype"}
                src={`${API_BASE}/api/prototypes/${id}/raw`}
                sandbox="allow-scripts allow-popups"
              />
            </div>
            {/* Under the phone, not inside it: a credit belongs to the page, not to the mockup. */}
            {meta.photo_credit && (
              <p className="small muted" style={{ textAlign: "center", marginTop: 12 }}>
                Foto: {meta.photo_credit}
                {meta.photo_credit_url && (
                  <>
                    {" · "}
                    <a href={meta.photo_credit_url} target="_blank" rel="noopener">
                      Pexels
                    </a>
                  </>
                )}
              </p>
            )}
          </div>
        </div>
      ) : (
        <div>
          <iframe
            title={meta.title ?? "Prototype"}
            src={`${API_BASE}/api/prototypes/${id}/raw`}
            sandbox="allow-scripts allow-popups"
            style={{ width: "100%", height: "80vh", border: "1px solid rgba(0,0,0,.12)", borderRadius: 12 }}
          />
          {/* A website and the ads carry photographs too, and Pexels asks for the credit wherever
              their picture is shown. It went missing here when only the app had pictures. */}
          {meta.photo_credit && (
            <p className="small muted" style={{ marginTop: 10 }}>
              Foto: {meta.photo_credit}
              {meta.photo_credit_url && (
                <>
                  {" · "}
                  <a href={meta.photo_credit_url} target="_blank" rel="noopener">
                    Pexels
                  </a>
                </>
              )}
            </p>
          )}
        </div>
      )}
      <RevisePanel id={id} locale={locale} d={d} meta={meta} onSent={() => {
        setMeta({ ...meta, status: "building", stage: "revising" });
        setRound((r) => r + 1);
      }} />
    </div>
  );
}

/**
 * The one free change. A visitor who is not signed in is asked for an e-mail first and comes
 * back to this page from the link; one who is signed in writes what should change.
 */
function RevisePanel({ id, locale, d, meta, onSent }: { id: string; locale: Locale; d: Dict; meta: Meta; onSent: () => void }) {
  const r = d.proto.revise;
  const [token, setTokenState] = useState<string | null>(null);
  const [change, setChange] = useState("");
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // eslint-disable-next-line react-hooks/set-state-in-effect -- the token lives in localStorage, readable only after hydration
  useEffect(() => setTokenState(getToken()), []);

  if ((meta.revisions_left ?? 1) < 1) {
    return <p className="small muted" style={{ marginTop: 16 }}>{r.used}</p>;
  }

  async function sendLink() {
    try {
      localStorage.setItem("aifactory-next", `/${locale}/p/${id}`);
    } catch {}
    try {
      await api("/auth/magic-link", { method: "POST", body: JSON.stringify({ email, locale, join: true }) });
      setSent(true);
    } catch {
      setError(d.proto.failed);
    }
  }

  async function send() {
    setBusy(true);
    setError(null);
    try {
      await api(`/prototypes/${id}/revise`, { method: "POST", token: token ?? undefined, body: JSON.stringify({ change }) });
      onSent();
    } catch (err) {
      const status = err && typeof err === "object" && "status" in err ? (err as { status: number }).status : 0;
      const code = err && typeof err === "object" && "body" in err ? (err as { body: { code?: string } | null }).body?.code : undefined;
      setError(code === "used" ? r.used : code === "not_yours" ? r.notYours : status === 401 ? r.signIn : d.proto.failed);
      setBusy(false);
    }
  }

  return (
    <div className="card" style={{ marginTop: 20, display: "grid", gap: 10, maxWidth: 640 }}>
      <strong>{r.title}</strong>
      <p className="small muted" style={{ margin: 0 }}>{r.hint}</p>
      {meta.revision_failed && <p className="est-empty" style={{ margin: 0 }}>{r.failed}</p>}
      {token ? (
        <>
          <textarea
            value={change}
            onChange={(e) => setChange(e.target.value.slice(0, 1000))}
            rows={3}
            placeholder={r.placeholder}
            aria-label={r.title}
            style={{ width: "100%", fontSize: "1rem", padding: 10 }}
          />
          <button type="button" onClick={send} disabled={busy || change.trim().length < 5} style={{ justifySelf: "start" }}>
            {r.send}
          </button>
        </>
      ) : sent ? (
        <p style={{ margin: 0 }}>{r.linkSent}</p>
      ) : (
        <>
          <p style={{ margin: 0 }}>{r.signIn}</p>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={d.proto.signIn.email}
              aria-label={d.proto.signIn.email}
              style={{ flex: "1 1 220px", padding: 10, fontSize: "1rem" }}
            />
            <button type="button" onClick={sendLink} disabled={!/.+@.+\..+/.test(email)}>
              {d.proto.signIn.send}
            </button>
          </div>
        </>
      )}
      {error && <p className="est-empty" style={{ margin: 0 }}>{error}</p>}
    </div>
  );
}
