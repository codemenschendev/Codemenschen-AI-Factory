import type { MetadataRoute } from "next";
import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { SITE } from "@/lib/site";

// Previews, accounts, the console and one-time sign-in pages are private; they also say noindex.
export default async function robots(): Promise<MetadataRoute.Robots> {
  // Sofabuilt stays out of search until it launches (docs/specs/sofabuilt.md, phase 2).
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/api/", "/de/p/", "/en/p/", "/de/signin/", "/en/signin/", "/de/account", "/en/account", "/de/admin", "/en/admin", "/de/checkout", "/en/checkout"],
    },
    sitemap: `${SITE}/sitemap.xml`,
  };
}
