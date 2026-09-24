"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

type Platform = "meta" | "google";
type Event = "lead" | "purchase";

interface Row {
  id: number;
  platform: Platform;
  event: Event;
  status: "pending" | "sent" | "failed" | "expired";
  value_eur: number | null;
  happened_at: string;
  sent_at: string | null;
  attempts: number;
  error: string | null;
}

interface Report {
  setup: Record<Platform, Record<Event, string | null>>;
  counts: { platform: Platform; event: Event; status: Row["status"]; count: number; value_eur: number }[];
  rows: Row[];
}

type Settings = Record<string, { value: string; source: string }>;

const IDS = ["gtm_id", "meta_pixel_id", "google_conversion_lead", "google_conversion_purchase", "google_conversion_goal"] as const;

/**
 * Conversion tracking: whether Meta and Google are ready to take results, and what was reported.
 * The ids are set here; the tokens stay on the server. A result that happened before a platform
 * was set up waits and goes out on its own once it is.
 */
export function ConversionsPanel({ token, locale, d }: { token: string; locale: Locale; d: Dict }) {
  const c = d.admin.conversions;
  const [report, setReport] = useState<Report | null>(null);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);

  const tag = locale === "de" ? "de-AT" : "en-GB";
  const money = useMemo(() => new Intl.NumberFormat(tag, { style: "currency", currency: "EUR" }), [tag]);
  const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString(tag, { dateStyle: "short", timeStyle: "short" }) : "–");

  const fetchAll = useCallback(
    () =>
      Promise.all([
        api<Report>("/admin/conversions", { token }).catch(() => null),
        api<{ settings: Settings }>("/admin/ads/settings", { token }).catch(() => null),
      ]),
    [token],
  );

  useEffect(() => {
    let alive = true;
    void (async () => {
      const [r, s] = await fetchAll();
      if (!alive) return;
      if (r) setReport(r);
      if (s) setSettings(s.settings);
    })();
    return () => {
      alive = false;
    };
  }, [fetchAll]);

  async function reload() {
    const [r, s] = await fetchAll();
    if (r) setReport(r);
    if (s) setSettings(s.settings);
  }

  const fail = (e: unknown) => {
    const body = e instanceof ApiError ? (e.body as { error?: string; message?: string } | null) : null;
    setNote(body?.error ?? body?.message ?? "…");
  };

  async function save(values: Record<string, string>) {
    setBusy(true);
    setNote("");
    try {
      await api("/admin/ads/settings", { method: "POST", token, body: JSON.stringify(values) });
      await api("/admin/conversions/retry", { method: "POST", token });
      await reload();
      setNote(c.saved);
    } catch (e) {
      fail(e);
    }
    setBusy(false);
  }

  async function retry() {
    setBusy(true);
    setNote("");
    try {
      const r = await api<{ queued: number }>("/admin/conversions/retry", { method: "POST", token });
      setNote(c.retried.replace("{n}", String(r.queued)));
      window.setTimeout(() => void reload(), 3000);
    } catch (e) {
      fail(e);
    }
    setBusy(false);
  }

  if (!report) return <p className="est-empty">{d.admin.loading}</p>;

  const sum = (p: Platform, e: Event, s: Row["status"]) =>
    report.counts.filter((x) => x.platform === p && x.event === e && x.status === s).reduce((n, x) => n + x.count, 0);
  const value = (p: Platform) =>
    report.counts.filter((x) => x.platform === p && x.event === "purchase" && x.status === "sent").reduce((n, x) => n + x.value_eur, 0);
  const tone = (s: Row["status"]) => (s === "sent" ? "badge-live" : s === "failed" ? "badge-bad" : s === "pending" ? "badge-wait" : "badge-dim");
  const waiting = report.rows.some((r) => r.status === "pending" || r.status === "failed");

  return (
    <div>
      <p className="muted small" style={{ marginTop: 0, maxWidth: 720 }}>{c.intro}</p>

      <div className="ops-kpis">
        {(["meta", "google"] as const).map((p) => (
          <div className="ops-kpi" key={p} data-alert={report.setup[p].lead || report.setup[p].purchase ? "yes" : undefined}>
            <span className="ops-kpi-label">{p === "meta" ? "Meta" : "Google Ads"}</span>
            <strong style={{ fontSize: 16 }}>{report.setup[p].lead || report.setup[p].purchase ? c.notReady : c.ready}</strong>
            <span className="ops-kpi-sub">
              {report.setup[p].lead || report.setup[p].purchase
                ? c.missing.replace("{x}", [report.setup[p].lead, report.setup[p].purchase].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(", "))
                : c.sentSummary
                    .replace("{l}", String(sum(p, "lead", "sent")))
                    .replace("{p}", String(sum(p, "purchase", "sent")))
                    .replace("{eur}", money.format(value(p)))}
            </span>
          </div>
        ))}
      </div>

      {settings && (
        <form
          className="card"
          style={{ marginBottom: 18 }}
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            void save(Object.fromEntries(IDS.map((k) => [k, String(f.get(k) ?? "")])));
          }}
        >
          <h4 style={{ margin: "0 0 4px" }}>{c.setupTitle}</h4>
          <p className="muted small" style={{ margin: "0 0 12px" }}>{c.setupHint}</p>
          {IDS.map((key) => (
            <label key={key} className="small" style={{ display: "flex", flexWrap: "wrap", gap: "4px 10px", alignItems: "center", marginBottom: 8 }}>
              <span style={{ flex: "1 0 220px" }}>{c.fields[key]}</span>
              <input name={key} defaultValue={settings[key]?.value ?? ""} style={{ width: 200, maxWidth: "100%" }} />
              <span className="muted">{settings[key]?.source === "panel" ? d.admin.fromPanel : settings[key]?.source === "env" ? d.admin.fromEnv : d.admin.notSet}</span>
            </label>
          ))}
          <p className="muted small" style={{ margin: "4px 0 10px" }}>{c.tokenHint}</p>
          <button className="btn btn-ghost btn-sm" disabled={busy}>{c.save}</button>
        </form>
      )}

      <div className="ops-toolbar">
        <h4 style={{ margin: 0 }}>{c.latest}</h4>
        {waiting && (
          <button className="btn btn-ghost btn-sm" onClick={() => void retry()} disabled={busy}>{c.retry}</button>
        )}
      </div>
      {note && <p className="note">{note}</p>}

      <div className="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>{c.when}</th>
              <th>{c.platform}</th>
              <th>{c.event}</th>
              <th style={{ textAlign: "right" }}>{c.value}</th>
              <th>{c.status}</th>
              <th>{c.detail}</th>
            </tr>
          </thead>
          <tbody>
            {report.rows.map((r) => (
              <tr key={r.id}>
                <td className="num">{when(r.happened_at)}</td>
                <td>{r.platform === "meta" ? "Meta" : "Google"}</td>
                <td>{c.events[r.event]}</td>
                <td className="num" style={{ textAlign: "right" }}>{r.value_eur !== null ? money.format(r.value_eur) : ""}</td>
                <td><span className={`badge ${tone(r.status)}`}>{c.statuses[r.status]}</span></td>
                <td className="small muted" style={{ whiteSpace: "normal", maxWidth: 420 }}>
                  {r.status === "sent" ? when(r.sent_at) : r.error}
                  {r.attempts > 1 && r.status !== "sent" && <span> · {c.attemptsN.replace("{n}", String(r.attempts))}</span>}
                </td>
              </tr>
            ))}
            {report.rows.length === 0 && (
              <tr><td colSpan={6} className="muted">{c.empty}</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
