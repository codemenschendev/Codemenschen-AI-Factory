import Link from "next/link";
import { notFound } from "next/navigation";
import { isLocale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";

/** Phase 0 placeholder: the chat desk comes in phase 1 (docs/specs/sofabuilt.md). */
export default async function DeskPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const d = sbDict(locale);

  return (
    <main className="sb-section">
      <div className="wrap wrap-narrow sb-desk-soon">
        <h1 className="sb-h2">{d.desk.title}</h1>
        <p className="sb-section-lede">{d.desk.lede}</p>
        <p>
          {d.desk.mail} <a href="mailto:developerweb@codemenschen.at">developerweb@codemenschen.at</a>
        </p>
        <Link href={`/${locale}`}>{d.desk.back}</Link>
      </div>
    </main>
  );
}
