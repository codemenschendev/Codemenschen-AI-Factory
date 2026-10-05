/**
 * The brands the one Appwerk system serves (2026-10-03), told apart by the host. Appmitki is the
 * app storefront; Sofabuilt the store-plugin factory (docs/specs/sofabuilt.md), served from its own
 * route group under /sb, which proxy.ts rewrites to so its addresses stay plain (/en, /en/desk).
 */
export type Brand = "appmitki" | "sofabuilt";

/** Sofabuilt's own host and its address until the domain is live. `sofabuilt.localhost` is for dev. */
export function brandFromHost(host: string | null | undefined): Brand {
  const h = (host ?? "").toLowerCase().replace(/:\d+$/, "").replace(/^www\./, "");
  return h === "sofabuilt.com" || h.startsWith("sofabuilt.") ? "sofabuilt" : "appmitki";
}

/**
 * The customer console (console.appmitki.com, console.<anything> in dev): every project of a
 * customer of either brand, served from its own route group under /console (proxy.ts).
 */
export function isConsoleHost(host: string | null | undefined): boolean {
  return (host ?? "").toLowerCase().startsWith("console.");
}

/** The path prefix of the console's route group; never shown in an address. */
export const CONSOLE_PREFIX = "/console";

/** Sofabuilt is English first: its buyers are plugin and shop owners anywhere. */
export const SOFABUILT_DEFAULT_LOCALE = "en";

/** The path prefix of Sofabuilt's route group; never shown in an address. */
export const SOFABUILT_PREFIX = "/sb";

/**
 * A dictionary with the brand's name in place of Appmitki's, for the texts both brands share (the
 * legal pages: same company, same processing).
 */
export function rebrand<T>(value: T, brand: Brand): T {
  if (brand === "appmitki") return value;
  const swap = (s: string) =>
    s
      // What Appmitki sells, said for Sofabuilt (the imprint's service line).
      .replace("apps and websites at a fixed price", "WordPress plugins at a fixed price")
      .replace("Apps und Websites zum Festpreis", "WordPress-Plugins zum Festpreis")
      .replace(/appmitki\.com/g, "sofabuilt.com")
      .replace(/Appmitki/g, "Sofabuilt");
  const walk = (v: unknown): unknown =>
    typeof v === "string" ? swap(v) : Array.isArray(v) ? v.map(walk) : v && typeof v === "object" ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, walk(x)])) : v;

  return walk(value) as T;
}
