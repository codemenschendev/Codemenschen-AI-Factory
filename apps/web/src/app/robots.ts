import type { MetadataRoute } from "next";
import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { SITE } from "@/lib/site";

// Previews, accounts, the console and one-time sign-in pages are private: they say noindex.
export default async function robots(): Promise<MetadataRoute.Robots> {
  // Sofabuilt stays out of search until it launches (docs/specs/sofabuilt.md, phase 2).
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      // Previews, sign-in, accounts and checkout are NOT blocked here: they carry noindex, and Google
      // only drops a page from results when it may read that tag (2026-10-07: sign-in and checkout
      // were listed). The admin and the API stay blocked.
      disallow: ["/api/", "/de/admin", "/en/admin"],
    },
    sitemap: `${SITE}/sitemap.xml`,
  };
}
