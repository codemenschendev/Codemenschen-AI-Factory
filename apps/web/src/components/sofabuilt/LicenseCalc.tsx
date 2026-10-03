"use client";

import { useState } from "react";
import type { SbDict } from "@/dictionaries/sofabuilt";

/**
 * Yearly licences against one plugin of your own: Patrick's "cool chart", the honest version. Two
 * growing bars over the years; the own plugin is a one-time price, no care plan counted.
 */
export function LicenseCalc({ t, locale }: { t: SbDict["license"]; locale: string }) {
  const [yearly, setYearly] = useState(199);
  const [sites, setSites] = useState(3);
  const [build, setBuild] = useState(690);
  const [years, setYears] = useState(3);
  const fmt = (n: number) => (locale === "de" ? `${n.toLocaleString("de-AT")} €` : `€${n.toLocaleString("en-IE")}`);
  const licences = yearly * sites * years;
  const max = Math.max(licences, build, 1);
  const rows = Array.from({ length: years }, (_, i) => ({ year: i + 1, lic: yearly * sites * (i + 1) }));

  const slider = (label: string, value: number, set: (n: number) => void, min: number, maxV: number, step: number, money = true) => (
    <label className="sb-calc-field">
      <span>
        {label} <b>{money ? fmt(value) : value}</b>
      </span>
      <input type="range" min={min} max={maxV} step={step} value={value} onChange={(e) => set(Number(e.target.value))} />
    </label>
  );

  return (
    <div className="sb-calc">
      <div className="sb-calc-inputs">
        {slider(t.yearly, yearly, setYearly, 49, 999, 10)}
        {slider(t.sites, sites, setSites, 1, 25, 1, false)}
        {slider(t.build, build, setBuild, 290, 1500, 10)}
        {slider(t.years, years, setYears, 1, 5, 1, false)}
      </div>
      <div className="sb-calc-out">
        <div className="sb-calc-chart" aria-hidden="true">
          {rows.map((r) => (
            <div key={r.year} className="sb-calc-col">
              <div className="sb-calc-bars">
                <span className="sb-bar sb-bar-lic" style={{ height: `${(r.lic / max) * 100}%` }} />
                <span className="sb-bar sb-bar-own" style={{ height: `${(build / max) * 100}%` }} />
              </div>
              <small>{r.year}</small>
            </div>
          ))}
        </div>
        <dl className="sb-calc-sum">
          <div>
            <dt><i className="sb-dot sb-dot-lic" />{t.licenceTotal.replace("{years}", String(years))}</dt>
            <dd>{fmt(licences)}</dd>
          </div>
          <div>
            <dt><i className="sb-dot sb-dot-own" />{t.ownTotal}</dt>
            <dd>{fmt(build)}</dd>
          </div>
          <div className="sb-calc-diff">
            <dt>{t.saved}</dt>
            <dd>{fmt(licences - build)}</dd>
          </div>
        </dl>
      </div>
      <p className="sb-calc-note">{t.note}</p>
    </div>
  );
}
