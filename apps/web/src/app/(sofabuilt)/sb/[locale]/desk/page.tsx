import { notFound } from "next/navigation";
import { isLocale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";
import { Desk } from "@/components/sofabuilt/Desk";

/** The desk: chat on the left, scope and price on the right (docs/specs/sofabuilt.md). */
export default async function DeskPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ start?: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const { start } = await searchParams;
  const d = sbDict(locale);

  return (
    <main className="sb-desk-page">
      <div className="wrap">
        <h1 className="sb-h2">{d.desk.title}</h1>
        <p className="sb-section-lede">{d.desk.lede}</p>
        <Desk t={d.desk} doors={d.hero.doors} locale={locale} start={start === "idea" || start === "premium" ? start : null} />
      </div>
    </main>
  );
}
