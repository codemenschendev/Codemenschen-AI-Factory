import type { Metadata } from "next";
import { SITE } from "@/lib/site";

/**
 * A page's canonical address and its language versions, as full URLs (Google wants absolute
 * hreflang links) with German as x-default: "/" serves German to anyone without a language choice.
 * `path` is the page's path after the locale, "" for the home page.
 */
export function seo(locale: string, path: string): Pick<Metadata, "alternates"> {
  const url = (l: string) => `${SITE}/${l}${path}`;
  return { alternates: { canonical: url(locale), languages: { de: url("de"), en: url("en"), "x-default": url("de") } } };
}

/** Private pages: kept out of search results. robots.txt lets them be read, so the tag is seen. */
export const NOINDEX: Metadata = { robots: { index: false, follow: false } };
