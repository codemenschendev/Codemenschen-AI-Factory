import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SecurityPage } from "@/components/SecurityPage";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale: raw } = await params;
  const d = getDict(isLocale(raw) ? (raw as Locale) : "de");

  return { title: d.security.title, description: d.security.lede };
}

export default async function Security({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();

  return <SecurityPage locale={raw as Locale} d={getDict(raw as Locale)} />;
}
