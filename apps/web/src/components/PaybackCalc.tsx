"use client";

import { useMemo, useState } from "react";
import type { Dict, Locale } from "@/lib/i18n";

type T = Dict["appDev"]["payback"];

const MONTHS = 24;
const fill = (s: string, v: Record<string, string | number>) => s.replace(/\{(\w+)\}/g, (_, k) => String(v[k] ?? ""));

/**
 * When an app earns its money back (Patrick, 2026-09-24: "cool charts" like a business plan).
 * The visitor sets the one-time development price, the running costs, the subscription price
 * and the number of paying users; the chart shows the app's balance month by month. A plain
 * example: users are constant from the first month, no taxes, no ad budget, as the note says.
 */
export function PaybackCalc({ t, locale, devMin, devDefault }: { t: T; locale: Locale; devMin: number; devDefault: number }) {
  const [dev, setDev] = useState(devDefault);
  const [running, setRunning] = useState(19);
  const [price, setPrice] = useState(5);
  const [users, setUsers] = useState(40);
  const [fee, setFee] = useState(true);
  const money = (n: number) =>
    new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);

  const r = useMemo(() => {
    const perUser = price * (fee ? 0.85 : 1);
    const monthly = users * perUser - running;
    const balance = Array.from({ length: MONTHS + 1 }, (_, m) => -dev + monthly * m);
    const cover = perUser > 0 ? Math.ceil(running / perUser) : null;
    const back = monthly > 0 ? Math.ceil(dev / monthly) : null;
    return { balance, cover, back, profit: balance[MONTHS] };
  }, [dev, running, price, users, fee]);

  // The chart: balance over 24 months, zero line, the month the line crosses it.
  const W = 640, H = 240, P = 28;
  const lo = Math.min(0, ...r.balance), hi = Math.max(0, ...r.balance);
  const span = hi - lo || 1;
  const x = (m: number) => P + (m / MONTHS) * (W - 2 * P);
  const y = (v: number) => H - P - ((v - lo) / span) * (H - 2 * P);
  const line = r.balance.map((v, m) => `${m ? "L" : "M"}${x(m).toFixed(1)},${y(v).toFixed(1)}`).join(" ");
  const area = `${line} L${x(MONTHS)},${y(0)} L${x(0)},${y(0)} Z`;
  const backIn = r.back !== null && r.back <= MONTHS;

  const slider = (label: string, value: number, set: (n: number) => void, min: number, max: number, step: number, shown: string) => (
    <label className="pb-field">
      <span className="pb-label">
        {label}
        <b>{shown}</b>
      </span>
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => set(Number(e.target.value))} />
    </label>
  );

  return (
    <div className="pb reveal">
      <div className="pb-controls">
        {slider(t.dev, dev, setDev, devMin, 1500, 10, money(dev))}
        {slider(t.running, running, setRunning, 0, 99, 1, money(running))}
        {slider(t.price, price, setPrice, 1, 30, 0.5, money(price).replace(/,00|\.00/, ""))}
        {slider(t.users, users, setUsers, 0, 500, 5, String(users))}
        <label className="pb-check">
          <input type="checkbox" checked={fee} onChange={(e) => setFee(e.target.checked)} />
          {t.storeFee}
        </label>
      </div>
      <div className="pb-result">
        <div className="pb-kpis">
          <div>
            <small>{t.coverLabel}</small>
            <b>{r.cover === null ? "-" : fill(t.coverValue, { n: r.cover })}</b>
          </div>
          <div>
            <small>{t.backLabel}</small>
            <b className={backIn ? "pb-good" : "pb-wait"}>
              {r.back === null ? t.backNever : r.back <= 1 ? t.backOne : fill(t.backValue, { n: r.back })}
            </b>
          </div>
          <div>
            <small>{t.profitLabel}</small>
            <b className={r.profit >= 0 ? "pb-good" : "pb-wait"}>{money(r.profit)}</b>
          </div>
        </div>
        <svg className="pb-chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-label={t.chartLabel}>
          <defs>
            <linearGradient id="pbFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stopColor="#4f46e5" stopOpacity=".28" />
              <stop offset="1" stopColor="#4f46e5" stopOpacity="0" />
            </linearGradient>
          </defs>
          <line x1={P} x2={W - P} y1={y(0)} y2={y(0)} className="pb-zero" />
          <path d={area} fill="url(#pbFill)" />
          <path d={line} className="pb-line" />
          {backIn && r.back !== null && (
            <g>
              <circle cx={x(r.back)} cy={y(r.balance[r.back])} r="6" className="pb-dot" />
            </g>
          )}
          {[0, 6, 12, 18, 24].map((m) => (
            <text key={m} x={x(m)} y={H - 6} textAnchor="middle" className="pb-axis">
              {m === 0 ? t.month + " 0" : m}
            </text>
          ))}
        </svg>
        <p className="pb-note">{t.note}</p>
      </div>
    </div>
  );
}
