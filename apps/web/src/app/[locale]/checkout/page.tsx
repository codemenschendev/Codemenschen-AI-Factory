import { Suspense } from "react";
import { notFound } from "next/navigation";
import { CheckoutForm } from "@/components/CheckoutForm";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export default async function CheckoutPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <h1>{d.checkout.title}</h1>
      <Suspense fallback={<p className="est-empty">{d.checkout.working}</p>}>
        <CheckoutForm locale={locale} d={d} />
      </Suspense>
    </main>
  );
}
