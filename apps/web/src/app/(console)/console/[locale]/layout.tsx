import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, getDict, isLocale, type Locale } from "@/lib/i18n";
import { AccountLink } from "@/components/AccountLink";
import { LangSwitch } from "@/components/LangSwitch";
import "../../../globals.css";
import "../../../console.css";

/**
 * The customer console (console.appmitki.com): the projects of a customer of Appmitki or
 * Sofabuilt, their previews, downloads, Care and the changes they ask for. No storefront nav and
 * no ad tracking here; the legal pages stay on the storefront.
 */
export const metadata: Metadata = { title: "Console · Codemenschen", robots: { index: false, follow: false } };

export function generateStaticParams() {
  return LOCALES.map((locale) => ({ locale }));
}

const STOREFRONT = "https://appmitki.com";

export default async function ConsoleLayout({ children, params }: { children: React.ReactNode; params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const dict = getDict(locale as Locale);
  const de = locale === "de";

  return (
    <html lang={locale}>
      <body className="console">
        <header className="nav">
          <div className="wrap nav-inner">
            <Link href={`/${locale}/account`} className="nav-logo cs-logo" aria-label="Console">
              <span className="cs-mark" aria-hidden="true">C</span>
              <span>
                <b>Console</b>
                <small>{de ? "von codemenschen" : "by codemenschen"}</small>
              </span>
            </Link>
            <div className="nav-right">
              <AccountLink locale={locale as Locale} labels={{ account: dict.nav.account, login: dict.nav.login }} />
              <LangSwitch current={locale as Locale} />
            </div>
          </div>
        </header>
        {children}
        <footer className="site">
          <div className="wrap footer-inner">
            <p className="footer-small">Codemenschen GmbH, Gössendorf, Austria</p>
            <p className="footer-small">
              <a href={`${STOREFRONT}/${locale}/imprint`}>{de ? "Impressum" : "Imprint"}</a> ·{" "}
              <a href={`${STOREFRONT}/${locale}/privacy`}>{de ? "Datenschutz" : "Privacy"}</a> ·{" "}
              <a href={`${STOREFRONT}/${locale}/terms`}>{de ? "AGB" : "Terms"}</a>
            </p>
          </div>
        </footer>
      </body>
    </html>
  );
}
