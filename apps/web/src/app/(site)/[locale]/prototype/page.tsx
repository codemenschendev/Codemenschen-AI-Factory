import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PrototypeForm, type ProtoKind } from "@/components/PrototypeForm";
import { PrototypeHistory } from "@/components/PrototypeHistory";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export const metadata: Metadata = { title: "Prototype" };

const KINDS: ProtoKind[] = ["site", "app", "ads", "email", "campaign"];

export default async function PrototypePage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ kind?: string }>;
}) {
  const { locale: raw } = await params;
  const { kind } = await searchParams;
  const initialKind = KINDS.find((k) => k === kind) ?? "site";
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <h1>{d.proto.title}</h1>
      <p style={{ maxWidth: 640, marginBottom: 28 }}>{d.proto.lead}</p>
      <PrototypeForm locale={locale} d={d} initialKind={initialKind} />
      <PrototypeHistory locale={locale} d={d} />
    </main>
  );
}
