"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { API_BASE, api } from "@/lib/api";
import { setToken, useToken } from "@/lib/token";
import { trackOnce } from "@/lib/analytics";
import { forget, rename } from "@/lib/history";
import { eur, type Dict, type Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";

interface Meta {
  kind?: string;
  /** How an ad was made: hybrid, claude, or codex alone (two steps, a minute or two). */
  mode?: string | null;
  photo_credit?: string | null;
  photo_credit_url?: string | null;
  id: string;
  /** waiting: the visitor has not opened the e-mail link yet, and nothing is built until they do. */
  status: "waiting" | "queued" | "building" | "ready" | "failed" | "expired";
  /** Which step a build is on: writing, auditing, repairing, photos. */
  stage?: string | null;
  created_at?: string;
  title: string | null;
  error: string | null;
  /** The one free change: how many are left, and whether the last one failed (and was given back). */
  revisions_left?: number;
  revision_failed?: boolean;
  /** A website preview can be bought as it is: its price, and whether somebody did. */
  site_price_eur?: number | null;
  bought?: boolean;
  live_url?: string | null;
  /** A campaign is its parts: the ad, the landing page and the e-mails, each its own prototype. */
  parts?: Part[] | null;
}

interface Part {
  id: string;
  kind: "ads" | "site" | "email";
  status: Meta["status"];
  stage?: string | null;
  title: string | null;
  mode?: string | null;
  /** The landing page's public address, once its owner switched it on. */
  live_url?: string | null;
}

interface Waitlist {
  url: string | null;
  confirmed: number;
  pending: number;
  signups: { email: string; status: string; source: string | null; created_at: string; confirmed_at: string | null }[];
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

/** One card for every state that is not the finished prototype: waiting, building, failed, expired. */
function StatusCard({ icon, title, tone, children }: { icon: string; title: string; tone?: "bad"; children?: React.ReactNode }) {
  return (
    <div className={`sh-status${tone ? " sh-status-bad" : ""}`} aria-live="polite">
      <span className="sh-status-ico">
        <Icon name={icon} />
      </span>
      <h2>{title}</h2>
      {children}
    </div>
  );
}

/** The build steps as a list: done, the one it is on (with what it does), still to come. */
function StepList({ rows }: { rows: { key: string; label: string; state: "done" | "now" | "todo" | "failed"; note?: string | null }[] }) {
  return (
    <ol className="sh-steps">
      {rows.map((r) => (
        <li key={r.key} data-state={r.state}>
          <span className="sh-dot" aria-hidden="true">
            {r.state === "done" ? "✓" : r.state === "failed" ? "✕" : ""}
          </span>
          <span>
            {r.label}
            {r.note && <small>{r.note}</small>}
          </span>
        </li>
      ))}
    </ol>
  );
}

export function PrototypeView({ id, locale, d, embedded = false }: { id: string; locale: Locale; d: Dict; embedded?: boolean }) {
  const p = d.proto;
  const [meta, setMeta] = useState<Meta | null>(null);
  // A one-second clock while it builds. The stage's own start is noted when the stage is first
  // seen: the API says which step, the page remembers since when.
  const [now, setNow] = useState(() => Date.now());
  const [stageSince, setStageSince] = useState<{ stage: string | null; at: number } | null>(null);
  // Bumped when a change is sent, so the polling starts again for the rebuild.
  const [round, setRound] = useState(0);

  // The link in the e-mail lands here with a token in the hash: the visitor is signed in from now
  // on, and the hash comes off the address so the token is not shared with the link.
  useEffect(() => {
    const fromHash = new URLSearchParams(window.location.hash.slice(1)).get("token");
    if (fromHash) {
      setToken(fromHash);
      history.replaceState(null, "", window.location.pathname);
    }
  }, []);

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
        if (m.status === "waiting") setTimeout(tick, 5000);
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

  if (!meta) return <StatusCard icon="clock" title={p.building} />;

  if (meta.status === "waiting") {
    return (
      <StatusCard icon="mail" title={p.email.waitingTitle}>
        <p>{p.email.waiting}</p>
      </StatusCard>
    );
  }

  if (meta.kind === "campaign" && meta.status !== "expired" && meta.status !== "failed") {
    return <CampaignView meta={meta} locale={locale} d={d} now={now} building={building} />;
  }

  if (building && meta.stage === "revising") {
    const since = Math.max(0, Math.floor((now - (stageSince?.at ?? now)) / 1000));

    return (
      <StatusCard icon="spark" title={p.revise.working}>
        <p>{p.revise.elapsed.replace("{t}", clock(since))}</p>
      </StatusCard>
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
      <StatusCard icon="spark" title={p.building}>
        <div className="sh-bar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct}>
          <span style={{ width: `${pct}%` }} />
        </div>
        <p className="sh-meta">
          {pct}% · {(codex ? p.codexElapsed : p.elapsed).replace("{t}", clock(elapsed))}
        </p>
        <StepList
          rows={steps.map((s, i) => {
            const state = at < 0 ? "todo" : i < at ? "done" : i === at ? "now" : "todo";
            return { key: s.key, label: names[s.key as keyof typeof names], state, note: state === "now" ? stage : null };
          })}
        />
      </StatusCard>
    );
  }
  if (meta.status === "expired") {
    return (
      <StatusCard icon="clock" title={p.expired}>
        <Link className="btn btn-primary" href={`/${locale}/prototype`}>{p.another}</Link>
      </StatusCard>
    );
  }
  if (meta.status === "failed") {
    return (
      <StatusCard icon="bulb" title={p.failed} tone="bad">
        {meta.error?.startsWith("site-unreadable: ") && (
          <p>{p.siteUnreadable.replace("{domain}", meta.error.slice("site-unreadable: ".length))}</p>
        )}
        <Link className="btn btn-primary" href={`/${locale}/prototype`}>{p.another}</Link>
      </StatusCard>
    );
  }

  return (
    <div>
      {!embedded && <div className="sh-toolbar">
        <p>
          {meta.bought ? p.boughtSite : meta.site_price_eur ? p.buySiteHint : p.shareHint}
        </p>
        <div className="sh-actions">
          {meta.bought && meta.live_url && (
            <a className="btn btn-primary" href={meta.live_url} target="_blank" rel="noopener noreferrer">{p.openLive}</a>
          )}
          {!meta.bought && meta.site_price_eur != null && (
            <Link className="btn btn-primary" href={`/${locale}/checkout?site=${id}`} onClick={() => trackOnce(`buy-site-${id}`, "cta_click", { cta: "prototype_buy_site", kind: "site" })}>
              {p.buySite.replace("{price}", eur(meta.site_price_eur, locale))}
            </Link>
          )}
          {!meta.bought && (
            <Link className="btn sh-ghost" href={`/${locale}/create?from=${id}`} onClick={() => trackOnce(`make-real-${id}`, "cta_click", { cta: "prototype_make_real", kind: meta.kind ?? null })}>
              {p.makeReal[(meta.kind ?? "site") as keyof typeof p.makeReal] ?? p.makeReal.site}
            </Link>
          )}
          <Link className="btn sh-ghost" href={`/${locale}/prototype`} onClick={() => trackOnce(`another-${id}`, "cta_click", { cta: "prototype_another" })}>
            {p.another}
          </Link>
        </div>
      </div>}
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
              <p className="sh-credit sh-credit-center">
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
          <div className="sh-frame">
            <iframe
              title={meta.title ?? "Prototype"}
              src={`${API_BASE}/api/prototypes/${id}/raw`}
              sandbox="allow-scripts allow-popups"
            />
          </div>
          {/* A website and the ads carry photographs too, and Pexels asks for the credit wherever
              their picture is shown. It went missing here when only the app had pictures. */}
          {meta.photo_credit && (
            <p className="sh-credit">
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
 * A campaign: while it builds, the three parts and where each one is; once ready, one tab per
 * part, each shown the way a prototype of its kind is shown, with the one change the parts share.
 */
function CampaignView({ meta, locale, d, now, building }: { meta: Meta; locale: Locale; d: Dict; now: number; building: boolean }) {
  const p = d.proto;
  const c = p.campaign;
  const parts = meta.parts ?? [];
  const [tab, setTab] = useState<string | null>(null);

  if (building) {
    const started = meta.created_at ? Date.parse(meta.created_at) : now;
    const elapsed = Math.max(0, Math.floor((now - started) / 1000));
    const rows: { key: string; label: string; state: "done" | "now" | "todo" | "failed"; note?: string }[] = [
      { key: "message", label: c.message, state: parts.length ? "done" : "now", note: parts.length ? undefined : c.messageNow },
      ...(["ads", "site", "email"] as const).map((k) => {
        const part = parts.find((x) => x.kind === k);
        const state = !part || part.status === "queued" ? "todo" : part.status === "building" ? "now" : part.status === "ready" ? "done" : "failed";
        const stages = part?.mode === "codex" ? p.codexStages : p.stages;
        const note = state === "now" && part?.stage && part.stage in stages ? stages[part.stage as keyof typeof stages]
          : state === "failed" ? c.failed : state === "todo" && part ? c.queued : undefined;

        return { key: k, label: c.parts[k], state: state as "done" | "now" | "todo" | "failed", note };
      }),
    ];

    return (
      <StatusCard icon="campaign" title={c.building}>
        <p className="sh-meta">{c.elapsed.replace("{t}", clock(elapsed))}</p>
        <StepList rows={rows} />
      </StatusCard>
    );
  }

  const active = parts.find((x) => x.id === tab) ?? parts.find((x) => x.status === "ready") ?? parts[0];

  return (
    <div>
      <div className="sh-toolbar">
        <p>{c.hint}</p>
        <div className="sh-actions">
          <Link className="btn btn-primary" href={`/${locale}/create?from=${meta.id}`} onClick={() => trackOnce(`make-real-${meta.id}`, "cta_click", { cta: "prototype_make_real", kind: "campaign" })}>
            {p.makeReal.campaign}
          </Link>
          <Link className="btn sh-ghost" href={`/${locale}/prototype`} onClick={() => trackOnce(`another-${meta.id}`, "cta_click", { cta: "prototype_another" })}>
            {p.another}
          </Link>
        </div>
      </div>
      <div role="tablist" className="sh-tabs">
        {parts.map((x, i) => (
          <button
            key={x.id}
            type="button"
            role="tab"
            className="sh-tab"
            aria-selected={active?.id === x.id}
            onClick={() => setTab(x.id)}
          >
            {i + 1}. {c.parts[x.kind]}
            {x.status === "failed" ? " ✕" : ""}
          </button>
        ))}
      </div>
      {active && (active.status === "failed"
        ? <StatusCard icon="bulb" title={c.partFailed} tone="bad" />
        : <PrototypeView key={active.id} id={active.id} locale={locale} d={d} embedded />)}
      {parts.some((x) => x.kind === "site" && x.status === "ready") && (
        <LandingPanel part={parts.find((x) => x.kind === "site")!} d={d} locale={locale} />
      )}
      {parts.some((x) => x.kind === "site" && x.live_url) && <ReportPanel id={meta.id} d={d} locale={locale} />}
      <ValidationPanel id={meta.id} d={d} />
    </div>
  );
}

/**
 * The landing page, live: the owner switches it on, gets the address to put in the ad, and sees
 * who signed up. Only the owner (or an admin) gets past the API; anyone else sees nothing here.
 */
function LandingPanel({ part, d, locale }: { part: Part; d: Dict; locale: Locale }) {
  const w = d.proto.waitlist;
  const token = useToken();
  const [list, setList] = useState<Waitlist | null>(null);
  const [denied, setDenied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!token) return;
    let stop = false;
    const load = () =>
      api<Waitlist>(`/prototypes/${part.id}/signups`, { token })
        .then((l) => !stop && setList(l))
        .catch(() => !stop && setDenied(true));
    load();
    const t = setInterval(load, 30000);
    return () => {
      stop = true;
      clearInterval(t);
    };
  }, [token, part.id]);

  if (!token || denied || !list) return null;

  async function publish() {
    setBusy(true);
    try {
      const r = await api<{ url: string }>(`/prototypes/${part.id}/publish`, { method: "POST", token: token ?? undefined });
      setList((l) => (l ? { ...l, url: r.url } : l));
    } finally {
      setBusy(false);
    }
  }

  function csv() {
    const rows = [["email", "status", "source", "signed_up", "confirmed"], ...list!.signups.map((s) => [s.email, s.status, s.source ?? "", s.created_at, s.confirmed_at ?? ""])];
    const blob = new Blob([rows.map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(",")).join("\n")], { type: "text/csv" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "waitlist.csv";
    a.click();
    URL.revokeObjectURL(a.href);
  }

  const date = (iso: string) => new Date(iso).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB");

  return (
    <div className="card" style={{ marginTop: 20, display: "grid", gap: 10, maxWidth: 640 }}>
      <strong>{w.title}</strong>
      {!list.url ? (
        <>
          <p className="small muted" style={{ margin: 0 }}>{w.hint}</p>
          <button type="button" onClick={publish} disabled={busy} style={{ justifySelf: "start" }}>{w.publish}</button>
        </>
      ) : (
        <>
          <p className="small muted" style={{ margin: 0 }}>{w.live}</p>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
            <a href={list.url} target="_blank" rel="noopener" style={{ wordBreak: "break-all" }}>{list.url}</a>
            <button
              type="button"
              className="tab"
              onClick={() => {
                navigator.clipboard?.writeText(list.url!);
                setCopied(true);
              }}
            >
              {copied ? w.copied : w.copy}
            </button>
          </div>
          <p style={{ margin: 0 }}>
            <strong>{list.confirmed}</strong> {w.confirmed} · {list.pending} {w.pending}
          </p>
          {list.signups.length > 0 && (
            <>
              <ul className="small" style={{ margin: 0, paddingLeft: 18, maxHeight: 220, overflow: "auto" }}>
                {list.signups.map((s) => (
                  <li key={s.email}>
                    {s.email} · {s.status === "confirmed" ? w.confirmedOne : w.pendingOne} · {date(s.created_at)}
                    {s.source ? ` · ${s.source}` : ""}
                  </li>
                ))}
              </ul>
              <button type="button" className="tab" onClick={csv} style={{ justifySelf: "start" }}>{w.csv}</button>
            </>
          )}
        </>
      )}
    </div>
  );
}

interface Report {
  from: string | null;
  running: boolean;
  verdict: "go" | "no_go" | "unclear" | "too_early";
  goals: { rate: number; cpl: number };
  funnel: {
    impressions: number;
    clicks: number;
    ctr: number | null;
    visitors: number;
    visitors_from_ads: number;
    signups: number;
    confirmed: number;
    rate: number | null;
    rate_range: [number, number] | null;
    spend_eur: number;
    cost_per_signup_eur: number | null;
  };
  days: { date: string; visitors: number; signups: number; confirmed: number }[];
}

const VERDICT_COLOR = { go: "#1e8449", no_go: "#c0392b", unclear: "#b9770e", too_early: "#7f8c8d" } as const;

/**
 * The validation report for the campaign's owner: the funnel from the ad to the confirmed
 * sign-up, a verdict against the goals set before the test, and the days one by one.
 */
function ReportPanel({ id, d, locale }: { id: string; d: Dict; locale: Locale }) {
  const r = d.proto.report;
  const token = useToken();
  const [rep, setRep] = useState<Report | null>(null);

  useEffect(() => {
    if (!token) return;
    let stop = false;
    const load = () =>
      !stop &&
      api<Report>(`/prototypes/${id}/report`, { token })
        .then((x) => !stop && setRep(x))
        .catch(() => {
          stop = true;
        });
    load();
    const t = setInterval(load, 60000);
    return () => {
      stop = true;
      clearInterval(t);
    };
  }, [token, id]);

  if (!rep) return null;
  const f = rep.funnel;
  const nf = (n: number, digits = 0) => n.toLocaleString(locale === "de" ? "de-AT" : "en-GB", { minimumFractionDigits: digits, maximumFractionDigits: digits });
  const pct = (v: number | null) => (v === null ? "-" : `${nf(v * 100, 1)} %`);
  const eur = (v: number | null) => (v === null ? "-" : `${nf(v, 2)} €`);
  const max = Math.max(1, ...rep.days.map((x) => x.visitors));
  const tiles: [string, string, string?][] = [
    ...(f.impressions > 0 ? ([[r.impressions, nf(f.impressions)], [r.clicks, nf(f.clicks), pct(f.ctr)]] as [string, string, string?][]) : []),
    [r.visitors, nf(f.visitors), f.visitors_from_ads ? r.fromAds.replace("{n}", nf(f.visitors_from_ads)) : undefined],
    [r.confirmed, nf(f.confirmed), r.of.replace("{n}", nf(f.signups))],
    [r.rate, pct(f.rate), f.rate_range ? r.range.replace("{lo}", pct(f.rate_range[0])).replace("{hi}", pct(f.rate_range[1])) : undefined],
    ...(f.spend_eur > 0 ? ([[r.spend, eur(f.spend_eur)], [r.cpl, eur(f.cost_per_signup_eur)]] as [string, string, string?][]) : []),
  ];

  return (
    <div className="card" style={{ marginTop: 20, display: "grid", gap: 12, maxWidth: 760 }}>
      <strong>{r.title}</strong>
      <div style={{ borderLeft: `4px solid ${VERDICT_COLOR[rep.verdict]}`, paddingLeft: 12 }}>
        <strong style={{ color: VERDICT_COLOR[rep.verdict] }}>{r.verdicts[rep.verdict]}</strong>
        <p className="small muted" style={{ margin: "4px 0 0" }}>
          {r.verdictHints[rep.verdict]} {r.goals.replace("{rate}", pct(rep.goals.rate)).replace("{cpl}", eur(rep.goals.cpl))}
          {rep.running ? ` ${r.running}` : ""}
        </p>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(130px, 1fr))", gap: 10 }}>
        {tiles.map(([label, value, note]) => (
          <div key={label} style={{ border: "1px solid var(--border)", borderRadius: 10, padding: 10 }}>
            <span className="small muted">{label}</span>
            <div style={{ fontSize: 22, fontWeight: 600 }}>{value}</div>
            {note && <span className="small muted">{note}</span>}
          </div>
        ))}
      </div>
      {rep.days.length > 0 && (
        <div style={{ display: "grid", gap: 4 }}>
          <span className="small muted">{r.perDay}</span>
          {rep.days.map((x) => (
            <div key={x.date} className="small" style={{ display: "grid", gridTemplateColumns: "56px 1fr 90px", gap: 8, alignItems: "center" }}>
              <span>{new Date(x.date).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB", { day: "2-digit", month: "2-digit" })}</span>
              <span style={{ background: "var(--border)", borderRadius: 4, height: 10, overflow: "hidden" }}>
                <span style={{ display: "block", height: "100%", width: `${(x.visitors / max) * 100}%`, background: "var(--accent, #2f4bd6)" }} />
              </span>
              <span>{r.dayLine.replace("{v}", String(x.visitors)).replace("{c}", String(x.confirmed))}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

interface Validation {
  ready: boolean;
  landing_url: string | null;
  defaults: { headline: string; text: string; budget_eur: number; days: number; countries: string[]; goal_rate: number; goal_cpl: number };
  meta: { configured: boolean };
  limits: { killed: boolean; max_campaign_eur: number; max_daily_total_eur: number; running_daily_eur: number };
  tests: {
    id: number;
    status: string;
    budget_eur: number | null;
    spent_eur: number;
    spent_today_eur: number;
    checked_at: string | null;
    ends_at: string | null;
    stopped_reason: string | null;
    error: string | null;
    countries: string[];
  }[];
}

const COUNTRIES = ["AT", "DE", "CH"];

/**
 * The validation test, for operators: the campaign's ad on Meta for a few days with a fixed total,
 * pointing at the live landing page. Prepared paused, started by a button, watched by the spend
 * guard. The API answers 403 to anyone else, and then this shows nothing.
 */
function ValidationPanel({ id, d }: { id: string; d: Dict }) {
  const v = d.proto.validation;
  const token = useToken();
  const [data, setData] = useState<Validation | null>(null);
  const [form, setForm] = useState<Validation["defaults"] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!token) return;
    let stop = false;
    // A visitor who is not an admin gets a 403 once, and the panel stops asking.
    const load = () =>
      !stop &&
      api<Validation>(`/admin/prototypes/${id}/validation`, { token })
        .then((r) => {
          if (stop) return;
          setData(r);
          setForm((f) => f ?? r.defaults);
        })
        .catch(() => {
          stop = true;
        });
    load();
    const t = setInterval(load, 20000);
    return () => {
      stop = true;
      clearInterval(t);
    };
  }, [token, id]);

  if (!token || !data || !form) return null;

  async function run(path: string, body?: object, confirm?: string) {
    if (confirm && !window.confirm(confirm)) return;
    setBusy(true);
    setError(null);
    try {
      await api(path, { method: "POST", token: token ?? undefined, body: body ? JSON.stringify(body) : undefined });
      setData(await api<Validation>(`/admin/prototypes/${id}/validation`, { token: token ?? undefined }));
    } catch (err) {
      const b = err && typeof err === "object" && "body" in err ? (err as { body: { error?: string; message?: string; problems?: string[] } | null }).body : null;
      setError(b?.error ?? b?.problems?.join(" · ") ?? b?.message ?? d.proto.failed);
    } finally {
      setBusy(false);
    }
  }

  const open = data.tests.find((t) => ["publishing", "paused", "active"].includes(t.status));
  const eur = (n: number | null) => (n === null ? "?" : `${n.toFixed(2)} €`);

  return (
    <div className="card" style={{ marginTop: 20, display: "grid", gap: 10, maxWidth: 640 }}>
      <strong>{v.title}</strong>
      <p className="small muted" style={{ margin: 0 }}>{v.hint}</p>
      {!data.meta.configured && <p className="est-empty" style={{ margin: 0 }}>{v.noMeta}</p>}
      {data.limits.killed && <p className="est-empty" style={{ margin: 0 }}>{v.killed}</p>}
      {!data.ready && <p className="small" style={{ margin: 0 }}>{v.notReady}</p>}
      {data.tests.map((t) => (
        <div key={t.id} style={{ borderTop: "1px solid var(--border)", paddingTop: 8, display: "grid", gap: 6 }}>
          <span>
            <strong>#{t.id}</strong> · {v.status[t.status as keyof typeof v.status] ?? t.status} · {eur(t.spent_eur)} / {eur(t.budget_eur)}
            {" · "}
            {v.today} {eur(t.spent_today_eur)}
            {t.ends_at && ` · ${v.until} ${new Date(t.ends_at).toLocaleDateString()}`}
          </span>
          {t.stopped_reason && <span className="small muted">{v.stopped} {t.stopped_reason}</span>}
          {t.error && <span className="small" style={{ color: "#c0392b" }}>{t.error}</span>}
          <div style={{ display: "flex", gap: 8 }}>
            {t.status === "paused" && (
              <button type="button" disabled={busy} onClick={() => run(`/admin/marketing/${t.id}/activate`, undefined,
                v.startConfirm.replace("{eur}", String(t.budget_eur)).replace("{date}", t.ends_at ? new Date(t.ends_at).toLocaleDateString() : "?"))}>
                {v.start}
              </button>
            )}
            {t.status === "active" && (
              <button type="button" disabled={busy} onClick={() => run(`/admin/marketing/${t.id}/pause`)}>{v.pause}</button>
            )}
          </div>
        </div>
      ))}
      {data.ready && !open && data.meta.configured && (
        <div style={{ display: "grid", gap: 8, borderTop: "1px solid var(--border)", paddingTop: 8 }}>
          <label className="small">
            {v.headline}
            <input value={form.headline} maxLength={40} onChange={(e) => setForm({ ...form, headline: e.target.value })} style={{ width: "100%", padding: 8 }} />
          </label>
          <label className="small">
            {v.text}
            <textarea value={form.text} maxLength={500} rows={3} onChange={(e) => setForm({ ...form, text: e.target.value })} style={{ width: "100%", padding: 8 }} />
          </label>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "center" }}>
            <label className="small">
              {v.budget}{" "}
              <input type="number" min={20} max={data.limits.max_campaign_eur} value={form.budget_eur}
                onChange={(e) => setForm({ ...form, budget_eur: Number(e.target.value) })} style={{ width: 80 }} /> €
            </label>
            <label className="small">
              {v.days}{" "}
              <input type="number" min={3} max={14} value={form.days} onChange={(e) => setForm({ ...form, days: Number(e.target.value) })} style={{ width: 60 }} />
            </label>
            <label className="small">
              {v.goalRate}{" "}
              <input type="number" min={1} max={80} value={form.goal_rate} onChange={(e) => setForm({ ...form, goal_rate: Number(e.target.value) })} style={{ width: 60 }} /> %
            </label>
            <label className="small">
              {v.goalCpl}{" "}
              <input type="number" min={0.5} max={500} step={0.5} value={form.goal_cpl} onChange={(e) => setForm({ ...form, goal_cpl: Number(e.target.value) })} style={{ width: 70 }} /> €
            </label>
            {COUNTRIES.map((c) => (
              <label key={c} className="small">
                <input type="checkbox" checked={form.countries.includes(c)}
                  onChange={(e) => setForm({ ...form, countries: e.target.checked ? [...form.countries, c] : form.countries.filter((x) => x !== c) })} /> {c}
              </label>
            ))}
          </div>
          <button type="button" disabled={busy || !form.headline || !form.text || form.countries.length === 0} style={{ justifySelf: "start" }}
            onClick={() => run(`/admin/prototypes/${id}/validation`, form)}>
            {v.prepare}
          </button>
        </div>
      )}
      {error && <p className="est-empty" style={{ margin: 0 }}>{error}</p>}
    </div>
  );
}

/**
 * The one free change. A visitor who is not signed in is asked for an e-mail first and comes
 * back to this page from the link; one who is signed in writes what should change.
 */
function RevisePanel({ id, locale, d, meta, onSent }: { id: string; locale: Locale; d: Dict; meta: Meta; onSent: () => void }) {
  const r = d.proto.revise;
  const token = useToken();
  const [change, setChange] = useState("");
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if ((meta.revisions_left ?? 1) < 1) {
    return <p className="sh-note">{r.used}</p>;
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
    <div className="sh-revise">
      <div className="sh-revise-head">
        <span className="sh-revise-ico"><Icon name="spark" /></span>
        <div>
          <h2>{r.title}</h2>
          <p>{r.hint}</p>
        </div>
      </div>
      {meta.revision_failed && <p className="pp-error">{r.failed}</p>}
      {token ? (
        <>
          <textarea
            value={change}
            onChange={(e) => setChange(e.target.value.slice(0, 1000))}
            rows={3}
            placeholder={r.placeholder}
            aria-label={r.title}
            className="pp-textarea sh-revise-text"
          />
          <button type="button" className="btn btn-primary pp-submit" onClick={send} disabled={busy || change.trim().length < 5}>
            {r.send}
            <Icon name="arrow" className="pp-btn-ico" />
          </button>
        </>
      ) : sent ? (
        <p className="sh-sent">{r.linkSent}</p>
      ) : (
        <>
          <p>{r.signIn}</p>
          <div className="sh-inline">
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={d.proto.signIn.email}
              aria-label={d.proto.signIn.email}
              className="pp-input"
            />
            <button type="button" className="btn btn-primary pp-submit" onClick={sendLink} disabled={!/.+@.+\..+/.test(email)}>
              {d.proto.signIn.send}
            </button>
          </div>
        </>
      )}
      {error && <p className="pp-error">{error}</p>}
    </div>
  );
}
