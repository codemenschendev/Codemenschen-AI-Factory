import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Icon } from "@/components/LineIcon";
import { PrototypeForm, type ProtoKind } from "@/components/PrototypeForm";
import { PrototypeHistory } from "@/components/PrototypeHistory";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import "../../../prototype.css";

export const metadata: Metadata = { title: "Prototype" };

const KINDS: ProtoKind[] = ["site", "app", "ads", "email", "campaign"];

export default async function PrototypePage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ kind?: string }>;
}) {
  const { locale: raw } = await params;
  const { kind } = await searchParams;
  const initialKind = KINDS.find((k) => k === kind) ?? "site";
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="pp">
      <div className="pp-band">
        <div className="wrap pp-head">
          <span className="pp-pill">{d.proto.page.pill}</span>
          <h1>{d.proto.title}</h1>
          <p className="pp-lead">{d.proto.lead}</p>
        </div>
      </div>

      <div className="wrap pp-grid">
        <div className="pp-main">
          <PrototypeForm locale={locale} d={d} initialKind={initialKind} />
          <PrototypeHistory locale={locale} d={d} />
        </div>

        {/* What happens after the click, and the promises that make it safe to try. */}
        <aside className="pp-aside">
          <div className="pp-card">
            <h2>{d.proto.page.asideTitle}</h2>
            <ol className="pp-steps">
              {d.how.steps.map((s, i) => (
                <li key={s.h}>
                  <span className="pp-num">{i + 1}</span>
                  <div>
                    <b>{s.h}</b>
                    <p>{s.p}</p>
                  </div>
                </li>
              ))}
            </ol>
          </div>
          <ul className="pp-trust">
            {d.home.trust.map((t) => (
              <li key={t.h}>
                <Icon name="check" className="pp-tick" />
                <div>
                  <b>{t.h}</b>
                  <p>{t.p}</p>
                </div>
              </li>
            ))}
          </ul>
        </aside>
      </div>
    </main>
  );
}
