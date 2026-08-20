import { notFound } from "next/navigation";
import { CreateWizard } from "@/components/CreateWizard";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

export default async function CreatePage({
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
      <h1>{d.wizard.title}</h1>
      <p className="muted" style={{ fontSize: 17, marginBottom: 32 }}>
        {d.wizard.lede}
      </p>
      <CreateWizard locale={locale} d={d} />
    </main>
  );
}
