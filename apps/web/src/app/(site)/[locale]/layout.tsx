import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, getDict, isLocale, type Locale } from "@/lib/i18n";
import { AccountLink } from "@/components/AccountLink";
import { LangSwitch } from "@/components/LangSwitch";
import { Logo } from "@/components/Logo";
import { MobileNav } from "@/components/MobileNav";
import { NavLinks } from "@/components/NavLinks";
import { PageViews } from "@/components/PageViews";
import { AdConsentBanner, AdConsentLink } from "@/components/AdConsent";
import "../../globals.css";

export const metadata: Metadata = {
  title: "Appwerk · Website, app and ads from one hand",
  description: "Describe your idea and get a free preview in minutes. Website, app, ads and e-mails at a fixed price.",
};

export function generateStaticParams() {
  return LOCALES.map((locale) => ({ locale }));
}

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const dict = getDict(locale as Locale);
  // The same links in the bar and, on a phone, behind the menu button. The prototype page is the
  // start button's, not a fifth link: two ways to the same page only made the bar longer.
  const navLinks = [
    { href: `/${locale}#services`, label: dict.nav.services },
    { href: `/${locale}#how`, label: dict.nav.how },
    { href: `/${locale}#prices`, label: dict.nav.pricing },
    { href: `/${locale}/app`, label: dict.nav.appDev },
  ];
  const cta = { href: `/${locale}/prototype`, label: dict.nav.cta };

  return (
    <html lang={locale}>
      <body>
        <PageViews />
        {/* Sticky header, ported from the appwerk prototype (site/index.html:14-30) */}
        <header className="nav" id="top">
          <div className="wrap nav-inner">
            <Link href={`/${locale}`} className="nav-logo" aria-label="Appwerk">
              <Logo by={dict.nav.by} />
            </Link>
            <NavLinks links={navLinks} />
            <div className="nav-right">
              <AccountLink locale={locale as Locale} labels={{ account: dict.nav.account, login: dict.nav.login }} />
              <LangSwitch current={locale as Locale} />
              <Link className="btn btn-primary btn-sm nav-cta" href={cta.href}>
                <span className="nav-cta-long">{dict.nav.cta}</span>
                <span className="nav-cta-short">{dict.nav.ctaShort}</span>
              </Link>
              <MobileNav links={navLinks} cta={cta} />
            </div>
          </div>
        </header>
        {children}
        <footer className="site">
          <div className="wrap footer-inner">
            <div>
              <p className="nav-logo">
                <Logo />
              </p>
              <p className="footer-small">{dict.footer.by}</p>
            </div>
            <div className="footer-links">
              <Link href={`/${locale}/imprint`}>{dict.footer.imprint}</Link>
              <Link href={`/${locale}/security`}>{dict.security.title}</Link>
              <Link href={`/${locale}/privacy`}>{dict.legal.privacy.title}</Link>
              <Link href={`/${locale}/terms`}>{dict.legal.terms.title}</Link>
              <Link href={`/${locale}/withdrawal`}>{dict.legal.withdrawal.title}</Link>
              <AdConsentLink label={dict.adConsent.link} />
            </div>
          </div>
          <div className="wrap">
            <p className="footer-legal">{dict.footer.legal}</p>
          </div>
        </footer>
        <AdConsentBanner d={dict} locale={locale as Locale} />
      </body>
    </html>
  );
}
