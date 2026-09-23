"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface Numbers {
  impressions: number;
  clicks: number;
  cost_eur: number;
  conversions: number;
  ctr: number | null;
  cpc_eur: number | null;
}

interface Traffic {
  from: string;
  to: string;
  read_at: string;
  totals: Numbers & { visits: number | null };
  daily: (Numbers & { date: string; visits: number | null })[];
  keywords: (Numbers & {
    text: string;
    match: string;
    status: string;
    quality: number | null;
  })[];
  search_terms: (Numbers & { term: string; status: string })[];
  devices: (Numbers & { key: string })[];
  countries: (Numbers & { key: string })[];
  hours: (Numbers & { key: string })[];
  warnings: string[];
}

const PERIODS = [7, 30, 90] as const;

/**
 * A Google campaign's traffic, read through the API: day by day, per keyword, the searches people
 * actually typed, devices, countries and hours. The point is that nobody needs a Google login to
 * see how an ad is doing. A search term that brings the wrong people can be excluded from here;
 * it lands on the keyword screen as an approved negative and goes to Google with the next Apply.
 */
export function TrafficPanel({
  token,
  locale,
  d,
  campaignId,
}: {
  token: string;
  locale: Locale;
  d: Dict;
  campaignId: number;
}) {
  const t = d.admin.traffic;
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30);
  const [data, setData] = useState<Traffic | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [excluded, setExcluded] = useState<Set<string>>(new Set());
  const [note, setNote] = useState("");

  const tag = locale === "de" ? "de-AT" : "en-GB";
  const money = useMemo(
    () => new Intl.NumberFormat(tag, { style: "currency", currency: "EUR" }),
    [tag],
  );
  const count = useMemo(() => new Intl.NumberFormat(tag), [tag]);
  const pct = (v: number | null) =>
    v === null
      ? "–"
      : `${v.toLocaleString(tag, { maximumFractionDigits: 2 })} %`;
  const eur = (v: number | null) => (v === null ? "–" : money.format(v));

  /** The report, or the reason there is none, worded by the API. */
  const read = useCallback(
    async (fresh: boolean): Promise<{ data: Traffic } | { error: string }> => {
      try {
        return {
          data: await api<Traffic>(
            `/admin/marketing/${campaignId}/traffic?days=${days}${fresh ? "&fresh=1" : ""}`,
            { token },
          ),
        };
      } catch (e) {
        const body =
          e instanceof ApiError
            ? (e.body as { error?: string; message?: string } | null)
            : null;
        return { error: body?.error ?? body?.message ?? "…" };
      }
    },
    [campaignId, days, token],
  );

  const show = (r: { data: Traffic } | { error: string }) => {
    if ("data" in r) {
      setData(r.data);
      setError("");
    } else {
      setError(r.error);
    }
  };

  async function load(fresh = false) {
    setBusy(true);
    show(await read(fresh));
    setBusy(false);
  }

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await read(false);
      if (alive) show(r);
    })();
    return () => {
      alive = false;
    };
  }, [read]);

  async function exclude(term: string) {
    setNote("");
    try {
      await api(`/admin/marketing/${campaignId}/keywords`, {
        method: "POST",
        token,
        body: JSON.stringify({ text: term, negative: true }),
      });
      setExcluded((s) => new Set(s).add(term));
      setNote(t.excludedNote);
    } catch (e) {
      const body =
        e instanceof ApiError
          ? (e.body as { error?: string; message?: string } | null)
          : null;
      setNote(body?.error ?? body?.message ?? "…");
    }
  }

  const head = (
    <div className="ops-toolbar" style={{ marginTop: 0 }}>
      <div className="ops-seg" role="group" aria-label={t.period}>
        {PERIODS.map((p) => (
          <button key={p} aria-pressed={days === p} onClick={() => setDays(p)}>
            {t.days.replace("{n}", String(p))}
          </button>
        ))}
      </div>
      <button
        className="btn btn-ghost btn-sm"
        onClick={() => void load(true)}
        disabled={busy}
      >
        {busy ? "…" : t.refresh}
      </button>
      {data && (
        <span className="muted small">
          {t.readAt.replace(
            "{t}",
            new Date(data.read_at).toLocaleTimeString(tag, {
              hour: "2-digit",
              minute: "2-digit",
            }),
          )}
        </span>
      )}
    </div>
  );

  if (error) {
    return (
      <div>
        {head}
        <p className="note">{error}</p>
      </div>
    );
  }
  if (!data)
    return (
      <div>
        {head}
        <p className="muted small">{d.admin.loading}</p>
      </div>
    );

  const tot = data.totals;
  const peak = Math.max(
    1,
    ...data.daily.map((x) => Math.max(x.clicks, x.visits ?? 0)),
  );
  const hourPeak = Math.max(1, ...data.hours.map((h) => h.clicks));
  const hours = Array.from(
    { length: 24 },
    (_, h) => data.hours.find((x) => x.key === String(h)) ?? null,
  );

  const numbers = (r: Numbers) => (
    <>
      <td className="num" style={{ textAlign: "right" }}>
        {count.format(r.impressions)}
      </td>
      <td className="num" style={{ textAlign: "right" }}>
        {count.format(r.clicks)}
      </td>
      <td className="num" style={{ textAlign: "right" }}>
        {pct(r.ctr)}
      </td>
      <td className="num" style={{ textAlign: "right" }}>
        {eur(r.cpc_eur)}
      </td>
      <td className="num" style={{ textAlign: "right" }}>
        {money.format(r.cost_eur)}
      </td>
    </>
  );
  const numberHeads = (
    <>
      <th style={{ textAlign: "right" }}>{t.impressions}</th>
      <th style={{ textAlign: "right" }}>{t.clicks}</th>
      <th style={{ textAlign: "right" }}>{t.ctr}</th>
      <th style={{ textAlign: "right" }}>{t.cpc}</th>
      <th style={{ textAlign: "right" }}>{t.cost}</th>
    </>
  );

  const small = (
    title: string,
    rows: (Numbers & { key: string })[],
    label: (k: string) => string,
  ) => (
    <div>
      <h4 style={{ margin: "0 0 6px" }}>{title}</h4>
      {rows.length === 0 ? (
        <p className="muted small">{t.none}</p>
      ) : (
        <table className="table" style={{ width: "100%" }}>
          <thead>
            <tr>
              <th />
              <th style={{ textAlign: "right" }}>{t.clicks}</th>
              <th style={{ textAlign: "right" }}>{t.cost}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.key}>
                <td>{label(r.key)}</td>
                <td className="num" style={{ textAlign: "right" }}>
                  {count.format(r.clicks)}
                </td>
                <td className="num" style={{ textAlign: "right" }}>
                  {money.format(r.cost_eur)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );

  return (
    <div>
      {head}
      {data.warnings.length > 0 && (
        <p className="note small">{data.warnings.join(" · ")}</p>
      )}

      <div className="ops-kpis">
        {(
          [
            [t.impressions, count.format(tot.impressions)],
            [t.clicks, count.format(tot.clicks)],
            [t.ctr, pct(tot.ctr)],
            [t.cpc, eur(tot.cpc_eur)],
            [t.cost, money.format(tot.cost_eur)],
            [t.conversions, count.format(tot.conversions)],
            ...(tot.visits !== null
              ? [[t.visits, count.format(tot.visits)] as const]
              : []),
          ] as const
        ).map(([label, value]) => (
          <div className="ops-kpi" key={label}>
            <span className="ops-kpi-label">{label}</span>
            <strong className="num">{value}</strong>
          </div>
        ))}
      </div>

      <h4 style={{ margin: "0 0 6px" }}>{t.perDay}</h4>
      <div className="ops-bars" role="img" aria-label={t.perDay}>
        {data.daily.map((x) => (
          <div
            key={x.date}
            className="ops-bar"
            title={`${new Date(x.date).toLocaleDateString(tag)}: ${t.clicksN.replace("{n}", String(x.clicks))}${x.visits !== null ? `, ${t.visitsN.replace("{n}", String(x.visits))}` : ""}, ${money.format(x.cost_eur)}`}
          >
            <span
              className="ops-bar-a"
              style={{ height: `${(x.clicks / peak) * 100}%` }}
            />
            {x.visits !== null && (
              <span
                className="ops-bar-b"
                style={{ height: `${(x.visits / peak) * 100}%` }}
              />
            )}
          </div>
        ))}
      </div>
      <p className="muted small" style={{ margin: "4px 0 18px" }}>
        <span className="ops-key ops-key-a" /> {t.clicks}
        {tot.visits !== null && (
          <>
            {"  "}
            <span className="ops-key ops-key-b" /> {t.visitsLegend}
          </>
        )}
        {" · "}
        {t.range
          .replace("{a}", new Date(data.from).toLocaleDateString(tag))
          .replace("{b}", new Date(data.to).toLocaleDateString(tag))}
      </p>

      <h4 style={{ margin: "0 0 6px" }}>{t.keywords}</h4>
      {data.keywords.length === 0 ? (
        <p className="muted small">{t.none}</p>
      ) : (
        <div className="tbl-wrap" style={{ marginBottom: 18 }}>
          <table>
            <thead>
              <tr>
                <th>{t.keyword}</th>
                <th>{t.quality}</th>
                {numberHeads}
              </tr>
            </thead>
            <tbody>
              {data.keywords.map((k) => (
                <tr key={k.text + k.match}>
                  <td>
                    {k.text} <span className="muted small">{k.match}</span>
                    {k.status !== "enabled" && (
                      <span
                        className="badge badge-dim"
                        style={{ marginLeft: 6 }}
                      >
                        {(t.kwStatus as Record<string, string>)[k.status] ?? k.status}
                      </span>
                    )}
                  </td>
                  <td className="num">{k.quality ?? "–"}</td>
                  {numbers(k)}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h4 style={{ margin: "0 0 2px" }}>{t.searchTerms}</h4>
      <p className="muted small" style={{ marginTop: 0 }}>
        {t.searchTermsHint}
      </p>
      {note && <p className="note">{note}</p>}
      {data.search_terms.length === 0 ? (
        <p className="muted small">{t.none}</p>
      ) : (
        <div className="tbl-wrap" style={{ marginBottom: 18 }}>
          <table>
            <thead>
              <tr>
                <th>{t.searchTerm}</th>
                {numberHeads}
                <th />
              </tr>
            </thead>
            <tbody>
              {data.search_terms.map((s) => (
                <tr key={s.term}>
                  <td>
                    {s.term}
                    {s.status === "excluded" && (
                      <span
                        className="badge badge-dim"
                        style={{ marginLeft: 6 }}
                      >
                        {t.isExcluded}
                      </span>
                    )}
                  </td>
                  {numbers(s)}
                  <td style={{ textAlign: "right" }}>
                    {s.status !== "excluded" && (
                      <button
                        className="btn btn-ghost btn-sm"
                        onClick={() => void exclude(s.term)}
                        disabled={excluded.has(s.term)}
                      >
                        {excluded.has(s.term) ? t.excludedShort : t.exclude}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div
        style={{
          display: "grid",
          gap: 16,
          gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
          alignItems: "start",
        }}
      >
        {small(
          t.devices,
          data.devices,
          (k) => (t.device as Record<string, string>)[k] ?? k,
        )}
        {small(t.countries, data.countries, (k) => k)}
        <div>
          <h4 style={{ margin: "0 0 6px" }}>{t.hours}</h4>
          <div className="ops-bars ops-bars-sm" role="img" aria-label={t.hours}>
            {hours.map((h, i) => (
              <div
                key={i}
                className="ops-bar"
                title={`${i}:00 · ${t.clicksN.replace("{n}", String(h?.clicks ?? 0))}`}
              >
                <span
                  className="ops-bar-a"
                  style={{ height: `${((h?.clicks ?? 0) / hourPeak) * 100}%` }}
                />
              </div>
            ))}
          </div>
          <p
            className="muted small num"
            style={{
              display: "flex",
              justifyContent: "space-between",
              margin: "4px 0 0",
            }}
          >
            <span>0</span>
            <span>6</span>
            <span>12</span>
            <span>18</span>
            <span>23</span>
          </p>
        </div>
      </div>
    </div>
  );
}
