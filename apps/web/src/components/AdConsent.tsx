"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { API_BASE } from "@/lib/api";
import { OPEN_EVENT, arrivedClick, consent, decide, keepArrivedClick, reopen, type Consent } from "@/lib/adConsent";
import { applyConsent } from "@/lib/gtm";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The consent question. Asked of every visitor once a Tag Manager container is set in the console,
 * and before that only of a visitor who came from an ad. Both purposes start unticked, and Allow
 * all, Save choice and Decline all look the same: a no must be as easy as a yes. The footer link
 * opens it again, which is how a yes is taken back.
 */
export function AdConsentBanner({ d, locale }: { d: Dict; locale: Locale }) {
  const c = d.adConsent;
  const [open, setOpen] = useState(false);
  const [gtmId, setGtmId] = useState<string | null>(null);
  const [pick, setPick] = useState<Consent>({ stats: false, ads: false });

  useEffect(() => {
    let alive = true;
    keepArrivedClick();
    void (async () => {
      const id = await fetch(`${API_BASE}/api/site-config`)
        .then((r) => (r.ok ? r.json() : null))
        .then((j: { gtm_id?: string | null } | null) => j?.gtm_id ?? null)
        .catch(() => null);
      if (!alive) return;
      setGtmId(id);
      const now = consent();
      if (now) {
        applyConsent(id, now);
        setPick(now);
      } else if (id || arrivedClick()) {
        setOpen(true);
      }
    })();
    const show = () => {
      setPick(consent() ?? { stats: false, ads: false });
      setOpen(true);
    };
    window.addEventListener(OPEN_EVENT, show);
    return () => {
      alive = false;
      window.removeEventListener(OPEN_EVENT, show);
    };
  }, []);

  if (!open) return null;

  const save = (v: Consent) => {
    decide(v);
    applyConsent(gtmId, v);
    setPick(v);
    setOpen(false);
  };

  const option = (key: keyof Consent, title: string, text: string) => (
    <label style={{ display: "flex", gap: 10, alignItems: "flex-start", margin: "0 0 10px", fontSize: "0.9rem", cursor: "pointer" }}>
      <input type="checkbox" checked={pick[key]} onChange={(e) => setPick({ ...pick, [key]: e.target.checked })} style={{ marginTop: 3 }} />
      <span>
        <strong style={{ display: "block" }}>{title}</strong>
        <span style={{ color: "var(--ink-soft)" }}>{text}</span>
      </span>
    </label>
  );

  return (
    <div
      role="dialog"
      aria-label={c.title}
      style={{
        position: "fixed", left: 16, right: 16, bottom: 16, zIndex: 60, maxWidth: 540, margin: "0 auto",
        maxHeight: "calc(100vh - 32px)", overflowY: "auto",
        background: "var(--surface)", color: "var(--ink)", border: "1px solid var(--border)",
        borderRadius: "var(--radius)", boxShadow: "0 12px 40px rgba(11,14,20,0.18)", padding: "18px 20px",
      }}
    >
      <strong style={{ display: "block", marginBottom: 6 }}>{c.title}</strong>
      <p style={{ margin: "0 0 12px", fontSize: "0.92rem", color: "var(--ink-soft)" }}>
        {c.text}{" "}
        <Link href={`/${locale}/privacy`} style={{ color: "inherit" }}>{c.more}</Link>
      </p>
      {gtmId && option("stats", c.statsTitle, c.statsText)}
      {option("ads", c.adsTitle, c.adsText)}
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 4 }}>
        <button className="btn btn-ghost btn-sm" onClick={() => save({ stats: Boolean(gtmId), ads: true })}>{c.allowAll}</button>
        <button className="btn btn-ghost btn-sm" onClick={() => save(pick)}>{c.saveChoice}</button>
        <button className="btn btn-ghost btn-sm" onClick={() => save({ stats: false, ads: false })}>{c.declineAll}</button>
      </div>
    </div>
  );
}

/** The footer link that opens the question again. */
export function AdConsentLink({ label }: { label: string }) {
  return (
    <button
      type="button"
      onClick={reopen}
      style={{ background: "none", border: 0, padding: 0, color: "#b8becd", fontSize: "0.88rem", cursor: "pointer", fontFamily: "inherit" }}
    >
      {label}
    </button>
  );
}
