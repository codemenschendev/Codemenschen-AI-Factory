import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AdminPanel } from "@/components/AdminPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

// The operator's page: reachable by URL, never in the nav, never in an index. The real guard is
// the API, which answers 403 to anyone whose token is not flagged as an admin.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function AdminPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <h1>{d.admin.title}</h1>
      <AdminPanel locale={locale} d={d} />
    </main>
  );
}
