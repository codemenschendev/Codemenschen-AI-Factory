import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { Icon } from "@/components/LineIcon";
import { PrototypeForm } from "@/components/PrototypeForm";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { anonymousPreview } from "@/lib/offer";
import "../../../prototype.css";
import "../../../landing.css";

/**
 * The landing for visitors from the Meta ads (2026-10-18). The proxy serves it at /de?utm_source=meta,
 * the link the ads already carry, so no ad had to change. Ad visitors left the home page within a
 * second: its first screen showed words and a cookie card, not the app the ad showed. Here the first
 * screen is the ad's own picture and words, and the e-mail and idea go in right there.
 */
export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const { locale } = await params;
  // Not a page of its own for search engines: the home page is the one to index.
  return { robots: { index: false, follow: true }, alternates: { canonical: `/${locale}` } };
}

const EXAMPLES = ["idea-praxo", "idea-mealgrid", "idea-shiftly"];

export default async function AdLanding({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const t = d.adLanding;

  return (
    <main className="al">
      <section id="ad-landing" className="al-hero">
        <div className="wrap al-grid">
          <figure className="al-pic">
            {/* eslint-disable-next-line @next/next/no-img-element -- one fixed picture, the ad's own */}
            <img src="/home/ad-preview.webp" alt={t.picAlt} width={900} height={433} fetchPriority="high" />
            <span className="al-badge">{t.badge}</span>
          </figure>
          <div className="al-copy">
            <h1>
              {t.h1a} <span className="al-accent">{t.h1b}</span>
            </h1>
            <p className="al-lede">{t.lede}</p>
            <PrototypeForm
              locale={locale}
              d={d}
              initialKind="app"
              kinds={["app"]}
              anonymous={await anonymousPreview()}
              compact
              submitLabel={t.cta}
              note={t.note}
              ideaPlaceholder={t.idea}
            />
          </div>
        </div>
      </section>

      <section id="ad-landing-more" className="al-more">
        <div className="wrap">
          <ul className="al-checks">
            {t.checks.map((c) => (
              <li key={c}>
                <Icon name="check" className="al-tick" />
                {c}
              </li>
            ))}
          </ul>
          <h2 className="al-ex-title">{t.examplesTitle}</h2>
          <div className="al-examples">
            {EXAMPLES.map((src) => (
              // eslint-disable-next-line @next/next/no-img-element -- the home page's fixed example pictures
              <img key={src} src={`/home/${src}.webp`} alt="" width={256} height={356} loading="lazy" />
            ))}
          </div>
          <p className="al-more-link">
            <Link href={`/${locale}`}>{t.more} →</Link>
          </p>
        </div>
      </section>
    </main>
  );
}
