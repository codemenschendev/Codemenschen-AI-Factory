"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import type { SbDict } from "@/dictionaries/sofabuilt";

/**
 * Ordering the scope on the desk: launch options, e-mail, the withdrawal choice and the terms, then
 * Stripe. The desk freezes the scope into a quote first, so the price paid is the price shown.
 */
export function SbCheckout({
  sessionId,
  total,
  picked,
  t,
  locale,
}: {
  sessionId: string;
  /** Build plus the launch options ticked in the desk's launch card. */
  total: number;
  picked: Record<string, boolean>;
  t: SbDict["desk"]["checkout"];
  locale: string;
}) {
  const [email, setEmail] = useState("");
  const [name, setName] = useState("");
  const [startNow, setStartNow] = useState(false);
  const [terms, setTerms] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const eur = (n: number) => (locale === "de" ? `${n.toLocaleString("de-AT")} €` : `€${n.toLocaleString("en-IE")}`);
  const [before, after] = t.terms.split("{terms}");
  const [mid, end] = (after ?? "").split("{withdrawal}");

  async function pay(e: React.FormEvent) {
    e.preventDefault();
    if (!terms || busy) return;
    setBusy(true);
    setError(null);
    try {
      const q = await api<{ quote_id: string }>(`/desk/${sessionId}/quote`, { method: "POST", body: "{}" });
      const r = await api<{ checkout_url?: string }>("/checkout", {
        method: "POST",
        body: JSON.stringify({ quote_id: q.quote_id, email, name: name || null, packages: picked, fagg_waiver: startNow, terms: true, locale }),
      });
      if (!r.checkout_url) throw new Error("no checkout url");
      window.location.href = r.checkout_url;
    } catch {
      setError(t.failed);
      setBusy(false);
    }
  }

  return (
    <form className="sb-order" onSubmit={pay}>
      <p className="sb-order-total">
        <span>{t.total}</span>
        <b>{eur(total)}</b>
      </p>
      <input type="email" required placeholder={t.email} value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
      <input type="text" placeholder={t.name} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoComplete="organization" />
      <label className="sb-check sb-check-text">
        <input type="checkbox" checked={startNow} onChange={(e) => setStartNow(e.target.checked)} />
        <span>{t.startNow}</span>
      </label>
      <label className="sb-check sb-check-text">
        <input type="checkbox" checked={terms} onChange={(e) => setTerms(e.target.checked)} required />
        <span>
          {before}
          <a href={`/${locale}/terms`} target="_blank" rel="noopener">{t.termsLink}</a>
          {mid}
          <a href={`/${locale}/withdrawal`} target="_blank" rel="noopener">{t.withdrawalLink}</a>
          {end}
        </span>
      </label>
      {error && <p className="sb-desk-error">{error}</p>}
      <button type="submit" className="sb-btn" disabled={busy || !terms || !email}>
        {busy ? t.paying : t.pay.replace("{price}", eur(total))}
      </button>
      <p className="sb-small">{t.careLater}</p>
    </form>
  );
}
