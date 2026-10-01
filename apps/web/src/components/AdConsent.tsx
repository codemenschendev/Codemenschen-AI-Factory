"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { usePathname } from "next/navigation";
import { API_BASE } from "@/lib/api";
import { OPEN_EVENT, arrivedClick, consent, decide, keepArrivedClick, reopen, type Consent } from "@/lib/adConsent";
import { applyConsent } from "@/lib/gtm";
import { applyMetaPixel, metaPageView } from "@/lib/metaPixel";
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
  const [pixelId, setPixelId] = useState<string | null>(null);
  const pathname = usePathname();
  const firstPath = useRef(true);
  const [pick, setPick] = useState<Consent>({ stats: false, ads: false });

  useEffect(() => {
    let alive = true;
    keepArrivedClick();
    void (async () => {
      const cfg = await fetch(`${API_BASE}/api/site-config`)
        .then((r) => (r.ok ? r.json() : null))
        .catch(() => null) as { gtm_id?: string | null; meta_pixel_id?: string | null } | null;
      const id = cfg?.gtm_id ?? null;
      const pixel = cfg?.meta_pixel_id ?? null;
      if (!alive) return;
      setGtmId(id);
      setPixelId(pixel);
      const now = consent();
      if (now) {
        applyConsent(id, now);
        applyMetaPixel(pixel, now.ads);
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

  // A page view per client-side navigation; the first one is sent when the pixel loads.
  useEffect(() => {
    if (firstPath.current) {
      firstPath.current = false;
      return;
    }
    metaPageView();
  }, [pathname]);

  const save = (v: Consent) => {
    decide(v);
    applyConsent(gtmId, v);
    applyMetaPixel(pixelId, v.ads);
    setPick(v);
    setOpen(false);
  };

  if (!open) return null;

  const option = (key: keyof Consent, title: string, text: string) => (
    <label className="consent-opt">
      <input type="checkbox" role="switch" className="consent-switch" checked={pick[key]} onChange={(e) => setPick({ ...pick, [key]: e.target.checked })} />
      <span>
        <strong>{title}</strong>
        <span>{text}</span>
      </span>
    </label>
  );

  // A bar along the bottom, one row on a wide screen: the big card covered half the page (2026-10-01).
  return (
    <div role="dialog" aria-label={c.title} className="consent">
      <div className="consent-copy">
        <strong className="consent-title">{c.title}</strong>
        <p className="consent-text">
          {c.text}{" "}
          <Link href={`/${locale}/privacy`}>{c.more}</Link>
        </p>
      </div>
      <div className="consent-opts">
        {gtmId && option("stats", c.statsTitle, c.statsText)}
        {option("ads", c.adsTitle, c.adsText)}
      </div>
      {/* Allow and decline look the same on purpose: consent may not be nudged (EDPB 03/2022). */}
      <div className="consent-actions">
        <button className="consent-btn" onClick={() => save({ stats: false, ads: false })}>{c.declineAll}</button>
        <button className="consent-btn" onClick={() => save({ stats: Boolean(gtmId), ads: true })}>{c.allowAll}</button>
        <button className="consent-save" onClick={() => save(pick)}>{c.saveChoice}</button>
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
      className="consent-reopen"
    >
      {label}
    </button>
  );
}
