import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AppDesk } from "@/components/AppDesk";
import { Icon } from "@/components/LineIcon";
import { PrototypeForm, type ProtoKind } from "@/components/PrototypeForm";
import { PrototypeHistory } from "@/components/PrototypeHistory";
import { appDesk } from "@/dictionaries/appDesk";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { appsOnly, offeredKinds } from "@/lib/offer";
import { seo } from "@/lib/seo";
import "../../../prototype.css";
import "../../../sofabuilt.css";

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  return { title: "Prototype", ...seo((await params).locale, "/prototype") };
}

// The offer switch is read from the API; a page older than a minute is built again.
export const revalidate = 60;

export default async function PrototypePage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ kind?: string; form?: string; idea?: string }>;
}) {
  const { locale: raw } = await params;
  const { kind, form, idea } = await searchParams;
  // Only what is on offer; an old link to a hidden kind opens on the first one that is.
  const kinds: ProtoKind[] = await offeredKinds();
  const initialKind = kinds.find((k) => k === kind) ?? kinds[0];
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  // With apps only, the side panel speaks about apps: the general steps name websites and ads.
  const apps = appsOnly(kinds);
  const steps = apps ? d.appDev.how.steps : d.how.steps;
  const trust = apps ? d.appDev.trust : d.home.trust;

  // Apps start on the desk: scope and price part by part, like Sofabuilt (owner, 2026-10-05).
  // The plain form stays for the free preview the desk hands over to (?form=1).
  if (apps && !form) {
    const a = appDesk(locale);
    return (
      <main className="sb-desk-page sb-vars">
        <div className="wrap">
          <AppDesk t={a.desk} doors={a.doors} locale={locale} idea={idea?.slice(0, 500)} />
        </div>
      </main>
    );
  }

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
          <PrototypeForm locale={locale} d={d} initialKind={initialKind} kinds={kinds} />
          <PrototypeHistory locale={locale} d={d} />
        </div>

        {/* What happens after the click, and the promises that make it safe to try. */}
        <aside className="pp-aside">
          <div className="pp-card">
            <h2>{d.proto.page.asideTitle}</h2>
            <ol className="pp-steps">
              {steps.map((s, i) => (
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
            {trust.map((t) => (
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
