import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { LegalPage } from "@/components/LegalPage";
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

  return { title: d.legal.terms.title, ...seo(raw, "/terms") };
}

export default async function TermsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();

  const d = getDict(raw as Locale);
  return (
    <>
      <JsonLd data={breadcrumbs(raw, [[d.legal.terms.title, "/terms"]])} />
      <LegalPage locale={raw as Locale} d={d} doc="terms" />
    </>
  );
}
