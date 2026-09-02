"use client";

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface Overview {
  projects: { total: number; by_status: Record<string, number> };
  runs: { queued: number; running: number; failed_24h: number };
  ads: Record<string, number>;
  campaigns: Record<string, number>;
  customers: number;
  revenue: {
    paid_orders: number;
    paid_eur: number;
    hosting_monthly_eur: number;
    ad_budget_monthly_eur: number;
  };
  attention: AttentionItem[];
}

interface AttentionItem {
  kind: "project_failed" | "run_failed" | "run_stalled" | "ad_failed";
  at: string;
  project: { id: string; name: string; status: string } | null;
  customer: string | null;
  stage?: string;
  ad?: { id: number; kind: string; name: string };
  detail: string;
}

interface ProjectRow {
  id: string;
  name: string;
  status: string;
  stack: string | null;
  customer: string | null;
  created_at: string;
  order: { status: string | null; total_one_time_eur: number };
  counts: { ads: number; campaigns: number; change_requests: number };
}

interface AdRow {
  id: number;
  kind: string;
  name: string;
  status: string;
  error: string | null;
  bytes: number;
  created_at: string;
  project: { id: string; name: string } | null;
  customer: string | null;
}

interface CustomerRow {
  id: number;
  email: string;
  name: string | null;
  is_admin: boolean;
  projects: number;
  orders: number;
  paid_eur: number;
}

/** The detail endpoint answers with more than the list, and with the customer as an object. */
interface ProjectDetail {
  id: string;
  name: string;
  status: string;
  customer: { email: string | null; name: string | null } | null;
  failed_reason: string | null;
  care_status: string;
  runs: {
    id: string;
    stage: string;
    attempt: number;
    status: string;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
  }[];
  ads: { id: number; kind: string; name: string; status: string; error: string | null }[];
  campaigns: { id: number; platform: string; status: string; platform_status: string }[];
  events: { type: string; actor: string; created_at: string }[];
}

type Tab = "overview" | "projects" | "ads" | "customers";

const dt = (s: string, locale: Locale) => new Date(s).toLocaleString(locale);

/**
 * The operator's own page: the whole factory on one screen, and the few buttons that get a stuck
 * project moving again.
 *
 * It reads /admin/*, which is closed to everyone but an admin. The panel does not decide that: a
 * customer who guesses this URL gets a page that answers 403 to every request, which is what the
 * "no access" state below is showing.
 */
export function AdminPanel({ locale, d }: { locale: Locale; d: Dict }) {
  const a = d.admin;
  const [token, setToken] = useState<string | null | undefined>(undefined);
  const [denied, setDenied] = useState(false);
  const [tab, setTab] = useState<Tab>("overview");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [projects, setProjects] = useState<ProjectRow[]>([]);
  const [statuses, setStatuses] = useState<string[]>([]);
  const [stages, setStages] = useState<string[]>([]);
  const [ads, setAds] = useState<AdRow[]>([]);
  const [customers, setCustomers] = useState<CustomerRow[]>([]);
  const [detail, setDetail] = useState<ProjectDetail | null>(null);
  const [q, setQ] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  useEffect(() => {
    setToken(localStorage.getItem("aifactory-token"));
  }, []);

  /** Every call goes through here so a 403 turns into the "no access" state instead of a blank page. */
  const call = useCallback(
    async <T,>(path: string, init?: RequestInit): Promise<T | null> => {
      if (!token) return null;
      try {
        return await api<T>(path, { ...init, token });
      } catch (e) {
        if (e instanceof ApiError && e.status === 403) setDenied(true);
        else if (e instanceof ApiError) {
          const body = e.body as { message?: string } | null;
          setNote(body?.message ?? `HTTP ${e.status}`);
        }
        return null;
      }
    },
    [token],
  );

  const loadOverview = useCallback(async () => {
    const r = await call<Overview>("/admin/overview");
    if (r) setOverview(r);
  }, [call]);

  const loadProjects = useCallback(async () => {
    const params = new URLSearchParams();
    if (q.trim()) params.set("q", q.trim());
    if (statusFilter) params.set("status", statusFilter);
    const r = await call<{ projects: ProjectRow[]; statuses: string[]; stages: string[] }>(
      `/admin/projects${params.size ? `?${params}` : ""}`,
    );
    if (r) {
      setProjects(r.projects);
      setStatuses(r.statuses);
      setStages(r.stages);
    }
  }, [call, q, statusFilter]);

  useEffect(() => {
    if (!token) return;
    void loadOverview();
    void loadProjects();
  }, [token, loadOverview, loadProjects]);

  const loadAds = useCallback(async () => {
    const r = await call<{ ads: AdRow[] }>("/admin/ads");
    if (r) setAds(r.ads);
  }, [call]);

  /** Tab data is fetched on the press, not in an effect: the press is the thing that asks for it. */
  async function selectTab(t: Tab) {
    setTab(t);
    setNote(null);
    if (t === "ads") await loadAds();
    if (t === "customers") {
      const r = await call<{ customers: CustomerRow[] }>("/admin/customers");
      if (r) setCustomers(r.customers);
    }
  }

  async function openProject(id: string) {
    setNote(null);
    const r = await call<ProjectDetail>(`/admin/projects/${id}`);
    if (r) {
      setDetail(r);
      setTab("projects");
    }
  }

  async function runStage(projectId: string, stage: string) {
    setBusy(true);
    setNote(null);
    const r = await call<{ stage: string }>(`/admin/projects/${projectId}/stage`, {
      method: "POST",
      body: JSON.stringify({ stage }),
    });
    if (r) setNote(`${a.stageQueued}: ${r.stage}`);
    setBusy(false);
    await loadOverview();
    if (detail?.id === projectId) await openProject(projectId);
  }

  async function forceStatus(projectId: string, status: string) {
    setBusy(true);
    setNote(null);
    const r = await call<{ status: string }>(`/admin/projects/${projectId}/status`, {
      method: "POST",
      body: JSON.stringify({ status }),
    });
    if (r) setNote(`${a.statusForced}: ${r.status}`);
    setBusy(false);
    await Promise.all([loadOverview(), loadProjects()]);
    if (detail?.id === projectId) await openProject(projectId);
  }

  async function rerenderAd(adId: number) {
    setBusy(true);
    setNote(null);
    const r = await call<{ status: string }>(`/admin/ads/${adId}/rerender`, { method: "POST" });
    if (r) setNote(a.adQueued);
    setBusy(false);
    await loadOverview();
    if (tab === "ads") await loadAds();
  }

  if (token === undefined) return <p className="est-empty">{a.loading}</p>;
  if (!token || denied)
    return (
      <p className="est-empty">
        {a.noAccess} <a href={`/${locale}/account`}>{a.goSignIn}</a>
      </p>
    );

  return (
    <div>
      <div className="tabs" role="tablist">
        {(["overview", "projects", "ads", "customers"] as Tab[]).map((t) => (
          <button
            key={t}
            className="tab"
            role="tab"
            aria-selected={tab === t}
            onClick={() => void selectTab(t)}
          >
            {a.tabs[t]}
          </button>
        ))}
      </div>

      {note && <p className="note">{note}</p>}

      {tab === "overview" && overview && (
        <>
          <div className="grid" style={{ marginBottom: 26 }}>
            <div className="card">
              <span className="cat">{a.projectsTile}</span>
              <strong className="num" style={{ fontSize: 28 }}>
                {overview.projects.total}
              </strong>
              <div className="small muted">
                {Object.entries(overview.projects.by_status).map(([s, n]) => (
                  <div key={s}>
                    {s} · {n}
                  </div>
                ))}
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.runsTile}</span>
              <div className="small">
                <div>
                  {a.queued}: <span className="num">{overview.runs.queued}</span>
                </div>
                <div>
                  {a.running}: <span className="num">{overview.runs.running}</span>
                </div>
                <div>
                  {a.failed24h}: <span className="num">{overview.runs.failed_24h}</span>
                </div>
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.adsTile}</span>
              <div className="small">
                {Object.entries(overview.ads).map(([s, n]) => (
                  <div key={s}>
                    {s} · {n}
                  </div>
                ))}
                {Object.keys(overview.ads).length === 0 && <span className="muted">{a.empty}</span>}
              </div>
            </div>
            <div className="card">
              <span className="cat">{a.revenueTile}</span>
              <div className="small">
                <div>
                  {a.paidOrders}: <span className="num">{overview.revenue.paid_orders}</span>
                </div>
                <div>
                  {a.paidEur}: <span className="num">{overview.revenue.paid_eur} €</span>
                </div>
                <div>
                  {a.hosting}: <span className="num">{overview.revenue.hosting_monthly_eur} €</span>
                </div>
                <div>
                  {a.customersTile}: <span className="num">{overview.customers}</span>
                </div>
              </div>
            </div>
          </div>

          <h2 style={{ fontSize: "1.1rem" }}>{a.attention}</h2>
          {overview.attention.length === 0 && <p className="est-empty">{a.allQuiet}</p>}
          <div className="tbl-wrap">
            <table>
              <tbody>
                {overview.attention.map((item, i) => (
                  <tr key={`${item.kind}-${i}`}>
                    <td>{a.kinds[item.kind]}</td>
                    <td>
                      {item.project ? (
                        <button className="tab" onClick={() => void openProject(item.project!.id)}>
                          {item.project.name}
                        </button>
                      ) : (
                        "—"
                      )}
                      <div className="small muted">
                        {item.customer ?? ""} · {dt(item.at, locale)}
                      </div>
                    </td>
                    <td className="small muted" style={{ maxWidth: 420 }}>
                      {item.stage ? `${item.stage}: ` : ""}
                      {item.detail}
                    </td>
                    <td>
                      {item.stage && item.project && (
                        <button
                          className="btn btn-ghost"
                          disabled={busy}
                          onClick={() => void runStage(item.project!.id, item.stage!)}
                        >
                          {a.retryStage}
                        </button>
                      )}
                      {item.ad && (
                        <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(item.ad!.id)}>
                          {a.rerender}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === "projects" && (
        <>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap", marginBottom: 16 }}>
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={a.searchHint}
              style={{ minWidth: 240 }}
            />
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">{a.allStatuses}</option>
              {statuses.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
            <button className="btn btn-ghost" onClick={() => void loadProjects()}>
              {a.search}
            </button>
          </div>

          <div className="tbl-wrap" style={{ marginBottom: 24 }}>
            <table>
              <thead>
                <tr>
                  <th>{a.project}</th>
                  <th>{a.status}</th>
                  <th>{a.customer}</th>
                  <th>{a.order}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {projects.map((p) => (
                  <tr key={p.id}>
                    <td>
                      {p.name}
                      <div className="small muted">{dt(p.created_at, locale)}</div>
                    </td>
                    <td>
                      <span className="badge">{p.status}</span>
                    </td>
                    <td className="small">{p.customer}</td>
                    <td className="small num">
                      {p.order.total_one_time_eur} € · {p.order.status}
                    </td>
                    <td>
                      <button className="btn btn-ghost" onClick={() => void openProject(p.id)}>
                        {a.open}
                      </button>
                    </td>
                  </tr>
                ))}
                {projects.length === 0 && (
                  <tr>
                    <td colSpan={5} className="muted">
                      {a.empty}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {detail && (
            <div className="card" style={{ gap: 14 }}>
              <div className="detail-head">
                <h2 style={{ margin: 0, fontSize: "1.15rem" }}>{detail.name}</h2>
                <span className="badge">{detail.status}</span>
                <span className="small muted">{detail.customer?.email}</span>
              </div>
              {detail.failed_reason && <p className="note">{detail.failed_reason}</p>}

              <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "center" }}>
                <label className="small">
                  {a.runStage}{" "}
                  <select
                    defaultValue=""
                    disabled={busy}
                    onChange={(e) => {
                      if (e.target.value) void runStage(detail.id, e.target.value);
                      e.target.value = "";
                    }}
                  >
                    <option value="">…</option>
                    {stages.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="small">
                  {a.forceStatus}{" "}
                  <select
                    defaultValue=""
                    disabled={busy}
                    onChange={(e) => {
                      if (e.target.value) void forceStatus(detail.id, e.target.value);
                      e.target.value = "";
                    }}
                  >
                    <option value="">…</option>
                    {statuses.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
              </div>

              <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.runs}</h3>
              <div className="tbl-wrap">
                <table>
                  <tbody>
                    {detail.runs.map((r) => (
                      <tr key={r.id}>
                        <td>{r.stage}</td>
                        <td className="small">
                          {r.status} · #{r.attempt}
                        </td>
                        <td className="small muted">{r.error ?? ""}</td>
                        <td className="small muted">{r.finished_at ? dt(r.finished_at, locale) : ""}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {detail.ads.length > 0 && (
                <>
                  <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.adsTile}</h3>
                  <div className="tbl-wrap">
                    <table>
                      <tbody>
                        {detail.ads.map((ad) => (
                          <tr key={ad.id}>
                            <td>{ad.name}</td>
                            <td className="small">
                              {ad.kind} · {ad.status}
                            </td>
                            <td className="small muted">{ad.error ?? ""}</td>
                            <td>
                              <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(ad.id)}>
                                {a.rerender}
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}

              <h3 style={{ margin: "8px 0 0", fontSize: "1rem" }}>{a.events}</h3>
              <div className="small muted" style={{ maxHeight: 240, overflowY: "auto" }}>
                {detail.events.map((e, i) => (
                  <div key={i}>
                    {dt(e.created_at, locale)} · {e.type} · {e.actor}
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}

      {tab === "ads" && (
        <div className="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>{a.ad}</th>
                <th>{a.status}</th>
                <th>{a.project}</th>
                <th>{a.customer}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {ads.map((ad) => (
                <tr key={ad.id}>
                  <td>
                    {ad.name}
                    <div className="small muted">
                      {ad.kind} · {dt(ad.created_at, locale)}
                    </div>
                  </td>
                  <td className="small">
                    {ad.status}
                    {ad.error && <div className="muted">{ad.error}</div>}
                  </td>
                  <td className="small">
                    {ad.project ? (
                      <button className="tab" onClick={() => void openProject(ad.project!.id)}>
                        {ad.project.name}
                      </button>
                    ) : (
                      "—"
                    )}
                  </td>
                  <td className="small">{ad.customer}</td>
                  <td>
                    <button className="btn btn-ghost" disabled={busy} onClick={() => void rerenderAd(ad.id)}>
                      {a.rerender}
                    </button>
                  </td>
                </tr>
              ))}
              {ads.length === 0 && (
                <tr>
                  <td colSpan={5} className="muted">
                    {a.empty}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {tab === "customers" && (
        <div className="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>{a.customer}</th>
                <th>{a.projectsTile}</th>
                <th>{a.orders}</th>
                <th>{a.paidEur}</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td>
                    {c.email}
                    {c.is_admin && <span className="badge badge-type" style={{ marginLeft: 8 }}>admin</span>}
                  </td>
                  <td className="num">{c.projects}</td>
                  <td className="num">{c.orders}</td>
                  <td className="num">{c.paid_eur} €</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
