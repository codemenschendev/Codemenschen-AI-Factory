import type { NextRequest } from "next/server";
import { API_BASE } from "@/lib/api";

/**
 * werk-connect (Werk docs/API.md 7.10): Werk and werk.ai read https://appmitki.com/_werk/v1/*
 * with the site's probe token. Apache sends only /api/ to Laravel, so the storefront hands these
 * requests on to the API, where the token is checked (WerkController). The folder is named
 * %5Fwerk because a folder starting with "_" is private in the App Router.
 *
 * Inside docker the API is the `api` service (API_BASE_URL, compose); elsewhere the public API.
 */
const ROUTES = new Set(["probe", "overview", "ads", "business"]);

const json = (body: string, status: number) =>
  new Response(body, { status, headers: { "content-type": "application/json; charset=utf-8", "cache-control": "no-store, private" } });

export async function GET(req: NextRequest, { params }: { params: Promise<{ route: string }> }) {
  const { route } = await params;
  if (!ROUTES.has(route)) return json(JSON.stringify({ error: "not_found" }), 404);

  const headers: Record<string, string> = { accept: "application/json" };
  for (const name of ["authorization", "x-werk-token", "x-forwarded-for"]) {
    const value = req.headers.get(name);
    if (value) headers[name] = value;
  }
  const base = (process.env.API_BASE_URL || API_BASE).replace(/\/+$/, "");
  try {
    const res = await fetch(`${base}/api/werk/v1/${route}${req.nextUrl.search}`, {
      headers,
      cache: "no-store",
      signal: AbortSignal.timeout(55_000),
    });
    return json(await res.text(), res.status);
  } catch {
    return json(JSON.stringify({ error: "upstream" }), 502);
  }
}
