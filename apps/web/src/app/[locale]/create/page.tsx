import { notFound } from "next/navigation";
import { CreateWizard } from "@/components/CreateWizard";
import { getDict, isLocale, type Locale } from "@/lib/i18n";

/**
 * Carries a free prototype into the paid wizard.
 *
 * The share page's "turn it into a real app" link arrives as ?from=<prototype id>. Fetching the
 * sentence here rather than in the browser means the textarea is already filled on first paint,
 * so nobody is asked to type their idea a second time. A missing or expired prototype simply
 * leaves the field empty: the wizard still works, it just starts blank.
 */
async function ideaFrom(id: string | undefined): Promise<string> {
  if (!id) return "";
  // Server-side, so prefer the internal container URL over the public one.
  const base = process.env.API_BASE_URL ?? process.env.NEXT_PUBLIC_API_BASE_URL ?? "";
  if (!base) return "";
  try {
    const res = await fetch(`${base}/api/prototypes/${encodeURIComponent(id)}`, {
      cache: "no-store",
      signal: AbortSignal.timeout(4000),
    });
    if (!res.ok) return "";
    const data = (await res.json()) as { prompt?: string };
    return (data.prompt ?? "").slice(0, 800);
  } catch {
    return "";
  }
}

export default async function CreatePage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ from?: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const initialIdea = await ideaFrom((await searchParams).from);

  return (
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <h1>{d.wizard.title}</h1>
      <p className="muted" style={{ fontSize: 17, marginBottom: 32 }}>
        {d.wizard.lede}
      </p>
      <CreateWizard locale={locale} d={d} initialIdea={initialIdea} />
    </main>
  );
}
