import type { MetadataRoute } from "next";
import { SITE } from "@/lib/site";

// Previews, accounts, the console and one-time sign-in pages are private; they also say noindex.
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/api/", "/de/p/", "/en/p/", "/de/signin/", "/en/signin/", "/de/account", "/en/account", "/de/admin", "/en/admin", "/de/checkout", "/en/checkout"],
    },
    sitemap: `${SITE}/sitemap.xml`,
  };
}
