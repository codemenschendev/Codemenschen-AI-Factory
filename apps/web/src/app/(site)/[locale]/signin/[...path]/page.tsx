import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SignInConfirm } from "@/components/SignInConfirm";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../../prototype.css";
import "../../../../share.css";

// A one-time link from an e-mail, kept out of search.
export const metadata: Metadata = { robots: { index: false, follow: false } };

/** The signed API addresses an e-mail may open; anything else is not a sign-in link. */
const LINKS = [/^auth\/verify\/\d+$/, /^auth\/join$/, /^prototypes\/[0-9a-f-]{36}\/confirm$/];

export default async function SignInPage({ params }: { params: Promise<{ locale: string; path: string[] }> }) {
  const { locale: raw, path } = await params;
  const link = path.join("/");
  if (!isLocale(raw) || !LINKS.some((r) => r.test(link))) notFound();
  const locale = raw as Locale;

  return (
    <main className="sh">
      <div className="wrap sh-body">
        <SignInConfirm link={link} locale={locale} d={getDict(locale)} />
      </div>
    </main>
  );
}
