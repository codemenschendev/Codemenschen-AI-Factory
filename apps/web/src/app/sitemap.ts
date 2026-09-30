import type { MetadataRoute } from "next";
import { CATALOG } from "@/lib/catalog";
import { LOCALES } from "@/lib/i18n";
import { SITE } from "@/lib/site";

/** The public pages in both languages, each with its other-language twin. */
const PAGES = ["", "/app", "/prototype", "/imprint", "/privacy", "/security", "/terms", "/withdrawal"];

export default function sitemap(): MetadataRoute.Sitemap {
  const paths = [...PAGES, ...CATALOG.filter((e) => e.status === "available").map((e) => `/apps/${e.slug}`)];

  return paths.flatMap((p) =>
    LOCALES.map((l) => ({
      url: `${SITE}/${l}${p}`,
      alternates: { languages: Object.fromEntries(LOCALES.map((o) => [o, `${SITE}/${o}${p}`])) },
    })),
  );
}
