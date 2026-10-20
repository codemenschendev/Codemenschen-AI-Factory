"use client";

import { useEffect } from "react";

declare global {
  interface Window {
    werkAdsConsent?: boolean;
  }
}

/**
 * Werk's analytics beacon (Werk docs/API.md 7.6): page views of appmitki.com for Codemenschen's
 * support app, with the key the API's hourly ping brought back (site-config `werk_analytics`).
 * The beacon sets no cookie and stores nothing, like our own count (PageViews).
 *
 * `werkAdsConsent = false`: the beacon would otherwise read the consent of a few cookie tools and
 * Google Consent Mode, keep an ad click id in localStorage and send Meta's fbc/fbp with a
 * conversion. Appmitki reports its own conversions (Domain\Ads\Conversions), so the beacon never
 * gets ad consent here and stays cookieless and storage-free for everyone.
 */
export function WerkBeacon({ src, siteKey }: { src: string; siteKey: string }) {
  useEffect(() => {
    if (document.querySelector("script[data-werk-beacon]")) return;
    window.werkAdsConsent = false;
    const s = document.createElement("script");
    s.src = src;
    s.defer = true;
    s.dataset.site = siteKey;
    s.dataset.werkBeacon = "1";
    document.body.appendChild(s);
  }, [src, siteKey]);
  return null;
}
