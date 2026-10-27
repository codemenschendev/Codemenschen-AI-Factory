import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AppDesk } from "@/components/AppDesk";
import { appDesk } from "@/dictionaries/appDesk";
import { isLocale, type Locale } from "@/lib/i18n";
import "../../../../prototype.css";
import "../../../../sofabuilt.css";

/**
 * Variant B of the ad landing test (2026-10-27, Patrick's layout): the app desk, chat first and the
 * parts and price beside it, on a phone below it. The proxy sends half of the Meta visitors here and
 * half to the landing (variant A), at the ad's own address. data-ab-variant tells the tracker which.
 */
export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale } = await params;
  return { robots: { index: false, follow: true }, alternates: { canonical: `/${locale}` } };
}

export default async function AdLandingDesk({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const a = appDesk(locale);
  return (
    <main className="sb-desk-page sb-vars" data-ab-variant="desk">
      <div className="wrap">
        <AppDesk t={a.desk} doors={a.doors} locale={locale} />
      </div>
    </main>
  );
}
