import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, isLocale, type Locale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";
import { SbLogo } from "@/components/sofabuilt/SbLogo";
import { LangSwitch } from "@/components/LangSwitch";
import { MobileNav } from "@/components/MobileNav";
import { NavLinks } from "@/components/NavLinks";
import "../../../globals.css";
import "../../../sofabuilt.css";

/**
 * Sofabuilt's root layout (docs/specs/sofabuilt.md), in Appmitki's header and footer: the same
 * system, a sibling brand. Reached only on its own host (proxy.ts). Not indexed before launch.
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
  const links = [
    { href: `/${locale}#services`, label: d.nav.what },
    { href: `/${locale}#how`, label: d.nav.how },
    { href: `/${locale}#prices`, label: d.nav.prices },
    { href: `/${locale}#faq`, label: d.nav.faq },
  ];
  const cta = { href: `/${locale}/desk`, label: d.nav.cta };
  const by = locale === "de" ? "von codemenschen" : "by codemenschen";

  return (
    <html lang={locale}>
      <body className="sb">
        <header className="nav" id="top">
          <div className="wrap nav-inner">
            <Link href={`/${locale}`} className="nav-logo" aria-label="Sofabuilt">
              <SbLogo by={by} />
            </Link>
            <NavLinks links={links} />
            <div className="nav-right">
              <LangSwitch current={locale as Locale} />
              <Link className="btn btn-primary btn-sm nav-cta" href={cta.href}>
                <span className="nav-cta-long">{d.nav.cta}</span>
                <span className="nav-cta-short">{d.nav.cta.split(" ")[0]}</span>
              </Link>
              <MobileNav links={links} cta={cta} />
            </div>
          </div>
        </header>
        {children}
        <footer className="site">
          <div className="wrap footer-inner">
            <div>
              <p className="nav-logo">
                <SbLogo />
              </p>
              <p className="footer-small">{d.footer.by}</p>
              <p className="footer-small">© {new Date().getFullYear()} Codemenschen GmbH</p>
            </div>
            <div className="footer-links">
              <Link href={`/${locale}/imprint`}>{d.footer.imprint}</Link>
              <Link href={`/${locale}/privacy`}>{d.footer.privacy}</Link>
              <Link href={`/${locale}/terms`}>{d.footer.terms}</Link>
              <Link href={`/${locale}/withdrawal`}>{d.footer.withdrawal}</Link>
            </div>
          </div>
        </footer>
      </body>
    </html>
  );
}
