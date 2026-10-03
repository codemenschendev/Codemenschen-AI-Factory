import { notFound } from "next/navigation";
import { LegalPage } from "@/components/LegalPage";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { rebrand } from "@/lib/brand";

/** Same company and processing as Appmitki, with Sofabuilt's name. Terms come with the first sale. */
export default async function Page({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();

  return <LegalPage locale={locale as Locale} d={rebrand(getDict(locale as Locale), "sofabuilt")} doc="privacy" docs={["imprint", "privacy"]} />;
}
