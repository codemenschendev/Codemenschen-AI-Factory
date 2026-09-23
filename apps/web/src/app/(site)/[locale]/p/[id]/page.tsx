import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PrototypeView } from "@/components/PrototypeView";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

// A shared preview, kept out of search.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function PrototypeSharePage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale: raw, id } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="wrap" style={{ padding: "32px 24px 64px" }}>
      <h1 style={{ fontSize: "1.4rem" }}>{d.proto.shareTitle}</h1>
      <PrototypeView id={id} locale={locale} d={d} />
    </main>
  );
}
