import Link from "next/link";
import { notFound } from "next/navigation";
import { isLocale } from "@/lib/i18n";
import { sbDict } from "@/dictionaries/sofabuilt";

/** Where Stripe sends a Sofabuilt buyer back to (CheckoutController::front). */
export default async function SuccessPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const d = sbDict(locale).success;

  return (
    <main className="sb-section">
      <div className="wrap wrap-narrow sb-desk-soon">
        <h1 className="sb-h2">{d.title}</h1>
        <p className="sb-section-lede">{d.lede}</p>
        <ol className="sb-steps sb-steps-1">
          {d.next.map((n, i) => (
            <li key={n}>
              <span className="sb-step-n">{i + 1}</span>
              <p>{n}</p>
            </li>
          ))}
        </ol>
        <p>
          <Link href={`/${locale}`}>{d.back}</Link>
        </p>
      </div>
    </main>
  );
}
