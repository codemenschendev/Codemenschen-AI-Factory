"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Locale } from "@/lib/i18n";

type Settings = {
  desk_open: boolean;
  offered: string[];
  price_pct: number;
  max_build_eur: number;
  turns_per_day: number;
  care_trial_months: number;
  care_edits_per_month: number;
  rate_wordpress: number;
  rate_shopify: number;
  rate_chrome: number;
  rate_app: number;
  token_pct: number;
  mult_wordpress: number;
  mult_shopify: number;
  mult_chrome: number;
  mult_app: number;
};
type Stats = { chats_today: number; chats_7d: number; quotes_7d: number; paid_orders: number; paid_eur: number; test_orders: number; care_active: number };

type BuildRow = { name: string; stack: string; status: string; runs: number; real_minutes: number; estimated_minutes: number | null; real_tokens_k: number; estimated_tokens_k: number | null; real_token_eur: number; tokens_measured: boolean };
type Builds = { rows: BuildRow[]; ratios: Record<string, number> };

const PLATFORMS = ["wordpress", "shopify", "chrome"] as const;

const T = {
  en: {
    title: "Sofabuilt",
    lede: "Sofabuilt's own switches. Same system as Appmitki; nothing here changes Appmitki.",
    stats: { chats_today: "Desk chats today", chats_7d: "Desk chats, 7 days", quotes_7d: "Quotes, 7 days", paid_orders: "Paid orders", paid_eur: "Revenue", test_orders: "Test orders", care_active: "Care active" },
    desk: "Desk open for new chats",
    deskHint: "Closed: the desk answers that it is closed. Chats already open go on.",
    offered: "Platforms on the desk",
    platforms: { wordpress: "WordPress", shopify: "Shopify", chrome: "Chrome" },
    price: "Price level (%)",
    priceHint: "Scales every part's price. 100 is the price list; 80 is 20% cheaper.",
    max: "Largest single build (EUR)",
    maxHint: "Above this the desk suggests splitting the work.",
    turns: "Desk answers per day, all visitors",
    turnsHint: "Caps what the desk can spend on the model in a day.",
    trial: "Free Care months at checkout",
    edits: "Changes included in Care per month",
    rates: "Hourly rate per platform (EUR)",
    ratesHint: "A part costs its build minutes, estimated by the desk for each idea, times this rate. At 60 a minute is a euro.",
    rateApp: "Apps (Appmitki)",
    mults: "Factor on build time plus tokens",
    multsHint: "1 is the plain calculation. Apps sell at 10: a todo app is about 400 EUR.",
    tokens: "AI token cost in the price (%)",
    tokensHint: "The tokens the desk estimates per part, at the list price of Claude Sonnet 5.5 ($2 in, $10 out per million). 100 is at cost, 0 leaves it out.",
    builds: "Real builds against the estimate",
    buildsHint: "The minutes the AI really worked in the build stages (spec, design, code, tests, fixes) and every token it used, cache included, at the list price. Tokens are counted in full from 6 October 2026; older builds show only part of them.",
    cols: ["Project", "Stack", "Real min", "Estimated min", "Real tokens", "Estimated tokens", "Token cost"],
    ratio: "Real time is {pct} of the estimate ({stack}, median)",
    partial: "partly counted",
    save: "Save",
    saved: "Saved.",
    failed: "Not saved. Check the values.",
  },
  de: {
    title: "Sofabuilt",
    lede: "Die eigenen Schalter von Sofabuilt. Dasselbe System wie Appmitki; hier ändert sich nichts an Appmitki.",
    stats: { chats_today: "Desk-Chats heute", chats_7d: "Desk-Chats, 7 Tage", quotes_7d: "Angebote, 7 Tage", paid_orders: "Bezahlte Aufträge", paid_eur: "Umsatz", test_orders: "Testaufträge", care_active: "Wartung aktiv" },
    desk: "Desk offen für neue Chats",
    deskHint: "Geschlossen: der Desk sagt, dass er geschlossen ist. Offene Chats laufen weiter.",
    offered: "Plattformen im Desk",
    platforms: { wordpress: "WordPress", shopify: "Shopify", chrome: "Chrome" },
    price: "Preisniveau (%)",
    priceHint: "Skaliert den Preis jedes Teils. 100 ist die Preisliste; 80 ist 20 % günstiger.",
    max: "Größter einzelner Auftrag (EUR)",
    maxHint: "Darüber schlägt der Desk vor, die Arbeit zu teilen.",
    turns: "Desk-Antworten pro Tag, alle Besucher",
    turnsHint: "Begrenzt, was der Desk an einem Tag für das Modell ausgeben kann.",
    trial: "Gratis-Monate Wartung im Checkout",
    edits: "Änderungen pro Monat in der Wartung",
    rates: "Stundensatz pro Plattform (EUR)",
    ratesHint: "Ein Teil kostet seine Bauminuten, vom Desk für jede Idee geschätzt, mal diesen Satz. Bei 60 ist eine Minute ein Euro.",
    rateApp: "Apps (Appmitki)",
    mults: "Faktor auf Bauzeit plus Tokens",
    multsHint: "1 ist die reine Rechnung. Apps verkaufen mit 10: eine To-do-App kostet etwa 400 EUR.",
    tokens: "KI-Tokenkosten im Preis (%)",
    tokensHint: "Die Tokens, die der Desk pro Teil schätzt, zum Listenpreis von Claude Sonnet 5.5 (2 $ rein, 10 $ raus pro Million). 100 ist zum Selbstkostenpreis, 0 lässt sie weg.",
    builds: "Echte Builds gegen die Schätzung",
    buildsHint: "Die Minuten, die die KI in den Build-Schritten (Spezifikation, Design, Code, Tests, Korrekturen) wirklich gearbeitet hat, und alle Tokens samt Cache zum Listenpreis. Tokens werden ab 6. Oktober 2026 vollständig gezählt; ältere Builds zeigen nur einen Teil.",
    cols: ["Projekt", "Stack", "Echt Min.", "Geschätzt Min.", "Echte Tokens", "Geschätzte Tokens", "Tokenkosten"],
    ratio: "Echte Zeit ist {pct} der Schätzung ({stack}, Median)",
    partial: "teilweise gezählt",
    save: "Speichern",
    saved: "Gespeichert.",
    failed: "Nicht gespeichert. Prüf die Werte.",
  },
};

/** Sofabuilt's settings in the admin console (API /admin/sofabuilt). */
export function SofabuiltPanel({ token, locale }: { token: string; locale: Locale }) {
  const t = T[locale === "de" ? "de" : "en"];
  const [s, setS] = useState<Settings | null>(null);
  const [stats, setStats] = useState<Stats | null>(null);
  const [builds, setBuilds] = useState<Builds | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  useEffect(() => {
    api<{ settings: Settings; stats: Stats; builds?: Builds }>("/admin/sofabuilt", { token })
      .then((r) => {
        setS(r.settings);
        setStats(r.stats);
        setBuilds(r.builds ?? null);
      })
      .catch(() => setNote(t.failed));
  }, [token, t.failed]);

  async function save() {
    if (!s) return;
    setBusy(true);
    setNote(null);
    try {
      const r = await api<{ settings: Settings }>("/admin/sofabuilt", { method: "POST", token, body: JSON.stringify(s) });
      setS(r.settings);
      setNote(t.saved);
    } catch {
      setNote(t.failed);
    } finally {
      setBusy(false);
    }
  }

  if (!s) return <p className="small muted">{note ?? "…"}</p>;
  const num = (key: keyof Settings, label: string, hint?: string, min = 0, max = 100000) => (
    <label key={key} style={{ display: "grid", gap: 4 }}>
      <span className="small">{label}</span>
      <input type="number" min={min} max={max} value={s[key] as number} onChange={(e) => setS({ ...s, [key]: Number(e.target.value) })} style={{ maxWidth: 180 }} />
      {hint && <span className="small muted">{hint}</span>}
    </label>
  );

  return (
    <div>
      <h2 style={{ marginTop: 0 }}>{t.title}</h2>
      <p className="small muted">{t.lede}</p>

      {stats && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(150px, 1fr))", gap: 12, marginBottom: 22 }}>
          {(Object.keys(t.stats) as (keyof Stats)[]).map((k) => (
            <div key={k} className="card" style={{ padding: 14 }}>
              <span className="cat">{t.stats[k]}</span>
              <strong style={{ fontSize: 22 }}>{k === "paid_eur" ? `€ ${stats[k].toLocaleString(locale === "de" ? "de-AT" : "en-GB")}` : stats[k]}</strong>
            </div>
          ))}
        </div>
      )}

      {builds && builds.rows.length > 0 && (
        <div className="card" style={{ gap: 10, marginBottom: 22, overflowX: "auto" }}>
          <b>{t.builds}</b>
          <span className="small muted">{t.buildsHint}</span>
          {Object.entries(builds.ratios).map(([stack, r]) => (
            <span key={stack} className="small">
              {t.ratio.replace("{pct}", `${Math.round(r * 1000) / 10} %`).replace("{stack}", stack)}
            </span>
          ))}
          <table className="small" style={{ borderCollapse: "collapse", minWidth: 640 }}>
            <thead>
              <tr>
                {t.cols.map((c) => (
                  <th key={c} style={{ textAlign: "left", padding: "4px 8px" }}>{c}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {builds.rows.map((r, i) => (
                <tr key={i} style={{ borderTop: "1px solid var(--border)" }}>
                  <td style={{ padding: "4px 8px" }}>{r.name}</td>
                  <td style={{ padding: "4px 8px" }}>{r.stack}</td>
                  <td style={{ padding: "4px 8px" }}>{r.real_minutes}</td>
                  <td style={{ padding: "4px 8px" }}>{r.estimated_minutes ?? "–"}</td>
                  <td style={{ padding: "4px 8px" }}>
                    {r.real_tokens_k}k{r.tokens_measured ? "" : ` (${t.partial})`}
                  </td>
                  <td style={{ padding: "4px 8px" }}>{r.estimated_tokens_k ? `${r.estimated_tokens_k}k` : "–"}</td>
                  <td style={{ padding: "4px 8px" }}>€ {r.real_token_eur.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="card" style={{ gap: 18 }}>
        <label className="small" style={{ display: "flex", gap: 8, alignItems: "center" }}>
          <input type="checkbox" checked={s.desk_open} onChange={(e) => setS({ ...s, desk_open: e.target.checked })} />
          <b>{t.desk}</b>
        </label>
        <span className="small muted" style={{ marginTop: -12 }}>{t.deskHint}</span>

        <div>
          <span className="small">{t.offered}</span>
          <div style={{ display: "flex", gap: 14, marginTop: 6 }}>
            {PLATFORMS.map((p) => {
              const on = s.offered.includes(p);
              return (
                <label key={p} className="small" style={{ display: "flex", gap: 6, alignItems: "center" }}>
                  <input
                    type="checkbox"
                    checked={on}
                    disabled={on && s.offered.length === 1}
                    onChange={() => setS({ ...s, offered: on ? s.offered.filter((x) => x !== p) : [...s.offered, p] })}
                  />
                  {t.platforms[p]}
                </label>
              );
            })}
          </div>
        </div>

        <div style={{ display: "grid", gap: 8 }}>
          <b className="small">{t.rates}</b>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 16 }}>
            {PLATFORMS.map((p) => num(`rate_${p}`, t.platforms[p], undefined, 10, 1000))}
            {num("rate_app", t.rateApp, undefined, 10, 1000)}
          </div>
          <span className="small muted">{t.ratesHint}</span>
        </div>
        <div style={{ display: "grid", gap: 8 }}>
          <b className="small">{t.mults}</b>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 16 }}>
            {PLATFORMS.map((p) => num(`mult_${p}`, t.platforms[p], undefined, 1, 20))}
            {num("mult_app", t.rateApp, undefined, 1, 20)}
          </div>
          <span className="small muted">{t.multsHint}</span>
        </div>
        {num("token_pct", t.tokens, t.tokensHint, 0, 500)}
        {num("price_pct", t.price, t.priceHint, 30, 200)}
        {num("max_build_eur", t.max, t.maxHint, 100, 10000)}
        {num("turns_per_day", t.turns, t.turnsHint, 0, 20000)}
        {num("care_trial_months", t.trial, undefined, 0, 12)}
        {num("care_edits_per_month", t.edits, undefined, 0, 50)}

        <div style={{ display: "flex", gap: 12, alignItems: "center" }}>
          <button className="btn btn-primary" disabled={busy} onClick={save}>{t.save}</button>
          {note && <span className="small">{note}</span>}
        </div>
      </div>
    </div>
  );
}
