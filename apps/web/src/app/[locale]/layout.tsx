import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, getDict, isLocale, type Locale } from "@/lib/i18n";
import { AccountLink } from "@/components/AccountLink";
import { LangSwitch } from "@/components/LangSwitch";
import "../globals.css";

export const metadata: Metadata = {
  title: "Appwerk · AI App Factory",
  description: "Pick an app idea, pay a fixed price, own the finished app.",
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

  return (
    <html lang={locale}>
      <body>
        {/* Sticky header, ported from the appwerk prototype (site/index.html:14-30) */}
        <header className="nav" id="top">
          <div className="wrap nav-inner">
            <Link href={`/${locale}`} className="nav-logo">
              Appwerk<span className="logo-dot">.</span>
              <span className="logo-by">{dict.nav.by}</span>
            </Link>
            <nav className="nav-links">
              <Link href={`/${locale}#how`}>{dict.nav.how}</Link>
              <Link href={`/${locale}#apps`}>{dict.nav.ideas}</Link>
              <Link href={`/${locale}/create`}>{dict.nav.create}</Link>
              <Link href={`/${locale}#pricing`}>{dict.nav.pricing}</Link>
              <Link href={`/${locale}#faq`}>{dict.nav.faq}</Link>
              <Link href={`/${locale}/prototype`}>{dict.proto.navLink}</Link>
            </nav>
            <div className="nav-right">
              <AccountLink locale={locale as Locale} labels={{ account: dict.nav.account, login: dict.nav.login }} />
              <LangSwitch current={locale as Locale} />
              <Link className="btn btn-primary btn-sm nav-cta" href={`/${locale}#apps`}>
                {dict.nav.cta}
              </Link>
            </div>
          </div>
        </header>
        {children}
        <footer className="site">
          <div className="wrap footer-inner">
            <div>
              <p className="nav-logo">
                Appwerk<span className="logo-dot">.</span>
              </p>
              <p className="footer-small">{dict.footer.by}</p>
            </div>
            <div className="footer-links">
              <a href="https://www.codemenschen.at/impressum" target="_blank" rel="noopener">
                {dict.footer.imprint}
              </a>
              <Link href={`/${locale}/terms`}>{dict.legal.terms.title}</Link>
              <Link href={`/${locale}/withdrawal`}>{dict.legal.withdrawal.title}</Link>
            </div>
          </div>
          <div className="wrap">
            <p className="footer-legal">{dict.footer.legal}</p>
          </div>
        </footer>
      </body>
    </html>
  );
}
