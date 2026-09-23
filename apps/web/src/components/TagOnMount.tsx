"use client";

import { useEffect } from "react";
import { tagEvent } from "@/lib/gtm";

/** Sends one event to Tag Manager when the page is shown, for example purchase on the thank-you page. */
export function TagOnMount({ event }: { event: string }) {
  useEffect(() => {
    // Tag Manager loads after the consent banner reads the setting, a moment after the page.
    const t = window.setTimeout(() => tagEvent(event, { currency: "EUR" }), 1500);
    return () => window.clearTimeout(t);
  }, [event]);
  return null;
}
