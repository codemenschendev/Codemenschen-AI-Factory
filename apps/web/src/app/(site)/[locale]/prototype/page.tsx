import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PrototypeForm } from "@/components/PrototypeForm";
import { PrototypeHistory } from "@/components/PrototypeHistory";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export const metadata: Metadata = { title: "Prototype" };

export default async function PrototypePage({
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
      <h1>{d.proto.title}</h1>
      <p style={{ maxWidth: 640, marginBottom: 28 }}>{d.proto.lead}</p>
      <PrototypeForm locale={locale} d={d} />
      <PrototypeHistory locale={locale} d={d} />
    </main>
  );
}
