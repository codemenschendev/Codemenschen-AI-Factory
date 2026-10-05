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
  searchParams: Promise<{ start?: string; platform?: string }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const { start, platform } = await searchParams;
  const d = sbDict(locale);

  return (
    <main className="sb-desk-page">
      <div className="wrap">
        <Desk t={d.desk} doors={d.hero.doors} locale={locale} start={start === "idea" || start === "premium" ? start : null} startPlatform={platform === "chrome" ? "chrome" : "wordpress"} />
      </div>
    </main>
  );
}
