import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, isLocale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";
import { SbLogo } from "@/components/sofabuilt/SbLogo";
import "../../../globals.css";
import "../../../sofabuilt.css";

/**
 * Sofabuilt's root layout (docs/specs/sofabuilt.md). Reached only on its own host: proxy.ts
 * rewrites /en/... to /sb/en/... there. Not indexed until the brand launches.
 */
export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale } = await params;
  const d = sbDict(locale);

  return { title: d.meta.title, description: d.meta.description, robots: { index: false, follow: false } };
}

export function generateStaticParams() {
  return LOCALES.map((locale) => ({ locale }));
}

export default async function SofabuiltLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const d = sbDict(locale);
  const other = locale === "en" ? "de" : "en";

  return (
    <html lang={locale}>
      <body className="sb">
        <header className="sb-nav">
          <div className="wrap sb-nav-inner">
            <Link href={`/${locale}`} aria-label="Sofabuilt">
              <SbLogo />
            </Link>
            <nav className="sb-nav-links">
              <a href={`/${locale}#how`}>{d.nav.how}</a>
              <a href={`/${locale}#what`}>{d.nav.what}</a>
              <a href={`/${locale}#prices`}>{d.nav.prices}</a>
              <a href={`/${locale}#faq`}>{d.nav.faq}</a>
            </nav>
            <div className="sb-nav-right">
              <a className="sb-lang" href={`/${other}`} hrefLang={other}>
                {other.toUpperCase()}
              </a>
              <Link className="sb-btn sb-btn-sm" href={`/${locale}/desk`}>
                {d.nav.cta}
              </Link>
            </div>
          </div>
        </header>
        {children}
        <footer className="sb-footer">
          <div className="wrap sb-footer-inner">
            <div>
              <SbLogo />
              <p>{d.footer.by}</p>
              <p>© {new Date().getFullYear()} Codemenschen GmbH</p>
            </div>
            <nav>
              <Link href={`/${locale}/imprint`}>{d.footer.imprint}</Link>
              <Link href={`/${locale}/privacy`}>{d.footer.privacy}</Link>
              <Link href={`/${locale}/terms`}>{d.footer.terms}</Link>
              <Link href={`/${locale}/withdrawal`}>{d.footer.withdrawal}</Link>
            </nav>
          </div>
        </footer>
      </body>
    </html>
  );
}
