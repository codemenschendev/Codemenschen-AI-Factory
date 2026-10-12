import type { MetadataRoute } from "next";
import { CATALOG } from "@/lib/catalog";
import { LOCALES } from "@/lib/i18n";
import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { appsOnly, offeredKinds } from "@/lib/offer";
import { SITE } from "@/lib/site";

/** The public pages in both languages, each with its other-language twin. */
const PAGES = ["", "/app", "/prototype", "/imprint", "/privacy", "/security", "/terms", "/withdrawal"];

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") return [];
  // Apps only: /app is the home page's twin and names it as canonical, so it is not listed.
  const pages = appsOnly(await offeredKinds()) ? PAGES.filter((p) => p !== "/app") : PAGES;
  const paths = [...pages, ...CATALOG.filter((e) => e.status === "available").map((e) => `/apps/${e.slug}`)];

  return paths.flatMap((p) =>
    LOCALES.map((l) => ({
      url: `${SITE}/${l}${p}`,
      alternates: { languages: Object.fromEntries(LOCALES.map((o) => [o, `${SITE}/${o}${p}`])) },
    })),
  );
}
