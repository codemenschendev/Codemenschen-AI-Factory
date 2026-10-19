import type { MetadataRoute } from "next";
import { headers } from "next/headers";
import { brandFromHost } from "@/lib/brand";
import { SITE } from "@/lib/site";

/** Search and answer engines of the AI assistants: OpenAI, Anthropic, Perplexity, Google, Microsoft, Apple. */
const AI_BOTS = ["GPTBot", "OAI-SearchBot", "ChatGPT-User", "ClaudeBot", "Claude-SearchBot", "Claude-User", "PerplexityBot",
  "Perplexity-User", "Google-Extended", "Bingbot", "Applebot", "Applebot-Extended"];

// Previews, accounts, the console and one-time sign-in pages are private: they say noindex.
export default async function robots(): Promise<MetadataRoute.Robots> {
  // Sofabuilt stays out of search until it launches (docs/specs/sofabuilt.md, phase 2).
  if (brandFromHost((await headers()).get("host")) === "sofabuilt") {
    return { rules: { userAgent: "*", disallow: "/" } };
  }
  // Previews, sign-in, accounts and checkout are NOT blocked here: they carry noindex, and Google
  // only drops a page from results when it may read that tag (2026-10-07: sign-in and checkout
  // were listed). The admin and the API stay blocked.
  const disallow = ["/api/", "/de/admin", "/en/admin"];
  return {
    rules: [
      { userAgent: "*", allow: "/", disallow },
      // The AI crawlers by name (owner, 2026-10-19: appmitki.com should be read and cited by ChatGPT,
      // Claude, Perplexity and Gemini). "*" already lets them in; naming them says so on purpose.
      { userAgent: AI_BOTS, allow: "/", disallow },
    ],
    sitemap: `${SITE}/sitemap.xml`,
  };
}
