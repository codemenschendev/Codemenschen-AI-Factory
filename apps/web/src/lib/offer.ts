import { API_BASE } from "./api";

export type OfferKind = "site" | "app" | "ads" | "email" | "campaign";

const ALL: OfferKind[] = ["site", "app", "ads", "email", "campaign"];

/**
 * Which kinds the storefront offers, from the owner's switch in the console (2026-09-29: apps only
 * for now). Read on the server and refreshed every minute. When the API cannot be reached, apps
 * only: showing an offer the API would refuse is worse than hiding one it would take.
 */
export async function offeredKinds(): Promise<OfferKind[]> {
  try {
    const res = await fetch(`${API_BASE}/api/site-config`, { next: { revalidate: 60 } });
    if (!res.ok) return ["app"];
    const kinds = ((await res.json()) as { kinds?: string[] }).kinds ?? [];
    const on = ALL.filter((k) => kinds.includes(k));
    return on.length ? on : ["app"];
  } catch {
    return ["app"];
  }
}

/** Apps and nothing else: the storefront is the app landing page. */
export const appsOnly = (kinds: OfferKind[]) => kinds.length === 1 && kinds[0] === "app";

/**
 * Whether a visitor may build a free preview without an account or e-mail (the admin's switch, on during the
 * ads, 2026-10-15). When the API cannot be reached: no, the form then asks for the e-mail as before.
 */
export async function anonymousPreview(): Promise<boolean> {
  try {
    const res = await fetch(`${API_BASE}/api/site-config`, { next: { revalidate: 60 } });
    if (!res.ok) return false;
    return ((await res.json()) as { prototype_anonymous?: boolean }).prototype_anonymous === true;
  } catch {
    return false;
  }
}

/**
 * Werk's beacon for the public pages (components/WerkBeacon): its key and script, or null when the
 * API has none (no Werk token, no ping yet, analytics switched off in Werk) or cannot be reached.
 */
export async function werkAnalytics(): Promise<{ key: string; script: string } | null> {
  try {
    const res = await fetch(`${API_BASE}/api/site-config`, { next: { revalidate: 60 } });
    if (!res.ok) return null;
    const w = ((await res.json()) as { werk_analytics?: { key?: unknown; script?: unknown } | null }).werk_analytics;
    if (typeof w?.key !== "string" || typeof w?.script !== "string") return null;
    if (!/^[A-Za-z0-9_-]{6,128}$/.test(w.key) || !/^https:\/\/([a-z0-9-]+\.)*codemenschen\.at\//.test(w.script)) return null;
    return { key: w.key, script: w.script };
  } catch {
    return null;
  }
}
