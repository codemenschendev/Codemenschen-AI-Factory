"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { OPEN_EVENT, choice, decide, keepArrivedClick, reopen, shouldAsk } from "@/lib/adConsent";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * The one question about ad measurement, asked only of a visitor who came from an ad. Allow and
 * Decline look the same and sit side by side: a no must be as easy as a yes. The footer link opens
 * it again, which is how a yes is taken back.
 */
export function AdConsentBanner({ d, locale }: { d: Dict; locale: Locale }) {
  const c = d.adConsent;
  const [open, setOpen] = useState(false);
  const [current, setCurrent] = useState<"yes" | "no" | null>(null);

  useEffect(() => {
    keepArrivedClick();
    // Read after mount: storage and the link are only there in the browser.
    const t = window.setTimeout(() => {
      setCurrent(choice());
      setOpen(shouldAsk());
    }, 0);
    const show = () => {
      setCurrent(choice());
      setOpen(true);
    };
    window.addEventListener(OPEN_EVENT, show);
    return () => {
      window.clearTimeout(t);
      window.removeEventListener(OPEN_EVENT, show);
    };
  }, []);

  if (!open) return null;

  const pick = (v: "yes" | "no") => {
    decide(v);
    setOpen(false);
  };

  return (
    <div
      role="dialog"
      aria-label={c.title}
      style={{
        position: "fixed", left: 16, right: 16, bottom: 16, zIndex: 60, maxWidth: 520, margin: "0 auto",
        background: "var(--surface)", color: "var(--ink)", border: "1px solid var(--border)",
        borderRadius: "var(--radius)", boxShadow: "0 12px 40px rgba(11,14,20,0.18)", padding: "18px 20px",
      }}
    >
      <strong style={{ display: "block", marginBottom: 6 }}>{c.title}</strong>
      <p style={{ margin: "0 0 12px", fontSize: "0.92rem", color: "var(--ink-soft)" }}>
        {c.text}{" "}
        <Link href={`/${locale}/privacy`} style={{ color: "inherit" }}>{c.more}</Link>
      </p>
      {current !== null && <p style={{ margin: "0 0 10px", fontSize: "0.85rem" }}>{current === "yes" ? c.nowYes : c.nowNo}</p>}
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
        <button className="btn btn-ghost btn-sm" onClick={() => pick("yes")}>{c.allow}</button>
        <button className="btn btn-ghost btn-sm" onClick={() => pick("no")}>{c.decline}</button>
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
