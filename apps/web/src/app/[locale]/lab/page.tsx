import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { LabPanel } from "@/components/LabPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

// Reachable by URL, deliberately absent from the nav, and kept out of search results until
// it goes public.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function LabPage({
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
      <h1>{d.lab.title}</h1>
      <LabPanel locale={locale} d={d} />
    </main>
  );
}
