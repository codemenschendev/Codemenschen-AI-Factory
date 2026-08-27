/** Factory API client — the browser talks to api.appwerk.codemenschen.at. */
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

export async function api<T>(
  path: string,
  init?: RequestInit & { token?: string },
): Promise<T> {
  const res = await fetch(`${API_BASE}/api${path}`, {
    ...init,
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      ...(init?.token ? { authorization: `Bearer ${init.token}` } : {}),
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
