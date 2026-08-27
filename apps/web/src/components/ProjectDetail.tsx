"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { api, API_BASE, ApiError } from "@/lib/api";
import { eur, type Dict, type Locale } from "@/lib/i18n";

interface Detail {
  id: string;
  name: string;
  status: string;
  failed_reason: string | null;
  criteria: { key: string; criterion: string; kind: string; status: string }[];
  builds: { id: number; platform: string; version: string; status: string }[];
  preview_url?: string | null;
  runs: { stage: string; attempt: number; status: string; started_at: string | null; finished_at: string | null }[];
  store_assets?: { id: number; kind: string; locale: string | null; content: string | null; status: string }[];
  revision_rounds?: number;
  max_revision_rounds?: number;
  free_rounds_left?: number;
  change_request_mode?: "free" | "paid" | "none";
  revision_price_eur?: number;
  change_requests?: {
    id: number;
    round: number;
    text: string;
    status: string;
    agent_summary: string | null;
    price_eur: number;
    checkout_url: string | null;
    created_at: string;
  }[];
  submissions?: { id: number; store: string; status: string; account_ref: string | null }[];
  packages?: Record<string, boolean>;
  campaigns?: {
    id: number;
    platform: string;
    status: string;
    strategy: Record<string, string>;
    creatives: { id: number; kind: string; locale: string | null; content: string }[];
  }[];
  order?: { total_one_time_eur: number };
}

type TabKey = "app" | "store" | "marketing" | "activity";

const runDot: Record<string, string> = {
  succeeded: "var(--valid)",
  failed: "#B3261E",
  running: "var(--accent)",
  queued: "var(--border)",
};

export function ProjectDetail({ locale, d, projectId }: { locale: Locale; d: Dict; projectId: string }) {
  const [token] = useState<string | null>(() =>
    typeof window === "undefined" ? null : localStorage.getItem("aifactory-token"),
  );
  const [p, setP] = useState<Detail | null | undefined>(undefined);
  const [crText, setCrText] = useState(""); // change-request draft
  const [crWaiver, setCrWaiver] = useState(false); // paid rounds: FAGG § 18, never pre-ticked
  const [crNotice, setCrNotice] = useState<string | null>(null);
  // Store assets are grouped per listing language; start on the portal language.
  const [assetLocale, setAssetLocale] = useState<string | null>(null);
  const assetLocales = useMemo(
    () => Array.from(new Set((p?.store_assets ?? []).map((a) => a.locale).filter((l): l is string => !!l))),
    [p?.store_assets],
  );
  const shownLocale =
    assetLocale && assetLocales.includes(assetLocale)
      ? assetLocale
      : assetLocales.includes(locale)
        ? locale
        : (assetLocales[0] ?? null);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<TabKey>("app");

  const load = useCallback(() => {
    if (!token) return;
    api<Detail>(`/me/projects/${projectId}`, { token }).then(setP).catch(() => setP(null));
  }, [token, projectId]);

  useEffect(load, [load]);

  // Live-ish while the factory works
  useEffect(() => {
    if (!p || ["READY", "FAILED", "COMPLETED"].includes(p.status)) return;
    const t = setInterval(load, 20_000);
    return () => clearInterval(t);
  }, [p, load]);

  if (!token)
    return (
      <div className="note" style={{ display: "grid", gap: 12 }}>
        <p style={{ margin: 0 }}>{d.project.signInNeeded}</p>
        <div style={{ display: "flex", gap: 14, alignItems: "center", flexWrap: "wrap" }}>
          <Link
            className="btn btn-primary"
            href={`/${locale}/account`}
            onClick={() => localStorage.setItem("aifactory-next", `/${locale}/account/${projectId}`)}
          >
            {d.project.signIn}
          </Link>
          <Link href={`/${locale}/account`}>{d.project.back}</Link>
        </div>
      </div>
    );
  if (p === null)
    return (
      <div className="note" style={{ display: "grid", gap: 12 }}>
        <p style={{ margin: 0 }}>{d.project.notFound}</p>
        <Link href={`/${locale}/account`}>{d.project.back}</Link>
      </div>
    );
  if (!p) return <p className="est-empty">…</p>;

  const ext: Record<string, string> = { android: "apk", ios: "ipa", bundle: "tar.gz" };
  const download = async (buildId: number, platform: string) => {
    setBusy(true);
    try {
      const res = await fetch(`${API_BASE}/api/me/projects/${p.id}/builds/${buildId}/download`, {
        headers: { authorization: `Bearer ${token}` },
      });
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = `${p.name}-${platform}-${buildId}.${ext[platform] ?? "bin"}`;
      a.click();
      URL.revokeObjectURL(a.href);
    } finally {
      setBusy(false);
    }
  };

  const requestChanges = async () => {
    if (!p) return;
    setBusy(true);
    setCrNotice(null);
    try {
      const res = await api<{ checkout_url?: string }>(`/me/projects/${p.id}/change-requests`, {
        method: "POST",
        token,
        body: JSON.stringify({ text: crText, fagg_waiver: crWaiver }),
      });
      if (res.checkout_url) {
        window.location.href = res.checkout_url; // Stripe Checkout for a paid round
        return;
      }
      setCrText("");
      setCrWaiver(false);
      load();
    } catch (e) {
      setCrNotice(e instanceof ApiError && e.status === 503 ? d.checkout.staging : d.checkout.errors.generic);
      load();
    } finally {
      setBusy(false);
    }
  };

  const approve = async () => {
    setBusy(true);
    try {
      await api(`/me/projects/${p.id}/approve-review`, { method: "POST", token });
      load();
    } finally {
      setBusy(false);
    }
  };

  const buildRunning = p.runs.some((r) => r.stage === "build" && (r.status === "running" || r.status === "queued"));
  const buildFailed =
    !buildRunning &&
    !p.builds.some((b) => b.platform === "android") &&
    p.runs.some((r) => r.stage === "build" && r.status === "failed");
  const released = ["READY", "PUBLISHING", "PUBLISHED", "MARKETING", "COMPLETED"].includes(p.status);
  const showStore = (p.store_assets?.length ?? 0) > 0 || released;
  const showMarketing = released && !!p.packages?.marketingLaunch;
  const canChange = (p.change_request_mode ?? "none") !== "none";
  const passed = p.criteria.filter((c) => c.status === "passed").length;
  const tabs: { key: TabKey; label: string }[] = [
    { key: "app", label: d.project.tabs.app },
    ...(showStore ? [{ key: "store" as const, label: d.project.tabs.store }] : []),
    ...(showMarketing ? [{ key: "marketing" as const, label: d.project.tabs.marketing }] : []),
    { key: "activity", label: d.project.tabs.activity },
  ];
  const active: TabKey = tabs.some((t) => t.key === tab) ? tab : "app";
  const inputStyle = {
    width: "100%",
    padding: 12,
    fontSize: 14.5,
    border: "1px solid var(--border)",
    borderRadius: "var(--radius)",
    background: "var(--paper)",
    fontFamily: "var(--font-body)",
  } as const;

  return (
    <div>
      <p>
        <Link href={`/${locale}/account`} className="muted small" style={{ textDecoration: "none" }}>
          {d.project.back}
        </Link>
      </p>
      <div className="detail-head">
        <h1 style={{ marginBottom: 0 }}>{p.name}</h1>
        <span className="badge badge-type">{p.status}</span>
        {p.order && (
          <span className="muted small num">{d.account.total}: {eur(p.order.total_one_time_eur, locale)}</span>
        )}
      </div>

      {p.status === "FAILED" && <p className="note" style={{ marginTop: 16 }}>{d.project.failed}</p>}
      {p.change_requests?.some((c) => c.status === "in_progress") && (
        <p className="note" style={{ marginTop: 16 }}>{d.project.changesInProgress}</p>
      )}

      <div className="tabs" role="tablist">
        {tabs.map((t) => (
          <button key={t.key} role="tab" aria-selected={active === t.key} className="tab" onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      {/* ---------------- App: preview, downloads, approval, change requests ---------------- */}
      {active === "app" && (
        <div className="detail-stack">
          <div className="card">
            <h3>{d.project.builds}</h3>
            {p.builds.length === 0 && <p className="est-empty" style={{ margin: 0 }}>{d.project.buildsPending}</p>}
            {p.preview_url && (
              <a className="btn btn-primary btn-block" href={p.preview_url} target="_blank" rel="noopener noreferrer">
                {d.project.openPreview}
              </a>
            )}
            {p.preview_url && <p className="small muted" style={{ margin: 0 }}>{d.project.previewHint}</p>}
            {p.builds
              .filter((b) => b.platform !== "web")
              .map((b) => (
                <div className="row" key={b.id}>
                  <span className="muted">
                    {d.project.platformNames[b.platform] ?? b.platform} v{b.version}
                  </span>
                  <button className="lang-toggle" disabled={busy} onClick={() => download(b.id, b.platform)}>
                    {d.project.download}
                  </button>
                </div>
              ))}
            {buildRunning && <p className="small muted" style={{ margin: 0 }}>{d.project.buildRunning}</p>}
            {buildFailed && <p className="small muted" style={{ margin: 0 }}>{d.project.buildFailed}</p>}
            {p.criteria.length > 0 && (
              <p className="small muted" style={{ margin: 0 }}>
                {d.project.criteriaSummary.replace("{passed}", String(passed)).replace("{total}", String(p.criteria.length))}
                {" · "}
                <a href="#" onClick={(e) => { e.preventDefault(); setTab("activity"); }}>{d.project.tabs.activity}</a>
              </p>
            )}
          </div>

          {p.status === "REVIEW" && (
            <div className="card">
              <h3>{d.project.approveTitle}</h3>
              <p className="small muted" style={{ margin: 0 }}>{d.project.approveHint}</p>
              <button className="btn btn-primary btn-block" disabled={busy} onClick={approve}>
                {d.project.approve}
              </button>
            </div>
          )}

          {canChange && (
            <div className="card">
              <h3>{d.project.changesTitle}</h3>
              <p className="small muted" style={{ margin: 0 }}>
                {p.change_request_mode === "free"
                  ? d.project.changesHint
                      .replace("{left}", String(p.free_rounds_left ?? 0))
                      .replace("{max}", String(p.max_revision_rounds ?? 0))
                  : d.project.changesPaidHint.replace("{price}", eur(p.revision_price_eur ?? 0, locale))}
              </p>
              <textarea
                value={crText}
                onChange={(e) => setCrText(e.target.value)}
                placeholder={d.project.changesPh}
                rows={4}
                style={{ ...inputStyle, background: "var(--surface)", resize: "vertical" }}
              />
              {p.change_request_mode === "paid" && (
                <label className="choice" style={{ alignItems: "flex-start" }}>
                  <input type="checkbox" checked={crWaiver} onChange={(e) => setCrWaiver(e.target.checked)} style={{ marginTop: 4 }} />
                  <span className="small">{d.checkout.waiverLabel}</span>
                </label>
              )}
              <button
                className="btn btn-ghost btn-block"
                disabled={busy || crText.trim().length < 10 || (p.change_request_mode === "paid" && !crWaiver)}
                onClick={requestChanges}
              >
                {p.change_request_mode === "paid"
                  ? d.project.changesPay.replace("{price}", eur(p.revision_price_eur ?? 0, locale))
                  : d.project.changesSend}
              </button>
              {crNotice && <p className="note">{crNotice}</p>}
            </div>
          )}
        </div>
      )}

      {/* ---------------- Store: listing texts + publishing ---------------- */}
      {active === "store" && (
        <div className="detail-stack">
          <div>
            <h2 style={{ marginTop: 0 }}>{d.project.assets}</h2>
            <p className="muted small">{d.project.assetsHint}</p>
            {(p.store_assets?.length ?? 0) === 0 ? (
              <p className="est-empty">{d.project.assetsEmpty}</p>
            ) : (
              <>
                {assetLocales.length > 1 && (
                  <div className="choices" style={{ marginBottom: 12 }} role="tablist">
                    {assetLocales.map((code) => (
                      <label className="choice" key={code}>
                        <input type="radio" name="asset-locale" checked={shownLocale === code} onChange={() => setAssetLocale(code)} />
                        {d.checkout.localeNames[code] ?? code.toUpperCase()}
                      </label>
                    ))}
                  </div>
                )}
                <div className="tbl-wrap">
                  <table>
                    <tbody>
                      {p.store_assets!
                        .filter((a) => !a.locale || a.locale === shownLocale)
                        .map((a) => (
                          <tr key={a.id}>
                            <td style={{ whiteSpace: "nowrap", verticalAlign: "top" }}>{d.project.kinds[a.kind] ?? a.kind}</td>
                            <td className="muted" style={{ whiteSpace: "pre-wrap" }}>{a.content}</td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </div>

          {["READY", "PUBLISHING", "PUBLISHED"].includes(p.status) && (
            <div className="card">
              <h3>{d.project.publishing}</h3>
              {(p.submissions?.length ?? 0) === 0 ? (
                <>
                  <p className="small muted" style={{ margin: 0 }}>{d.project.publishingHint}</p>
                  <button
                    className="btn btn-primary btn-block"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true);
                      try {
                        await api(`/me/projects/${p.id}/publishing/start`, {
                          method: "POST",
                          token: token!,
                          body: JSON.stringify({ stores: ["apple", "google"] }),
                        });
                        load();
                      } finally {
                        setBusy(false);
                      }
                    }}
                  >
                    {d.project.publishStart}
                  </button>
                </>
              ) : (
                p.submissions!.map((s) => (
                  <div key={s.id} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <div className="row">
                      <strong>{d.project.stores[s.store] ?? s.store}</strong>
                      <span className="badge badge-type">{d.project.subStatus[s.status] ?? s.status}</span>
                    </div>
                    {s.status === "waiting_account" && (
                      <form
                        style={{ display: "flex", gap: 6 }}
                        onSubmit={async (e) => {
                          e.preventDefault();
                          const input = (e.currentTarget.elements.namedItem("ref") as HTMLInputElement).value;
                          setBusy(true);
                          try {
                            await api(`/me/projects/${p.id}/publishing/account`, {
                              method: "POST",
                              token: token!,
                              body: JSON.stringify({ store: s.store, account_ref: input }),
                            });
                            load();
                          } finally {
                            setBusy(false);
                          }
                        }}
                      >
                        <input name="ref" required placeholder={d.project.accountPh} style={{ ...inputStyle, flex: 1, padding: "8px 10px", fontSize: 13 }} />
                        <button className="lang-toggle" disabled={busy} type="submit">{d.project.accountSave}</button>
                      </form>
                    )}
                  </div>
                ))
              )}
            </div>
          )}
        </div>
      )}

      {/* ---------------- Marketing (marketingLaunch package) ---------------- */}
      {active === "marketing" && (
        <div className="detail-stack">
          <div>
            <h2 style={{ marginTop: 0 }}>{d.project.marketing}</h2>
            <p className="muted small">{d.project.marketingHint}</p>
          </div>
          {(p.campaigns?.length ?? 0) === 0 ? (
            <div>
              <button
                className="btn btn-primary"
                disabled={busy}
                onClick={async () => {
                  setBusy(true);
                  try {
                    await api(`/me/projects/${p.id}/marketing/generate`, { method: "POST", token: token! });
                    load();
                  } finally {
                    setBusy(false);
                  }
                }}
              >
                {busy ? d.project.marketingGenerating : d.project.marketingGenerate}
              </button>
            </div>
          ) : (
            p.campaigns!.map((c) => (
              <div className="card" key={c.id}>
                <div style={{ display: "flex", justifyContent: "space-between", gap: 10 }}>
                  <h3 style={{ margin: 0 }}>{c.platform === "google" ? "Google Ads" : "Meta Ads"}</h3>
                  <span className="badge badge-type">{d.project.campaignStatus[c.status] ?? c.status}</span>
                </div>
                <p className="muted small" style={{ margin: 0 }}>
                  {Object.entries(c.strategy).map(([k, v]) => `${k}: ${v}`).join(" · ")}
                </p>
                <div className="tbl-wrap">
                  <table>
                    <tbody>
                      {c.creatives.map((cr) => (
                        <tr key={cr.id}>
                          <td>
                            {d.project.creativeKinds[cr.kind] ?? cr.kind}
                            {cr.locale ? ` · ${cr.locale.toUpperCase()}` : ""}
                          </td>
                          <td className="muted" style={{ whiteSpace: "pre-wrap" }}>{cr.content}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {c.status === "pending_approval" && (
                  <div style={{ display: "flex", gap: 10 }}>
                    {(["approved", "rejected"] as const).map((decision) => (
                      <button
                        key={decision}
                        className={decision === "approved" ? "btn btn-primary" : "btn btn-ghost"}
                        disabled={busy}
                        onClick={async () => {
                          setBusy(true);
                          try {
                            await api(`/me/projects/${p.id}/campaigns/${c.id}/decide`, {
                              method: "POST",
                              token: token!,
                              body: JSON.stringify({ decision }),
                            });
                            load();
                          } finally {
                            setBusy(false);
                          }
                        }}
                      >
                        {decision === "approved" ? d.project.campaignApprove : d.project.campaignReject}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ))
          )}
        </div>
      )}

      {/* ---------------- Activity: change requests, criteria, pipeline ---------------- */}
      {active === "activity" && (
        <div className="detail-stack">
          {(p.change_requests?.length ?? 0) > 0 && (
            <div>
              <h2 style={{ marginTop: 0 }}>{d.project.changesHistory}</h2>
              <div className="tbl-wrap">
                <table>
                  <tbody>
                    {p.change_requests!.map((c) => (
                      <tr key={c.id}>
                        <td style={{ whiteSpace: "nowrap", verticalAlign: "top" }}>
                          {d.project.changesRound} {c.round}
                          <br />
                          <span className="badge" style={c.status === "done" ? { color: "var(--valid)", borderColor: "currentColor" } : c.status === "failed" ? { color: "#B3261E", borderColor: "currentColor" } : {}}>
                            {d.project.crStatus[c.status] ?? c.status}
                          </span>
                        </td>
                        <td style={{ whiteSpace: "pre-wrap" }}>
                          {c.text}
                          {c.price_eur > 0 && <span className="muted small"> · {eur(c.price_eur, locale)}</span>}
                          {c.status === "awaiting_payment" && c.checkout_url && (
                            <p style={{ margin: "8px 0 0" }}><a href={c.checkout_url}>{d.project.changesPayLink}</a></p>
                          )}
                          {c.agent_summary && <p className="muted small" style={{ margin: "8px 0 0" }}>→ {c.agent_summary}</p>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          <div>
            <h2 style={{ marginTop: 0 }}>{d.project.criteria}</h2>
            <div className="tbl-wrap">
              <table>
                <tbody>
                  {p.criteria.map((c) => (
                    <tr key={c.key}>
                      <td>
                        <span className="badge" style={c.status === "passed" ? { color: "var(--valid)", borderColor: "currentColor" } : c.status === "failed" ? { color: "#B3261E", borderColor: "currentColor" } : {}}>
                          {c.status}
                        </span>
                      </td>
                      <td className="muted">{c.criterion}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div>
            <h2 style={{ marginTop: 0 }}>{d.project.timeline}</h2>
            <ul className="aud-list">
              {[...p.runs].reverse().map((r, i) => (
                <li key={i} style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <span aria-hidden style={{ width: 10, height: 10, borderRadius: 99, display: "inline-block", background: runDot[r.status] ?? "var(--border)" }} />
                  <code style={{ fontSize: 13 }}>{r.stage}#{r.attempt}</code>
                  <span className="muted small">{r.status}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}
