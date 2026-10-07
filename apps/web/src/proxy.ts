import { NextResponse, type NextRequest } from "next/server";
import { DEFAULT_LOCALE, LOCALES, isLocale } from "@/lib/i18n";
import { CONSOLE_PREFIX, SOFABUILT_DEFAULT_LOCALE, SOFABUILT_PREFIX, brandFromHost, isConsoleHost } from "@/lib/brand";

/** Redirect locale-less paths to /de|/en. A manual choice (cookie) wins and is never
    auto-overridden; everyone else gets German, whatever the browser language. */
export function proxy(req: NextRequest) {
  const { pathname } = req.nextUrl;
  // One address: www and the bare domain kept two separate browsers' worth of storage, so a
  // visitor who said yes on one was asked again on the other (2026-10-01).
  const host = req.headers.get("host") ?? "";
  if (host.startsWith("www.")) {
    return NextResponse.redirect(`https://${host.slice(4)}${pathname}${req.nextUrl.search}`, 301);
  }
  // The console: only the customer's own pages and the sign-in links, nothing of the storefronts.
  if (isConsoleHost(host)) {
    if (pathname === CONSOLE_PREFIX || pathname.startsWith(`${CONSOLE_PREFIX}/`)) return NextResponse.redirect(new URL("/", req.url), 302);
    const m = /^\/(de|en)(\/(account|signin)(\/.*)?)?$/.exec(pathname);
    if (m && m[2]) {
      const url = req.nextUrl.clone();
      url.pathname = `${CONSOLE_PREFIX}${pathname}`;
      return NextResponse.rewrite(url);
    }
    const cookie = req.cookies.get("locale")?.value;
    const locale = m?.[1] ?? (cookie && isLocale(cookie) ? cookie : DEFAULT_LOCALE);
    return NextResponse.redirect(new URL(`/${locale}/account`, req.url), 302);
  }
  const sofabuilt = brandFromHost(host) === "sofabuilt";
  // Sofabuilt's pages live in their own route group; Appmitki's host never shows them.
  if (pathname === CONSOLE_PREFIX || pathname.startsWith(`${CONSOLE_PREFIX}/`)) return NextResponse.redirect(new URL("/", req.url), 302);
  if (pathname === SOFABUILT_PREFIX || pathname.startsWith(`${SOFABUILT_PREFIX}/`)) {
    return sofabuilt ? NextResponse.next() : NextResponse.redirect(new URL("/", req.url), 302);
  }
  if (LOCALES.some((l) => pathname === `/${l}` || pathname.startsWith(`/${l}/`))) {
    if (!sofabuilt) return NextResponse.next();
    const url = req.nextUrl.clone();
    url.pathname = `${SOFABUILT_PREFIX}${pathname}`;
    return NextResponse.rewrite(url);
  }
  // German first: Appwerk starts in Austria. English only when the visitor picked it (cookie).
  const cookie = req.cookies.get("locale")?.value;
  const locale = cookie && isLocale(cookie) ? cookie : sofabuilt ? SOFABUILT_DEFAULT_LOCALE : DEFAULT_LOCALE;
  const url = req.nextUrl.clone();
  url.pathname = `/${locale}${pathname === "/" ? "" : pathname}`;
  // The home page is served at "/" in the visitor's language, not redirected (2026-10-07: search
  // engines treat the 302 as temporary). Its canonical names /de or /en, so nothing is indexed twice.
  if (pathname === "/" && !sofabuilt) return NextResponse.rewrite(url);
  return NextResponse.redirect(url, 302);
}

export const config = {
  matcher: ["/((?!_next|api|fonts|favicon.ico|.*\\..*).*)"],
};
