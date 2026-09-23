"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface Account {
  id: number;
  platform: "google" | "meta";
  external_id: string;
  name: string | null;
  page_name: string | null;
  status: "pending" | "active" | "refused" | "removed";
  checked_at: string | null;
  problems: Problem[];
}

/** A code the screen words in its own language, plus the platform's message untranslated. */
interface Problem {
  code: string;
  detail: string | null;
}

interface Campaign {
  id: number;
  project: { id: string; name: string };
  platform: "google" | "meta";
  status: string;
  runs_on: "client" | "appwerk";
  account: string | null;
  budget_monthly_eur: number;
  spend_cap_eur: number | null;
  spent_eur: number;
  spent_today_eur: number;
  impressions: number;
  clicks: number;
  ends_at: string | null;
  checked_at: string | null;
  stopped_reason: string | null;
  problems: Problem[];
}

interface Client {
  id: number;
  email: string;
  accounts: Account[];
  campaigns: Campaign[];
  running: number;
  spent_eur: number;
  spent_today_eur: number;
  problems: number;
}

interface Report {
  totals: {
    clients: number;
    campaigns: number;
    running: number;
    spent_today_eur: number;
    spent_eur: number;
    problems: number;
    accounts: { active: number; pending: number; refused: number };
  };
  clients: Client[];
}

type Filter = "all" | "running" | "problems" | "waiting";

/**
 * Every client's advertising: their connected accounts, their campaigns, what each spends.
 *
 * Clients only, never Appwerk's own campaigns: that money is ours and lives on its own screen.
 * The list is sorted by what needs somebody (problems, then spending), so the top of the page is
 * the work. Starting a campaign spends money and asks first; pausing never does.
 */
export function ClientAdsPanel({ token, locale, d }: { token: string; locale: Locale; d: Dict }) {
  const c = d.admin.clientAds;
  const [report, setReport] = useState<Report | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [q, setQ] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [busy, setBusy] = useState<string | null>(null);
  const [note, setNote] = useState("");

  const money = useMemo(
    () => new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB", { style: "currency", currency: "EUR" }),
    [locale],
  );
  const count = useMemo(() => new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB"), [locale]);
  const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString(locale === "de" ? "de-AT" : "en-GB", { dateStyle: "short", timeStyle: "short" }) : "–";

  const load = useCallback(async () => {
    const r = await api<Report>("/admin/client-ads", { token }).catch(() => null);
    if (r) setReport(r);
  }, [token]);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await api<Report>("/admin/client-ads", { token }).catch(() => null);
      if (alive && r) setReport(r);
    })();
    return () => {
      alive = false;
    };
  }, [token]);

  const fail = (e: unknown) => {
    const body = e instanceof ApiError ? (e.body as { error?: string; message?: string } | null) : null;
    setNote(body?.error ?? body?.message ?? "…");
  };

  async function act(campaign: Campaign, what: "activate" | "pause") {
    if (what === "activate") {
      // A total is a total; a monthly budget has no end, and the question has to say which it is.
      const cap = campaign.spend_cap_eur !== null
        ? money.format(campaign.spend_cap_eur)
        : `${money.format(campaign.budget_monthly_eur)} ${c.perMonth}`;
      // Starting spends real money on somebody's card. One question is cheap.
      if (!window.confirm(c.confirmStart.replace("{name}", campaign.project.name).replace("{eur}", cap))) return;
    }
    setBusy(`c${campaign.id}`);
    setNote("");
    try {
      await api(`/admin/marketing/${campaign.id}/${what}`, { method: "POST", token });
      await load();
    } catch (e) {
      fail(e);
    }
    setBusy(null);
  }

  async function recheck(account: Account) {
    setBusy(`a${account.id}`);
    setNote("");
    try {
      await api(`/admin/ad-accounts/${account.id}/refresh`, { method: "POST", token });
      await load();
    } catch (e) {
      fail(e);
    }
    setBusy(null);
  }

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return (report?.clients ?? []).filter((cl) => {
      if (needle && !cl.email.toLowerCase().includes(needle) && !cl.campaigns.some((x) => x.project.name.toLowerCase().includes(needle))) return false;
      if (filter === "running") return cl.running > 0;
      if (filter === "problems") return cl.problems > 0;
      if (filter === "waiting") return cl.accounts.some((a) => a.status === "pending");
      return true;
    });
  }, [report, q, filter]);

  if (!report) return <p className="est-empty">{d.admin.loading}</p>;

  const t = report.totals;
  const selected = report.clients.find((cl) => cl.id === open) ?? null;

  const problem = (p: Problem) => (
    <div key={p.code} className="small" style={{ color: "var(--danger)" }}>
      {(c.problemText as Record<string, string>)[p.code] ?? p.code}
      {p.detail && <span className="num" style={{ display: "block", color: "var(--ink-dim)", whiteSpace: "normal" }}>{p.detail}</span>}
    </div>
  );

  const stTone = (s: string) => (s === "active" ? "badge-live" : s === "failed" ? "badge-bad" : s === "paused" ? "badge-dim" : "badge-wait");
  const stLabel = (s: string) => (c.status as Record<string, string>)[s] ?? s;
  const accTone = (s: Account["status"]) => (s === "active" ? "badge-live" : s === "pending" ? "badge-wait" : "badge-bad");
  const accLabel = (s: Account["status"]) => (c.accountStatus as Record<string, string>)[s] ?? s;

  return (
    <div>
      <p className="muted small" style={{ marginTop: 0, maxWidth: 720 }}>{c.intro}</p>

      <div className="ops-kpis">
        <div className="ops-kpi">
          <span className="ops-kpi-label">{c.kpiClients}</span>
          <strong className="num">{t.clients}</strong>
          <span className="ops-kpi-sub">{c.kpiCampaigns.replace("{n}", String(t.campaigns))}</span>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{c.kpiRunning}</span>
          <strong className="num" style={{ color: t.running > 0 ? "var(--ok)" : undefined }}>{t.running}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{c.kpiToday}</span>
          <strong className="num">{money.format(t.spent_today_eur)}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{c.kpiTotal}</span>
          <strong className="num">{money.format(t.spent_eur)}</strong>
        </div>
        <div className="ops-kpi">
          <span className="ops-kpi-label">{c.kpiAccounts}</span>
          <strong className="num">{t.accounts.active}</strong>
          <span className="ops-kpi-sub">{c.kpiAccountsSub.replace("{p}", String(t.accounts.pending)).replace("{r}", String(t.accounts.refused))}</span>
        </div>
        <div className="ops-kpi" data-alert={t.problems > 0 ? "yes" : undefined}>
          <span className="ops-kpi-label">{c.kpiProblems}</span>
          <strong className="num">{t.problems}</strong>
        </div>
      </div>

      <div className="ops-toolbar">
        <input
          type="search"
          value={q}
          placeholder={c.search}
          aria-label={c.search}
          onChange={(e) => setQ(e.target.value)}
          style={{ flex: "1 1 240px", maxWidth: 360 }}
        />
        <div className="ops-seg" role="group" aria-label={c.filter}>
          {(["all", "running", "problems", "waiting"] as Filter[]).map((f) => (
            <button key={f} aria-pressed={filter === f} onClick={() => setFilter(f)}>
              {c.filters[f]}
            </button>
          ))}
        </div>
      </div>

      {note && <p className="note">{note}</p>}

      <div className="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>{c.client}</th>
              <th>{c.accounts}</th>
              <th style={{ textAlign: "right" }}>{c.campaigns}</th>
              <th style={{ textAlign: "right" }}>{c.running}</th>
              <th style={{ textAlign: "right" }}>{c.today}</th>
              <th style={{ textAlign: "right" }}>{c.total}</th>
              <th>{c.problems}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {shown.map((cl) => (
              <tr key={cl.id} aria-selected={open === cl.id} className="ops-row">
                <td>{cl.email}</td>
                <td>
                  <span style={{ display: "inline-flex", gap: 4 }}>
                    {cl.accounts.length === 0 && <span className="muted small">{c.noAccount}</span>}
                    {cl.accounts.map((a) => (
                      <span key={a.id} className={`badge ${accTone(a.status)}`} title={`${a.external_id} · ${accLabel(a.status)}`}>
                        {a.platform === "google" ? "Google" : "Meta"}
                      </span>
                    ))}
                  </span>
                </td>
                <td className="num" style={{ textAlign: "right" }}>{cl.campaigns.length}</td>
                <td className="num" style={{ textAlign: "right", color: cl.running > 0 ? "var(--ok)" : undefined }}>{cl.running || ""}</td>
                <td className="num" style={{ textAlign: "right" }}>{cl.spent_today_eur ? money.format(cl.spent_today_eur) : ""}</td>
                <td className="num" style={{ textAlign: "right" }}>{cl.spent_eur ? money.format(cl.spent_eur) : ""}</td>
                <td>{cl.problems > 0 && <span className="badge badge-bad">{c.problemsN.replace("{n}", String(cl.problems))}</span>}</td>
                <td style={{ textAlign: "right" }}>
                  <button className="btn btn-ghost btn-sm" onClick={() => setOpen(open === cl.id ? null : cl.id)}>
                    {open === cl.id ? c.close : c.open}
                  </button>
                </td>
              </tr>
            ))}
            {shown.length === 0 && (
              <tr>
                <td colSpan={8} className="muted">{report.clients.length === 0 ? c.empty : c.noMatch}</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {selected && (
        <section className="card" style={{ marginTop: 18 }}>
          <div className="detail-head">
            <h2 style={{ margin: 0 }}>{selected.email}</h2>
            <span className="muted small">
              {c.detailSummary
                .replace("{n}", String(selected.campaigns.length))
                .replace("{r}", String(selected.running))
                .replace("{eur}", money.format(selected.spent_eur))}
            </span>
          </div>

          <h4 style={{ marginTop: 16 }}>{c.accounts}</h4>
          {selected.accounts.length === 0 ? (
            <p className="muted small">{c.noAccountLong}</p>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>{c.platform}</th>
                  <th>{c.number}</th>
                  <th>{c.name}</th>
                  <th>{c.state}</th>
                  <th>{c.checked}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {selected.accounts.map((a) => (
                  <tr key={a.id}>
                    <td>{a.platform === "google" ? "Google Ads" : "Meta"}</td>
                    <td className="num">{a.external_id}</td>
                    <td>
                      {a.name ?? "–"}
                      {a.page_name && <span className="muted small"> · {a.page_name}</span>}
                      {a.problems.map(problem)}
                    </td>
                    <td><span className={`badge ${accTone(a.status)}`}>{accLabel(a.status)}</span></td>
                    <td className="num muted">{when(a.checked_at)}</td>
                    <td style={{ textAlign: "right" }}>
                      <button className="btn btn-ghost btn-sm" onClick={() => void recheck(a)} disabled={busy === `a${a.id}`}>
                        {busy === `a${a.id}` ? "…" : c.recheck}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <h4 style={{ marginTop: 20 }}>{c.campaigns}</h4>
          {selected.campaigns.length === 0 ? (
            <p className="muted small">{c.noCampaigns}</p>
          ) : (
            <table className="table">
              <thead>
                <tr>
                  <th>{c.campaign}</th>
                  <th>{c.runsOn}</th>
                  <th>{c.state}</th>
                  <th style={{ textAlign: "right" }}>{c.budget}</th>
                  <th style={{ textAlign: "right" }}>{c.spent}</th>
                  <th style={{ textAlign: "right" }}>{c.reach}</th>
                  <th>{c.ends}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {selected.campaigns.map((x) => (
                  <tr key={x.id}>
                    <td>
                      <span className="muted num">#{x.id}</span> {x.project.name}
                      <div className="muted small">{x.platform === "google" ? "Google Ads" : "Meta"}</div>
                      {x.problems.map(problem)}
                    </td>
                    <td>
                      {x.runs_on === "client" ? c.runsClient : c.runsAppwerk}
                      {x.account && <div className="muted small num">{x.account}</div>}
                    </td>
                    <td>
                      <span className={`badge ${stTone(x.status)}`}>{stLabel(x.status)}</span>
                      {x.stopped_reason && <div className="muted small">{x.stopped_reason}</div>}
                    </td>
                    <td className="num" style={{ textAlign: "right" }}>
                      {x.spend_cap_eur !== null ? money.format(x.spend_cap_eur) : `${money.format(x.budget_monthly_eur)} ${c.perMonth}`}
                    </td>
                    <td className="num" style={{ textAlign: "right" }}>
                      {money.format(x.spent_eur)}
                      <div className="muted small">{c.todayShort} {money.format(x.spent_today_eur)}</div>
                    </td>
                    <td className="num" style={{ textAlign: "right" }}>
                      {count.format(x.impressions)}
                      <div className="muted small">{c.clicksN.replace("{n}", count.format(x.clicks))}</div>
                    </td>
                    <td className="num muted">{x.ends_at ? when(x.ends_at) : "–"}</td>
                    <td style={{ textAlign: "right" }}>
                      {x.status === "active" && (
                        <button className="btn btn-ghost btn-sm" onClick={() => void act(x, "pause")} disabled={busy === `c${x.id}`}>
                          {c.pause}
                        </button>
                      )}
                      {x.status === "paused" && (
                        <button className="btn btn-primary btn-sm" onClick={() => void act(x, "activate")} disabled={busy === `c${x.id}`}>
                          {c.start}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      )}
    </div>
  );
}
