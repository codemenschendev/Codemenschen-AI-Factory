import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_LOCALE, LOCALES, isLocale } from "@/lib/i18n";

/** Redirect locale-less paths to /de|/en. Cookie (manual choice) wins over
    Accept-Language; a manual choice is never auto-overridden (appwerk doc 12). */
export function proxy(req: NextRequest) {
  const { pathname } = req.nextUrl;
  if (LOCALES.some((l) => pathname === `/${l}` || pathname.startsWith(`/${l}/`))) {
    return NextResponse.next();
  }
  const cookie = req.cookies.get("locale")?.value;
  const fromHeader = req.headers
    .get("accept-language")
    ?.toLowerCase()
    .startsWith("de")
    ? "de"
    : "en";
  const locale = cookie && isLocale(cookie) ? cookie : (fromHeader ?? DEFAULT_LOCALE);
  const url = req.nextUrl.clone();
  url.pathname = `/${locale}${pathname === "/" ? "" : pathname}`;
  return NextResponse.redirect(url, 302);
}

export const config = {
  matcher: ["/((?!_next|api|fonts|favicon.ico|.*\\..*).*)"],
};
