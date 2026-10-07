import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { LegalPage } from "@/components/LegalPage";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { seo } from "@/lib/seo";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale: raw } = await params;
  const d = getDict(isLocale(raw) ? (raw as Locale) : "de");

  return { title: d.legal.withdrawal.title, ...seo(raw, "/withdrawal") };
}

export default async function WithdrawalPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();

  return <LegalPage locale={raw as Locale} d={getDict(raw as Locale)} doc="withdrawal" />;
}
