"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface Row {
  key: string;
  visitors: number;
}

interface Report {
  days: number;
  totals: { visitors: number; page_views: number; orders_paid: number; revenue_eur: number };
  daily: { day: string; views: number; visitors: number }[];
  funnel: { step: "visit" | "interest" | "quote" | "checkout" | "paid"; visitors: number }[];
  prototypes: { requested: number; viewed: number; made_real: number };
  pages: Row[];
  referrers: Row[];
  campaigns: Row[];
  devices: Row[];
  paid_sources: { key: string; orders: number }[];
}

const RANGES = [1, 7, 30, 90] as const;

/**
 * Traffic and funnel from the first-party analytics (GET /admin/analytics). A visitor is counted
 * per day, so a funnel is read as "of the visitors in this period, how many reached the step".
 */
export function AnalyticsPanel({ token, locale, d }: { token: string; locale: Locale; d: Dict }) {
  const t = d.admin.analytics;
  const [days, setDays] = useState<(typeof RANGES)[number]>(30);
  const [report, setReport] = useState<Report | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    api<Report>(`/admin/analytics?days=${days}`, { token })
      .then((r) => {
        if (alive) {
          setReport(r);
          setFailed(false);
        }
      })
      .catch(() => {
        if (alive) setFailed(true);
      });
    return () => {
      alive = false;
    };
  }, [days, token]);

  if (failed) return <p className="note">{t.failed}</p>;
  if (!report) return <p className="est-empty">{d.admin.loading}</p>;

  const top = report.funnel[0]?.visitors || 1;
  const maxDay = Math.max(1, ...report.daily.map((x) => x.visitors));
  const num = (n: number) => n.toLocaleString(locale);

  return (
    <div style={{ display: "grid", gap: 20 }}>
      <div className="tabs" role="tablist" aria-label={t.range}>
        {RANGES.map((r) => (
          <button key={r} className="tab" role="tab" aria-selected={days === r} onClick={() => setDays(r)}>
            {t.days.replace("{n}", String(r))}
          </button>
        ))}
      </div>

      <div className="grid">
        {([
          [t.visitors, num(report.totals.visitors)],
          [t.pageViews, num(report.totals.page_views)],
          [t.ordersPaid, num(report.totals.orders_paid)],
          [t.revenue, `${num(report.totals.revenue_eur)} €`],
        ] as const).map(([label, value]) => (
          <div className="card" key={label}>
            <span className="cat">{label}</span>
            <strong className="num" style={{ fontSize: 28 }}>{value}</strong>
          </div>
        ))}
      </div>

      <div className="card">
        <span className="cat">{t.funnel}</span>
        <div style={{ display: "grid", gap: 8, marginTop: 10 }}>
          {report.funnel.map((f, i) => (
            <div key={f.step} style={{ display: "grid", gridTemplateColumns: "minmax(90px, 140px) 1fr auto", gap: 10, alignItems: "center" }}>
              <span className="small">{t.steps[f.step]}</span>
              <span style={{ background: "var(--border)", borderRadius: 6, height: 14, overflow: "hidden" }}>
                <span style={{ display: "block", height: "100%", width: `${Math.min(100, (f.visitors / top) * 100)}%`, background: "var(--accent)" }} />
              </span>
              <span className="small num">
                {num(f.visitors)}
                {i > 0 && report.funnel[i - 1].visitors > 0 && (
                  <span className="muted"> · {Math.round((f.visitors / report.funnel[i - 1].visitors) * 100)}%</span>
                )}
              </span>
            </div>
          ))}
        </div>
        <p className="small muted" style={{ margin: "10px 0 0" }}>{t.funnelNote}</p>
      </div>

      {report.daily.length > 1 && (
        <div className="card">
          <span className="cat">{t.perDay}</span>
          <div style={{ display: "flex", alignItems: "flex-end", gap: 3, height: 90, marginTop: 10, overflowX: "auto" }}>
            {report.daily.map((x) => (
              <span
                key={x.day}
                title={`${x.day}: ${x.visitors} / ${x.views}`}
                style={{ flex: "1 0 6px", minWidth: 6, height: `${Math.max(4, (x.visitors / maxDay) * 100)}%`, background: "var(--accent)", borderRadius: 2 }}
              />
            ))}
          </div>
        </div>
      )}

      <div className="grid">
        <List title={t.prototypes} rows={[
          { key: t.protoRequested, visitors: report.prototypes.requested },
          { key: t.protoViewed, visitors: report.prototypes.viewed },
          { key: t.protoMadeReal, visitors: report.prototypes.made_real },
        ]} empty={t.empty} />
        <List title={t.paidSources} rows={report.paid_sources.map((s) => ({ key: s.key, visitors: s.orders }))} empty={t.empty} />
        <List title={t.referrers} rows={report.referrers} empty={t.empty} />
        <List title={t.campaigns} rows={report.campaigns} empty={t.empty} />
        <List title={t.pages} rows={report.pages} empty={t.empty} />
        <List title={t.devices} rows={report.devices} empty={t.empty} />
      </div>
    </div>
  );
}

function List({ title, rows, empty }: { title: string; rows: Row[]; empty: string }) {
  return (
    <div className="card">
      <span className="cat">{title}</span>
      <div className="small" style={{ display: "grid", gap: 4, marginTop: 8 }}>
        {rows.length === 0 && <span className="muted">{empty}</span>}
        {rows.map((r) => (
          <div key={r.key} style={{ display: "flex", justifyContent: "space-between", gap: 10 }}>
            <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{r.key}</span>
            <span className="num">{r.visitors}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
