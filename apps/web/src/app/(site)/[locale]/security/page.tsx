import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { SecurityPage } from "@/components/SecurityPage";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { seo } from "@/lib/seo";
import { JsonLd, breadcrumbs } from "@/lib/jsonld";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale: raw } = await params;
  const d = getDict(isLocale(raw) ? (raw as Locale) : "de");

  return { title: d.security.title, description: d.security.lede, ...seo(raw, "/security") };
}

export default async function Security({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();

  const d = getDict(raw as Locale);
  return (
    <>
      <JsonLd data={breadcrumbs(raw, [[d.security.title, "/security"]])} />
      <SecurityPage locale={raw as Locale} d={d} />
    </>
  );
}
