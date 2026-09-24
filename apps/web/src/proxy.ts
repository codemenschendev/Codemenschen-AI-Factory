import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_LOCALE, LOCALES, isLocale } from "@/lib/i18n";

/** Redirect locale-less paths to /de|/en. A manual choice (cookie) wins and is never
    auto-overridden; everyone else gets German, whatever the browser language. */
export function proxy(req: NextRequest) {
  const { pathname } = req.nextUrl;
  if (LOCALES.some((l) => pathname === `/${l}` || pathname.startsWith(`/${l}/`))) {
    return NextResponse.next();
  }
  // German first: Appwerk starts in Austria. English only when the visitor picked it (cookie).
  const cookie = req.cookies.get("locale")?.value;
  const locale = cookie && isLocale(cookie) ? cookie : DEFAULT_LOCALE;
  const url = req.nextUrl.clone();
  url.pathname = `/${locale}${pathname === "/" ? "" : pathname}`;
  return NextResponse.redirect(url, 302);
}

export const config = {
  matcher: ["/((?!_next|api|fonts|favicon.ico|.*\\..*).*)"],
};
