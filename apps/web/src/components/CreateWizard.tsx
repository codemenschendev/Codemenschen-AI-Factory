"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import {
  FEATURES,
  HOSTING_MONTHLY,
  estimate,
  type Audience,
  type FeatureKey,
  type Platform,
} from "@ai-factory/pricing";
import { eur, type Dict, type Locale } from "@/lib/i18n";

const FEATURE_KEYS = Object.keys(FEATURES) as FeatureKey[];

export function CreateWizard({ locale, d }: { locale: Locale; d: Dict }) {
  const [idea, setIdea] = useState("");
  const [audience, setAudience] = useState<Audience | null>(null);
  const [platform, setPlatform] = useState<Platform | null>(null);
  const [features, setFeatures] = useState<FeatureKey[]>([]);

  const est = useMemo(
    () =>
      audience && platform
        ? estimate({ audience, platform, features })
        : null,
    [audience, platform, features],
  );

  const missing = [
    !idea.trim() && d.wizard.idea,
    !audience && d.wizard.audience,
    !platform && d.wizard.platform,
  ].filter(Boolean) as string[];

  const toggleFeature = (f: FeatureKey) =>
    setFeatures((cur) =>
      cur.includes(f) ? cur.filter((x) => x !== f) : [...cur, f],
    );

  const ready = est !== null && idea.trim().length > 0;
  const w = d.wizard;

  return (
    <div className="wizard-cols">
      <div>
        <div className="field">
          <label htmlFor="idea">{w.idea}</label>
          <textarea
            id="idea"
            value={idea}
            placeholder={w.ideaPh}
            onChange={(e) => setIdea(e.target.value)}
          />
        </div>

        <div className="field">
          <span className="field-label">{w.audience}</span>
          <div className="choices" role="radiogroup" aria-label={w.audience}>
            {(Object.keys(w.audOpts) as Audience[]).map((v) => (
              <label className="choice" key={v}>
                <input
                  type="radio"
                  name="aud"
                  checked={audience === v}
                  onChange={() => setAudience(v)}
                />
                {w.audOpts[v]}
              </label>
            ))}
          </div>
        </div>

        <div className="field">
          <span className="field-label">{w.platform}</span>
          <div className="choices" role="radiogroup" aria-label={w.platform}>
            {(Object.keys(w.platOpts) as Platform[]).map((v) => (
              <label className="choice" key={v}>
                <input
                  type="radio"
                  name="plat"
                  checked={platform === v}
                  onChange={() => setPlatform(v)}
                />
                {w.platOpts[v]}
              </label>
            ))}
          </div>
        </div>

        <div className="field">
          <span className="field-label">{w.features}</span>
          <div className="choices">
            {FEATURE_KEYS.map((f) => (
              <label className="choice" key={f}>
                <input
                  type="checkbox"
                  checked={features.includes(f)}
                  onChange={() => toggleFeature(f)}
                />
                {w.featureLabels[f]}
                <span className="cost">+{eur(FEATURES[f].cost, locale)}</span>
              </label>
            ))}
          </div>
        </div>
      </div>

      <aside className="est-panel">
        <h3 style={{ margin: 0 }}>{w.estTitle}</h3>
        {!est ? (
          <p className="est-empty">
            {w.estMissing} <strong>{missing.join(" · ")}</strong>
          </p>
        ) : (
          <>
            <div className="row">
              <span className="muted">{w.estPrice}</span>
              <strong>{eur(est.price, locale)}</strong>
            </div>
            <div className="row">
              <span className="muted">{w.estWeeks}</span>
              <strong>
                {est.weeksLo}–{est.weeksHi} {d.detail.weeksUnit}
              </strong>
            </div>
            <hr />
            <div className="row">
              <span className="muted">{w.estType}</span>
              <span className="badge badge-type">
                {est.appType === "A" ? d.detail.typeA : d.detail.typeB}
              </span>
            </div>
            <div className="row">
              <span className="muted">{w.estHosting}</span>
              <strong>
                {est.appType === "A"
                  ? w.estHostingA
                  : `${eur(HOSTING_MONTHLY.B, locale)}/${locale === "de" ? "Monat" : "month"}`}
              </strong>
            </div>
            <hr />
            <p className="small muted" style={{ margin: 0 }}>
              {w.estNote}
            </p>
            {ready ? (
              <Link
                className="btn btn-primary btn-block"
                href={`/${locale}/checkout?custom=1`}
                onClick={() => {
                  sessionStorage.setItem(
                    "aifactory-custom",
                    JSON.stringify({ idea, audience, platform, features, est }),
                  );
                }}
              >
                {w.cta}
              </Link>
            ) : (
              <span className="btn btn-block" aria-disabled="true">
                {w.cta}
              </span>
            )}
          </>
        )}
      </aside>
    </div>
  );
}
