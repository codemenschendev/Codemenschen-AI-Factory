import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { LabPanel } from "@/components/LabPanel";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../prototype.css";
import "../../../lab.css";

// Reachable by URL, deliberately absent from the nav, and kept out of search results until
// it goes public.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function LabPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="lbp">
      <div className="pp-band lbp-band">
        <div className="wrap">
          <h1>{d.lab.title}</h1>
          <p className="lbp-lede">{d.lab.intro}</p>
        </div>
      </div>
      <div className="wrap lbp-body">
        <LabPanel locale={locale} d={d} />
      </div>
    </main>
  );
}
