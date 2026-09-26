import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PrototypeView } from "@/components/PrototypeView";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../../prototype.css";
import "../../../../share.css";

// A shared preview, kept out of search.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default async function PrototypeSharePage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale: raw, id } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="sh">
      <div className="pp-band sh-band">
        <div className="wrap">
          <h1>{d.proto.shareTitle}</h1>
        </div>
      </div>
      <div className="wrap sh-body">
        <PrototypeView id={id} locale={locale} d={d} />
      </div>
    </main>
  );
}
