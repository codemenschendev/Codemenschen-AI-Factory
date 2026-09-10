"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import { forget, rename } from "@/lib/history";
import type { Dict, Locale } from "@/lib/i18n";

interface Meta {
  kind?: string;
  photo_credit?: string | null;
  photo_credit_url?: string | null;
  id: string;
  status: "queued" | "building" | "ready" | "failed" | "expired";
  /** Which step a build is on: writing, auditing, repairing, photos. */
  stage?: string | null;
  created_at?: string;
  title: string | null;
  error: string | null;
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

type StepKey = (typeof STEPS)[number]["key"];

/** Percent done, from the step the build is on and how long it has been on it. */
function progress(stage: StepKey | null, inStage: number): number {
  if (!stage) return 2;
  let done = 0;
  for (const s of STEPS) {
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
  }, [id]);

  const building = meta?.status === "queued" || meta?.status === "building";
  useEffect(() => {
    if (!building) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [building]);

  if (!meta) return <p className="est-empty">{p.building}</p>;

  if (building) {
    // "One moment" for four minutes reads as broken, and one line of text for four minutes reads
    // as stuck. The visitor is watching: they get the steps, the one it is on, a bar that moves
    // every second and a clock, so a long build looks like a long build and not like a dead page.
    const key = meta.stage && meta.stage in p.stages ? (meta.stage as StepKey) : null;
    const stage = key ? p.stages[key] : null;
    const started = meta.created_at ? Date.parse(meta.created_at) : (stageSince?.at ?? now);
    const elapsed = Math.max(0, Math.floor((now - started) / 1000));
    const inStage = Math.max(0, (now - (stageSince?.at ?? now)) / 1000);
    const pct = progress(key, inStage);
    const at = key ? STEPS.findIndex((s) => s.key === key) : -1;

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
          {pct}% · {p.elapsed.replace("{t}", clock(elapsed))}
        </p>
        <ol style={{ listStyle: "none", margin: 0, padding: 0, display: "grid", gap: 6 }}>
          {STEPS.map((s, i) => {
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
                  {p.steps[s.key]}
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
        <p className="est-empty">{p.failed}</p>
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
          <Link className="lang-toggle" href={`/${locale}/create?from=${id}`}>
            {p.makeReal}
          </Link>
          <Link className="lang-toggle" href={`/${locale}/prototype`}>
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
    </div>
  );
}
