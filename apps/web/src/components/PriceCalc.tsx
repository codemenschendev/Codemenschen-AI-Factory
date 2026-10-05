"use client";

import { useState } from "react";
import { estimate, FEATURES, HOSTING_MONTHLY, type FeatureKey, type Platform } from "@ai-factory/pricing";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The price beside the idea, the way the Sofabuilt desk shows it: the visitor picks where the
 * app runs and what it needs, the total follows at once. Same engine as the real quote
 * (Estimator::estimate), so the number here is the number at checkout for that scope.
 */
export function PriceCalc({ d, locale }: { d: Dict; locale: Locale }) {
  const t = d.proto.calc;
  const w = d.wizard;
  const [platform, setPlatform] = useState<Platform>("mobile");
  const [features, setFeatures] = useState<FeatureKey[]>([]);
  const money = (n: number) =>
    new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);
  const est = estimate({ audience: "consumer", platform, features });
  const monthly = HOSTING_MONTHLY[est.appType];
  const toggle = (f: FeatureKey) => setFeatures((fs) => (fs.includes(f) ? fs.filter((x) => x !== f) : [...fs, f]));

  return (
    <div className="pp-card pp-calc">
      <h2>{t.title}</h2>
      <div className="pp-calc-plat" role="radiogroup" aria-label={w.platform}>
        {(["mobile", "web", "both"] as Platform[]).map((p) => (
          <button key={p} type="button" role="radio" aria-checked={platform === p} onClick={() => setPlatform(p)}>
            {w.platOpts[p]}
          </button>
        ))}
      </div>
      <ul className="pp-calc-feats">
        {(Object.keys(FEATURES) as FeatureKey[]).map((f) => (
          <li key={f}>
            <label>
              <input type="checkbox" checked={features.includes(f)} onChange={() => toggle(f)} />
              {w.featureLabels[f]}
            </label>
          </li>
        ))}
      </ul>
      <dl className="pp-calc-sum">
        <div>
          <dt>{t.once}</dt>
          <dd className="pp-calc-total">{money(est.price)}</dd>
        </div>
        <div>
          <dt>{t.monthly}</dt>
          <dd>{monthly ? fill(t.perMonth, { price: money(monthly) }) : t.noMonthly}</dd>
        </div>
        <div>
          <dt>{t.delivery}</dt>
          <dd>{fill(t.days, { lo: est.daysLo, hi: est.daysHi })}</dd>
        </div>
      </dl>
      <p className="pp-calc-note">{monthly ? t.noteServer : t.note}</p>
    </div>
  );
}

const fill = (s: string, v: Record<string, string | number>) => s.replace(/\{(\w+)\}/g, (_, k) => String(v[k] ?? ""));
