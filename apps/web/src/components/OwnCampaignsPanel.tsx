"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";
import { TrafficPanel } from "./TrafficPanel";

interface Campaign {
  id: number;
  name: string;
  status: string;
  editable: boolean;
  language: "de" | "en";
  countries: string[];
  landing_url: string;
  message: string;
  audience: string;
  offer: string;
  daily_eur: number;
  spend_cap_eur: number | null;
  ends_at: string | null;
  headlines: string[];
  descriptions: string[];
  keywords: { approved: number; waiting: number; negatives: number };
  spent_eur: number;
  spent_today_eur: number;
  impressions: number;
  clicks: number;
  published_at: string | null;
  error: string | null;
  stopped_reason: string | null;
  final_url: string;
  funnel: Funnel;
}

/** Google's clicks, then what our own analytics saw of the same visitors on the same day. */
interface Funnel {
  tag: string;
  clicks: number;
  visits: number;
  interest: number;
  quotes: number;
  orders: number;
  revenue_eur: number;
  cost_per_visit: number | null;
  cost_per_quote: number | null;
  cost_per_order: number | null;
}

interface Report {
  campaigns: Campaign[];
  limits: { killed: boolean; max_campaign_eur: number; max_daily_total_eur: number; running_daily_eur: number };
  google: boolean;
  countries: string[];
}

interface Form {
  name: string;
  language: "de" | "en";
  countries: string[];
  landing_url: string;
  message: string;
  audience: string;
  offer: string;
  daily_eur: string;
  spend_cap_eur: string;
  ends_at: string;
  headlines: string;
  descriptions: string;
}

const HEADLINE_MAX = 30;
const DESCRIPTION_MAX = 90;

const blank: Form = {
  name: "",
  language: "de",
  countries: ["AT"],
  landing_url: "https://appwerk.codemenschen.at",
  message: "",
  audience: "",
  offer: "",
  daily_eur: "10",
  spend_cap_eur: "100",
  ends_at: "",
  headlines: "",
  descriptions: "",
};

const toForm = (c: Campaign): Form => ({
  name: c.name,
  language: c.language,
  countries: c.countries,
  landing_url: c.landing_url,
  message: c.message,
  audience: c.audience ?? "",
  offer: c.offer ?? "",
  daily_eur: String(Math.round(c.daily_eur)),
  spend_cap_eur: c.spend_cap_eur !== null ? String(c.spend_cap_eur) : "",
  ends_at: c.ends_at ?? "",
  headlines: c.headlines.join("\n"),
  descriptions: c.descriptions.join("\n"),
});

const lines = (text: string) => text.split("\n").map((l) => l.trim()).filter(Boolean);

/**
 * Appwerk advertising itself: Google search campaigns on our own account, paid by us.
 *
 * The order on the screen is the order of the work: write the campaign (Appwerk AI drafts the ad
 * text, a person edits it), choose its keywords, send it to Google paused, then start it. Every
 * step says what it still needs, so a campaign that cannot serve is never one click from spending.
 */
export function OwnCampaignsPanel({
  token,
  locale,
  d,
  onKeywords,
}: {
  token: string;
  locale: Locale;
  d: Dict;
  onKeywords: (campaignId: number) => void;
}) {
  const o = d.admin.ownAds;
  const status = d.admin.clientAds.status as Record<string, string>;
  const [report, setReport] = useState<Report | null>(null);
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [form, setForm] = useState<Form>(blank);
  const [busy, setBusy] = useState<string | null>(null);
  const [note, setNote] = useState("");

  const money = useMemo(
    () => new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB", { style: "currency", currency: "EUR" }),
    [locale],
  );
  const count = useMemo(() => new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB"), [locale]);

  const load = useCallback(async () => {
    const r = await api<Report>("/admin/own-campaigns", { token }).catch(() => null);
    if (r) setReport(r);
    return r;
  }, [token]);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await api<Report>("/admin/own-campaigns", { token }).catch(() => null);
      if (alive && r) setReport(r);
    })();
    return () => {
      alive = false;
    };
  }, [token]);

  // Publishing runs on the queue. While one is on its way, look again every few seconds.
  const publishing = report?.campaigns.some((c) => c.status === "publishing") ?? false;
  useEffect(() => {
    if (!publishing) return;
    const t = window.setInterval(() => void load(), 4000);
    return () => window.clearInterval(t);
  }, [publishing, load]);

  const fail = (e: unknown) => {
    const body = e instanceof ApiError ? (e.body as { error?: string; message?: string } | null) : null;
    setNote(body?.error ?? body?.message ?? "…");
  };

  async function run(key: string, work: () => Promise<void>) {
    setBusy(key);
    setNote("");
    try {
      await work();
    } catch (e) {
      fail(e);
    }
    setBusy(null);
  }

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }));

  function edit(c: Campaign | null) {
    setNote("");
    if (c === null) {
      setForm(blank);
      setEditing("new");
    } else {
      setForm(toForm(c));
      setEditing(c.id);
    }
  }

  const body = () => ({
    name: form.name,
    language: form.language,
    countries: form.countries,
    landing_url: form.landing_url,
    message: form.message,
    audience: form.audience || null,
    offer: form.offer || null,
    daily_eur: Number(form.daily_eur),
    spend_cap_eur: Number(form.spend_cap_eur),
    ends_at: form.ends_at || null,
    headlines: lines(form.headlines),
    descriptions: lines(form.descriptions),
  });

  const save = () =>
    run("save", async () => {
      const saved =
        editing === "new"
          ? await api<Campaign>("/admin/own-campaigns", { method: "POST", token, body: JSON.stringify(body()) })
          : await api<Campaign>(`/admin/own-campaigns/${editing}`, { method: "PUT", token, body: JSON.stringify(body()) });
      await load();
      setEditing(saved.id);
      setForm(toForm(saved));
      setNote(o.saved);
    });

  const write = () => {
    if ((form.headlines.trim() || form.descriptions.trim()) && !window.confirm(o.confirmReplace)) return;
    void run("write", async () => {
      const r = await api<{ headlines: string[]; descriptions: string[] }>("/admin/own-campaigns/write", {
        method: "POST",
        token,
        body: JSON.stringify({
          message: form.message,
          audience: form.audience || null,
          offer: form.offer || null,
          landing_url: form.landing_url || null,
          language: form.language,
        }),
      });
      setForm((f) => ({ ...f, headlines: r.headlines.join("\n"), descriptions: r.descriptions.join("\n") }));
      setNote(o.written);
    });
  };

  const remove = (c: Campaign) => {
    if (!window.confirm(o.confirmDelete.replace("{name}", c.name))) return;
    void run(`d${c.id}`, async () => {
      await api(`/admin/own-campaigns/${c.id}`, { method: "DELETE", token });
      setEditing(null);
      await load();
    });
  };

  const publish = (c: Campaign) =>
    run(`p${c.id}`, async () => {
      await api(`/admin/own-campaigns/${c.id}/publish`, { method: "POST", token });
      await load();
      setNote(o.publishing);
    });

  const act = (c: Campaign, what: "activate" | "pause") => {
    if (
      what === "activate" &&
      !window.confirm(
        o.confirmStart
          .replace("{name}", c.name)
          .replace("{day}", money.format(c.daily_eur))
          .replace("{eur}", c.spend_cap_eur !== null ? money.format(c.spend_cap_eur) : "–"),
      )
    )
      return;
    void run(`c${c.id}`, async () => {
      await api(`/admin/marketing/${c.id}/${what}`, { method: "POST", token });
      await load();
    });
  };

  if (!report) return <p className="est-empty">{d.admin.loading}</p>;

  const current = typeof editing === "number" ? report.campaigns.find((c) => c.id === editing) ?? null : null;
  const running = report.campaigns.filter((c) => c.status === "active");
  const tone = (s: string) => (s === "active" ? "badge-live" : s === "failed" ? "badge-bad" : s === "paused" ? "badge-dim" : "badge-wait");

  /** What a campaign still needs before it can go to Google, worded for the person who fixes it. */
  const missing = (c: Campaign) => {
    const out: string[] = [];
    if (c.headlines.length < 3) out.push(o.needHeadlines.replace("{n}", String(c.headlines.length)));
    if (c.descriptions.length < 2) out.push(o.needDescriptions.replace("{n}", String(c.descriptions.length)));
    if (c.keywords.approved === 0) out.push(o.needKeywords);
    return out;
  };

  const counter = (text: string, max: number) =>
    lines(text).map((l, i) => (
      <div key={i} className="num small" style={{ color: l.length > max ? "var(--danger)" : "var(--ink-dim)" }}>
        {l.length}/{max} · {l}
      </div>
    ));

  const field = (label: string, input: React.ReactNode, hint?: string) => (
    <label style={{ display: "flex", flexDirection: "column", gap: 4 }}>
      <span className="small" style={{ color: "var(--ink-soft)" }}>{label}</span>
      {input}
      {hint && <span className="small muted">{hint}</span>}
    </label>
  );

  // Google refuses a line over its limit, so the form refuses it first, in the admin's language.
  const overLimit =
    lines(form.headlines).some((l) => l.length > HEADLINE_MAX) || lines(form.descriptions).some((l) => l.length > DESCRIPTION_MAX);
  const headCount = lines(form.headlines).length;
  const descCount = lines(form.descriptions).length;
  const readOnly = current !== null && !current.editable;
  // Publishing sends what is saved, so unsaved changes have to be saved first.
  const dirty = current !== null && JSON.stringify(form) !== JSON.stringify(toForm(current));

  return (
    <div>
      <p className="muted small" style={{ marginTop: 0, maxWidth: 720 }}>{o.intro}</p>

      {!report.google && <p className="note">{o.googleMissing}</p>}
      {report.limits.killed && <p className="note">{o.killed}</p>}

      <div className="ops-kpis">
        <div className="ops-kpi">
          <span className="ops-kpi-label">{o.kpiCampaigns}</span>
          <strong className="num">{report.campaigns.length}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{o.kpiRunning}</span>
          <strong className="num" style={{ color: running.length > 0 ? "var(--ok)" : undefined }}>{running.length}</strong>
          <span className="ops-kpi-sub">{o.kpiPerDay.replace("{eur}", money.format(running.reduce((s, c) => s + c.daily_eur, 0)))}</span>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{o.kpiToday}</span>
          <strong className="num">{money.format(report.campaigns.reduce((s, c) => s + c.spent_today_eur, 0))}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{o.kpiTotal}</span>
          <strong className="num">{money.format(report.campaigns.reduce((s, c) => s + c.spent_eur, 0))}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{o.kpiLimits}</span>
          <strong className="num">{money.format(report.limits.max_daily_total_eur)}</strong>
          <span className="ops-kpi-sub">{o.kpiLimitsSub.replace("{eur}", money.format(report.limits.max_campaign_eur))}</span>
        </div>
      </div>

      <div className="ops-toolbar">
        <button className="btn btn-primary btn-sm" onClick={() => edit(null)}>{o.create}</button>
      </div>

      {note && editing === null && <p className="note">{note}</p>}

      <div className="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>{o.campaign}</th>
              <th>{o.state}</th>
              <th style={{ textAlign: "right" }}>{o.perDay}</th>
              <th style={{ textAlign: "right" }}>{o.spent}</th>
              <th style={{ textAlign: "right" }}>{o.reach}</th>
              <th style={{ textAlign: "right" }}>{o.results}</th>
              <th>{o.keywords}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {report.campaigns.map((c) => (
              <tr key={c.id} className="ops-row" aria-selected={editing === c.id}>
                <td>
                  <span className="muted num">#{c.id}</span> {c.name}
                  <div className="muted small">{c.countries.join(", ") || o.everywhere} · {c.language.toUpperCase()}</div>
                  {c.status === "failed" && c.error && (
                    <div className="small" style={{ color: "var(--danger)", whiteSpace: "normal" }}>{c.error}</div>
                  )}
                </td>
                <td>
                  <span className={`badge ${tone(c.status)}`}>{status[c.status] ?? c.status}</span>
                  {c.stopped_reason && <div className="muted small">{c.stopped_reason}</div>}
                </td>
                <td className="num" style={{ textAlign: "right" }}>
                  {money.format(c.daily_eur)}
                  <div className="muted small">{o.capShort} {c.spend_cap_eur !== null ? money.format(c.spend_cap_eur) : "–"}</div>
                </td>
                <td className="num" style={{ textAlign: "right" }}>
                  {money.format(c.spent_eur)}
                  <div className="muted small">{o.todayShort} {money.format(c.spent_today_eur)}</div>
                </td>
                <td className="num" style={{ textAlign: "right" }}>
                  {count.format(c.impressions)}
                  <div className="muted small">{o.clicksN.replace("{n}", count.format(c.clicks))}</div>
                </td>
                <td className="num" style={{ textAlign: "right" }}>
                  {o.resultShort.replace("{q}", String(c.funnel.quotes)).replace("{o}", String(c.funnel.orders))}
                  <div className="muted small">
                    {c.funnel.cost_per_order !== null ? o.perOrder.replace("{eur}", money.format(c.funnel.cost_per_order)) : o.visitsN.replace("{n}", count.format(c.funnel.visits))}
                  </div>
                </td>
                <td>
                  <button className="btn btn-ghost btn-sm" onClick={() => onKeywords(c.id)}>
                    {o.keywordsN.replace("{n}", String(c.keywords.approved))}
                  </button>
                  {c.keywords.waiting > 0 && <div className="small" style={{ color: "var(--warn-ink)" }}>{o.waitingN.replace("{n}", String(c.keywords.waiting))}</div>}
                </td>
                <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                  {c.status === "active" && (
                    <button className="btn btn-ghost btn-sm" onClick={() => act(c, "pause")} disabled={busy === `c${c.id}`}>{o.pause}</button>
                  )}
                  {c.status === "paused" && (
                    <button className="btn btn-primary btn-sm" onClick={() => act(c, "activate")} disabled={busy === `c${c.id}`}>{o.start}</button>
                  )}
                  <button className="btn btn-ghost btn-sm" style={{ marginLeft: 6 }} onClick={() => (editing === c.id ? setEditing(null) : edit(c))}>
                    {editing === c.id ? o.close : c.editable ? o.edit : o.open}
                  </button>
                </td>
              </tr>
            ))}
            {report.campaigns.length === 0 && (
              <tr><td colSpan={8} className="muted">{o.empty}</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {editing !== null && (
        <section className="card" style={{ marginTop: 18 }}>
          <div className="detail-head">
            <h2 style={{ margin: 0 }}>{editing === "new" ? o.newTitle : form.name}</h2>
            {current && <span className={`badge ${tone(current.status)}`}>{status[current.status] ?? current.status}</span>}
          </div>
          {current && current.published_at !== null && (
            <div style={{ marginTop: 14 }}>
              <div className="ops-kpis" style={{ margin: "0 0 6px" }}>
                {(
                  [
                    ["clicks", current.funnel.clicks, null],
                    ["visits", current.funnel.visits, current.funnel.cost_per_visit],
                    ["interest", current.funnel.interest, null],
                    ["quotes", current.funnel.quotes, current.funnel.cost_per_quote],
                    ["orders", current.funnel.orders, current.funnel.cost_per_order],
                  ] as const
                ).map(([step, n, cost]) => (
                  <div className="ops-kpi" key={step}>
                    <span className="ops-kpi-label">{o.steps[step]}</span>
                    <strong className="num">{count.format(n)}</strong>
                    {cost !== null && <span className="ops-kpi-sub">{o.each.replace("{eur}", money.format(cost))}</span>}
                  </div>
                ))}
                <div className="ops-kpi">
                  <span className="ops-kpi-label">{o.revenue}</span>
                  <strong className="num">{money.format(current.funnel.revenue_eur)}</strong>
                  <span className="ops-kpi-sub">{o.spentOf.replace("{eur}", money.format(current.spent_eur))}</span>
                </div>
              </div>
              <p className="muted small" style={{ margin: 0 }}>{o.funnelHint}</p>
              <h3 style={{ margin: "20px 0 10px" }}>{d.admin.traffic.title}</h3>
              <TrafficPanel token={token} locale={locale} d={d} campaignId={current.id} />
            </div>
          )}
          {current && current.final_url && (
            <p className="small muted" style={{ marginTop: 10, overflowWrap: "anywhere" }}>
              {o.finalUrl} <span className="num">{current.final_url}</span>
            </p>
          )}
          {readOnly && <p className="muted small">{o.readOnly}</p>}

          <fieldset disabled={readOnly} style={{ border: 0, padding: 0, margin: "14px 0 0", display: "grid", gap: 14 }}>
            <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))" }}>
              {field(o.name, <input value={form.name} maxLength={60} onChange={(e) => set("name", e.target.value)} placeholder={o.namePlaceholder} />)}
              {field(
                o.language,
                <select value={form.language} onChange={(e) => set("language", e.target.value as Form["language"])}>
                  <option value="de">Deutsch</option>
                  <option value="en">English</option>
                </select>,
              )}
              <div role="group" aria-label={o.countries} style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                <span className="small" style={{ color: "var(--ink-soft)" }}>{o.countries}</span>
                <div style={{ display: "flex", gap: 12, flexWrap: "wrap", paddingTop: 6 }}>
                  {report.countries.map((cc) => (
                    <label key={cc} className="small" style={{ display: "flex", gap: 5, alignItems: "center" }}>
                      <input
                        type="checkbox"
                        checked={form.countries.includes(cc)}
                        onChange={(e) =>
                          set("countries", e.target.checked ? [...form.countries, cc] : form.countries.filter((x) => x !== cc))
                        }
                      />
                      {cc}
                    </label>
                  ))}
                </div>
              </div>
            </div>

            {field(o.landing, <input type="url" value={form.landing_url} onChange={(e) => set("landing_url", e.target.value)} />, o.landingHint)}
            {field(o.message, <textarea rows={3} maxLength={600} value={form.message} onChange={(e) => set("message", e.target.value)} placeholder={o.messagePlaceholder} />)}
            <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))" }}>
              {field(o.audience, <input value={form.audience} maxLength={300} onChange={(e) => set("audience", e.target.value)} placeholder={o.audiencePlaceholder} />)}
              {field(o.offer, <input value={form.offer} maxLength={300} onChange={(e) => set("offer", e.target.value)} placeholder={o.offerPlaceholder} />)}
            </div>

            <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))" }}>
              {field(o.daily, <input type="number" min={1} max={500} value={form.daily_eur} onChange={(e) => set("daily_eur", e.target.value)} />, o.dailyHint)}
              {field(
                o.cap,
                <input type="number" min={10} max={report.limits.max_campaign_eur} value={form.spend_cap_eur} onChange={(e) => set("spend_cap_eur", e.target.value)} />,
                o.capHint.replace("{eur}", money.format(report.limits.max_campaign_eur)),
              )}
              {field(o.ends, <input type="date" value={form.ends_at} onChange={(e) => set("ends_at", e.target.value)} />, o.endsHint)}
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: 10, flexWrap: "wrap", marginTop: 6 }}>
              <h4 style={{ margin: 0 }}>{o.adText}</h4>
              {!readOnly && (
                <button className="btn btn-ghost btn-sm" onClick={write} disabled={busy === "write" || form.message.trim() === ""} type="button">
                  {busy === "write" ? o.writing : o.write}
                </button>
              )}
            </div>
            <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fit, minmax(300px, 1fr))" }}>
              <div>
                {field(
                  o.headlines.replace("{n}", String(headCount)),
                  <textarea rows={8} value={form.headlines} onChange={(e) => set("headlines", e.target.value)} />,
                  o.headlinesHint,
                )}
                <div style={{ marginTop: 6 }}>{counter(form.headlines, HEADLINE_MAX)}</div>
              </div>
              <div>
                {field(
                  o.descriptions.replace("{n}", String(descCount)),
                  <textarea rows={5} value={form.descriptions} onChange={(e) => set("descriptions", e.target.value)} />,
                  o.descriptionsHint,
                )}
                <div style={{ marginTop: 6 }}>{counter(form.descriptions, DESCRIPTION_MAX)}</div>
              </div>
            </div>
          </fieldset>

          {overLimit && !readOnly && <p className="small" style={{ color: "var(--danger)", marginTop: 14 }}>{o.tooLong}</p>}
          {note && <p className="note" style={{ marginTop: 14 }}>{note}</p>}

          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 18, alignItems: "center" }}>
            {!readOnly && (
              <button className="btn btn-primary btn-sm" type="button" onClick={() => void save()} disabled={busy === "save" || overLimit}>
                {busy === "save" ? "…" : o.save}
              </button>
            )}
            {current && current.editable && (
              <>
                <button
                  className="btn btn-ghost btn-sm"
                  onClick={() => void publish(current)}
                  disabled={busy === `p${current.id}` || missing(current).length > 0 || !report.google || dirty}
                  title={o.publishHint}
                >
                  {o.publish}
                </button>
                <button className="btn btn-ghost btn-sm" onClick={() => onKeywords(current.id)}>{o.toKeywords}</button>
                {current.published_at === null && (
                  <button className="btn btn-ghost btn-sm" style={{ marginLeft: "auto", color: "var(--danger)" }} onClick={() => remove(current)} disabled={busy === `d${current.id}`}>
                    {o.remove}
                  </button>
                )}
              </>
            )}
          </div>

          {current && current.editable && (
            <div className="small" style={{ marginTop: 12 }}>
              {dirty && <p style={{ color: "var(--warn-ink)", margin: "0 0 6px" }}>{o.unsaved}</p>}
              {missing(current).length === 0 ? (
                <span style={{ color: "var(--ok)" }}>{o.ready}</span>
              ) : (
                <>
                  <span className="muted">{o.before}</span>
                  <ul style={{ margin: "4px 0 0", paddingLeft: 18 }}>
                    {missing(current).map((m) => <li key={m}>{m}</li>)}
                  </ul>
                </>
              )}
              <p className="muted" style={{ marginTop: 8 }}>{o.publishHint}</p>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
