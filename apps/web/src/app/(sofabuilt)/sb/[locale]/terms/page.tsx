import { notFound } from "next/navigation";
import { LegalPage } from "@/components/LegalPage";
import { isLocale, type Locale } from "@/lib/i18n";
import { SB_LEGAL_DOCS, sbLegalDict } from "@/lib/sbLegal";

export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();

  return <LegalPage locale={locale as Locale} d={sbLegalDict(locale as Locale)} doc="terms" docs={SB_LEGAL_DOCS} />;
}
