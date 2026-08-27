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
import { api, ApiError } from "@/lib/api";
import { featureHints } from "@/lib/featureHints";
import { eur, type Dict, type Locale } from "@/lib/i18n";

interface Refinement {
  off_topic: boolean;
  description: string;
  questions: { q: string; options: string[] }[];
  suggested_features: string[];
}

const MAX_REFINE_ROUNDS = 3;

const FEATURE_KEYS = Object.keys(FEATURES) as FeatureKey[];

export function CreateWizard({ locale, d }: { locale: Locale; d: Dict }) {
  const [idea, setIdea] = useState("");
  const [audience, setAudience] = useState<Audience | null>(null);
  const [platform, setPlatform] = useState<Platform | null>(null);
  const [features, setFeatures] = useState<FeatureKey[]>([]);
  // "Sharpen my idea": one OpenClaw round per click, at most three per idea.
  const [refinement, setRefinement] = useState<Refinement | null>(null);
  const [refining, setRefining] = useState(false);
  const [refineError, setRefineError] = useState<string | null>(null);
  const [answers, setAnswers] = useState<Record<number, string>>({});
  const [rounds, setRounds] = useState(0);
  const [accepted, setAccepted] = useState(false);

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

  const refine = async (withAnswers: boolean) => {
    setRefining(true);
    setRefineError(null);
    try {
      const answerList = withAnswers && refinement
        ? refinement.questions.map((q, i) => (answers[i] ? `${q.q}: ${answers[i]}` : null)).filter((a): a is string => !!a)
        : [];
      const res = await api<Refinement>("/quotes/refine", {
        method: "POST",
        body: JSON.stringify({ text: idea.trim().slice(0, 800), locale, answers: answerList }),
      });
      setRefinement(res);
      setAnswers({});
      setAccepted(false);
      setRounds((r) => r + 1);
    } catch (e) {
      setRefineError(e instanceof ApiError && e.status === 429 ? w.refineLimit : w.refineUnavailable);
    } finally {
      setRefining(false);
    }
  };
  const canRefine = idea.trim().length >= 30 && !refining && rounds < MAX_REFINE_ROUNDS;
  const answeredAny = refinement ? Object.keys(answers).length > 0 : false;
  // Typed-text hints: instant, local; dismissed ones stay hidden for this idea.
  const [dismissedHints, setDismissedHints] = useState<FeatureKey[]>([]);
  const hints = useMemo(
    () => featureHints(idea).filter((f) => !features.includes(f) && !dismissedHints.includes(f)),
    [idea, features, dismissedHints],
  );
  const newFeatures = (refinement?.suggested_features ?? []).filter(
    (f): f is FeatureKey => (FEATURE_KEYS as string[]).includes(f) && !features.includes(f as FeatureKey),
  );

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
          <div className="idea-tools">
            <span className="small muted">{w.ideaExamplesLabel}</span>
            {w.ideaExamples.map((ex) => (
              <button type="button" className="lang-toggle" key={ex} onClick={() => setIdea(ex)}>
                {ex.length > 46 ? `${ex.slice(0, 44)}…` : ex}
              </button>
            ))}
          </div>
          {idea.trim().length > 0 && idea.trim().length < 40 && (
            <p className="small muted" style={{ margin: "8px 0 0" }}>{w.ideaHint}</p>
          )}
          <div className="idea-tools" style={{ marginTop: 10 }}>
            <button type="button" className="btn btn-ghost" disabled={!canRefine} onClick={() => refine(false)}>
              {refining ? w.refining : w.refine}
            </button>
            <span className="small muted">{w.refineNote}</span>
          </div>
          {refineError && <p className="note" style={{ marginTop: 10 }}>{refineError}</p>}

          {refinement && (
            <div className="card" style={{ marginTop: 14, gap: 12 }}>
              {refinement.off_topic ? (
                <p className="small muted" style={{ margin: 0 }}>{w.refineOffTopic}</p>
              ) : (
                <>
                  <span className="cat">{w.refineTitle}</span>
                  <p style={{ margin: 0, whiteSpace: "pre-wrap" }}>{refinement.description}</p>
                  <div className="idea-tools">
                    <button
                      type="button"
                      className="btn btn-primary"
                      disabled={accepted}
                      onClick={() => { setIdea(refinement.description); setAccepted(true); }}
                    >
                      {accepted ? w.refineAccepted : w.refineAccept}
                    </button>
                    {newFeatures.length > 0 && (
                      <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => setFeatures((cur) => [...cur, ...newFeatures])}
                      >
                        {w.refineFeatures} {newFeatures.map((f) => w.featureLabels[f]).join(", ")}
                      </button>
                    )}
                  </div>
                  {refinement.questions.length > 0 && (
                    <>
                      <span className="small muted">{w.refineQuestions}</span>
                      {refinement.questions.map((q, i) => (
                        <div key={i}>
                          <p className="small" style={{ margin: "0 0 6px", fontWeight: 600 }}>{q.q}</p>
                          <div className="choices">
                            {q.options.map((o) => (
                              <label className="choice" key={o} style={{ padding: "6px 12px", fontSize: 14 }}>
                                <input type="radio" name={`rq${i}`} checked={answers[i] === o} onChange={() => setAnswers((a) => ({ ...a, [i]: o }))} />
                                {o}
                              </label>
                            ))}
                          </div>
                        </div>
                      ))}
                      {answeredAny && rounds < MAX_REFINE_ROUNDS && (
                        <button type="button" className="btn btn-ghost" disabled={refining} onClick={() => refine(true)}>
                          {refining ? w.refining : w.refineAgain}
                        </button>
                      )}
                    </>
                  )}
                </>
              )}
            </div>
          )}
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
          {hints.length > 0 && (
            <div className="idea-tools" style={{ margin: "0 0 10px" }}>
              <span className="small muted">{w.hintsLabel}</span>
              {hints.map((f) => (
                <button type="button" className="lang-toggle hint" key={f} onClick={() => toggleFeature(f)}>
                  + {w.featureLabels[f]} · {eur(FEATURES[f].cost, locale)}
                </button>
              ))}
              <button type="button" className="small muted" style={{ background: "none", border: 0, cursor: "pointer" }} onClick={() => setDismissedHints((d) => [...d, ...hints])}>
                {w.hintsDismiss}
              </button>
            </div>
          )}
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
