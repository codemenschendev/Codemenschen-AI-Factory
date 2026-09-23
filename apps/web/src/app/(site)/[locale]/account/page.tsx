import { notFound } from "next/navigation";
import { AccountPanel } from "@/components/AccountPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

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
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <h1>{d.account.title}</h1>
      <AccountPanel locale={locale} d={d} />
    </main>
  );
}
