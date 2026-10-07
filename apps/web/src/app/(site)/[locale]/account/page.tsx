import { notFound } from "next/navigation";
import { AccountPanel } from "@/components/AccountPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { NOINDEX } from "@/lib/seo";
import "../../../prototype.css";
import "../../../account.css";

// Private: kept out of search results (robots.txt lets Google read the tag).
export const metadata = NOINDEX;

export default async function AccountPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="acc-page">
      <div className="wrap">
        <AccountPanel locale={locale} d={d} />
      </div>
    </main>
  );
}
