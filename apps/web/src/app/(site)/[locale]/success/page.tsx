import Link from "next/link";
import { notFound } from "next/navigation";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export default async function SuccessPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="wrap-narrow" style={{ padding: "56px 24px 72px" }}>
      <h1>{d.success.title}</h1>
      <p className="muted" style={{ fontSize: 17 }}>
        {d.success.p}
      </p>
      <p>
        <Link className="btn btn-primary" href={`/${locale}/account`}>
          {d.success.cta}
        </Link>
      </p>
    </main>
  );
}
