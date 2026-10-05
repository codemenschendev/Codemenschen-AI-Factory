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
};
type Stats = { chats_today: number; chats_7d: number; quotes_7d: number; paid_orders: number; paid_eur: number; test_orders: number; care_active: number };

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
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  useEffect(() => {
    api<{ settings: Settings; stats: Stats }>("/admin/sofabuilt", { token })
      .then((r) => {
        setS(r.settings);
        setStats(r.stats);
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
