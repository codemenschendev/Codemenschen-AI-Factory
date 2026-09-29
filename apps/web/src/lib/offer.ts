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
