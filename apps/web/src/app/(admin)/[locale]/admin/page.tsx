import { notFound } from "next/navigation";
import { AdminPanel } from "@/components/AdminPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

// The operator's page: reachable by URL, never in the nav, never in an index. The real guard is
// the API, which answers 403 to anyone whose token is not flagged as an admin.
export default async function AdminPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;

  return <AdminPanel locale={locale} d={getDict(locale)} />;
}
