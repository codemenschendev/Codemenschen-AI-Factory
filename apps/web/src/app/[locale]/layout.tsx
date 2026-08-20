import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { LOCALES, getDict, isLocale, type Locale } from "@/lib/i18n";
import { LangSwitch } from "@/components/LangSwitch";
import "../globals.css";

export const metadata: Metadata = {
  title: "Appwerk — AI App Factory",
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
        <nav className="nav">
          <Link href={`/${locale}`} className="nav-logo">
            Appwerk
          </Link>
          <div className="nav-links">
            <Link href={`/${locale}#apps`}>{dict.nav.ideas}</Link>
            <Link href={`/${locale}/create`}>{dict.nav.create}</Link>
            <Link href={`/${locale}#how`}>{dict.nav.how}</Link>
          </div>
          <LangSwitch current={locale as Locale} />
        </nav>
        {children}
        <footer className="site">
          <div className="wrap">
            <span>{dict.footer.by}</span>
            <span className="counsel">{dict.footer.legal}</span>
          </div>
        </footer>
      </body>
    </html>
  );
}
