import { notFound } from "next/navigation";
import { ProjectDetail } from "@/components/ProjectDetail";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../../prototype.css";
import "../../../../account.css";
import "../../../../project.css";

export default async function ProjectPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale: raw, id } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="pj">
      <div className="wrap">
        <ProjectDetail locale={locale} d={d} projectId={id} />
      </div>
    </main>
  );
}
