import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CostPage } from "@/components/CostPage";
import { costDict } from "@/dictionaries/cost";
import { isLocale, type Locale } from "@/lib/i18n";
import { seo } from "@/lib/seo";
import { JsonLd, breadcrumbs, faqPage } from "@/lib/jsonld";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  const c = costDict(locale);

  return { title: c.metaTitle, description: c.metaDesc, ...seo(locale, "/app-kosten") };
}

export default async function AppCost({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();

  const c = costDict(raw);
  return (
    <>
      <JsonLd data={[breadcrumbs(raw, [[c.crumb, "/app-kosten"]]), faqPage(c.faq, raw)]} />
      <CostPage locale={raw as Locale} c={c} />
    </>
  );
}
