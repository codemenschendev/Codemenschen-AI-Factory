/** Factory API client — the browser talks to api.appwerk.codemenschen.at. */
import { adClickHeader } from "./adConsent";
export const API_BASE =
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000";

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: unknown,
  ) {
    super(`API ${status}`);
  }
}

/** The two requests a lead is counted on carry the ad click, when the visitor allowed it. */
const LEAD_PATHS = ["/quotes", "/prototypes"];

export async function api<T>(
  path: string,
  init?: RequestInit & { token?: string },
): Promise<T> {
  const click = init?.method === "POST" && LEAD_PATHS.includes(path) ? adClickHeader() : null;
  const res = await fetch(`${API_BASE}/api${path}`, {
    ...init,
    headers: {
      // A FormData body sets its own multipart boundary; a JSON content type would break it.
      ...(init?.body instanceof FormData ? {} : { "content-type": "application/json" }),
      accept: "application/json",
      ...(init?.token ? { authorization: `Bearer ${init.token}` } : {}),
      ...(click ? { "x-ad-click": click } : {}),
      ...init?.headers,
    },
  });
  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

export interface QuoteResponse {
  id: string;
  listing_slug: string | null;
  price_eur: number;
  app_type: "A" | "B";
  hosting_monthly_eur: number;
  breakdown: { weeksLo: number; weeksHi: number; [k: string]: unknown };
  packages: Record<string, number>;
  ad_budget_options: number[];
  /** Store-listing languages the factory can produce (customer picks a subset). */
  store_locales: string[];
  status: string;
}
