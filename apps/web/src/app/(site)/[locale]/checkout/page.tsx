import { Suspense } from "react";
import { notFound } from "next/navigation";
import { CheckoutForm } from "@/components/CheckoutForm";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../prototype.css";
import "../../../create.css";
import "../../../share.css";

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
    <main className="cw co">
      <div className="pp-band cw-band co-band">
        <div className="wrap">
          <h1>{d.checkout.title}</h1>
        </div>
      </div>
      <div className="wrap cw-body">
        <Suspense fallback={<p className="co-wait">{d.checkout.working}</p>}>
          <CheckoutForm locale={locale} d={d} />
        </Suspense>
      </div>
    </main>
  );
}
