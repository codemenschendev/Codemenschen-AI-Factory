import Link from "next/link";
import { notFound } from "next/navigation";
import { CATALOG, getEntry } from "@/lib/catalog";
import { DELIVERY_DAYS_HI, DELIVERY_DAYS_LO, HOSTING_MONTHLY } from "@ai-factory/pricing";
import { LOCALES, eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";
import { IDEA_ICONS } from "@/components/AppIdeas";
import { Icon } from "@/components/LineIcon";
import "../../../../prototype.css";
import "../../../../detail.css";

export function generateStaticParams() {
  return LOCALES.flatMap((locale) =>
    CATALOG.filter((e) => e.status === "available").map((e) => ({
      locale,
      slug: e.slug,
    })),
  );
}

export default async function AppDetail({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale: raw, slug } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const app = getEntry(slug);
  if (!app || app.status !== "available") notFound();

  const typeLabel = app.appType === "A" ? d.detail.typeA : d.detail.typeB;
  const hosting = HOSTING_MONTHLY[app.appType ?? "B"];

  return (
    <main className="ad">
      <div className="pp-band ad-band">
        <div className="wrap">
          <Link href={`/${locale}#apps`} className="ad-back">
            {d.detail.back}
          </Link>
          <div className="ad-head">
            <span className="ad-ico" aria-hidden>
              <Icon name={IDEA_ICONS[app.slug] ?? "app"} />
            </span>
            <div>
              <h1>{app.name}</h1>
              <p className="ad-badges">
                <span className="ad-badge">{t(app.cat, locale)}</span>
                <span className="ad-badge ad-badge-sample">{d.detail.sample}</span>
              </p>
            </div>
          </div>
          {app.lede && <p className="ad-lede">{t(app.lede, locale)}</p>}
        </div>
      </div>

      <div className="wrap ad-cols">
        <div className="ad-main">
          {app.why && (
            <section className="ad-sec">
              <h2>{d.detail.why}</h2>
              <div className="ad-why">
                {app.why.map((w, i) => (
                  <div className="ad-card" key={w.h.en}>
                    <span className="ad-num">{i + 1}</span>
                    <h3>{t(w.h, locale)}</h3>
                    <p>{t(w.p, locale)}</p>
                  </div>
                ))}
              </div>
            </section>
          )}

          {/* Guarded: the appwerk prototype crashed on entries without market
              data (rechni bug); here the section simply doesn't render. */}
          {app.market && app.market.length > 0 && (
            <section className="ad-sec">
              <h2>{d.detail.market}</h2>
              <dl className="ad-market">
                {app.market.map((row) => (
                  <div key={row[0].en}>
                    <dt>{t(row[0], locale)}</dt>
                    <dd>{t(row[1], locale)}</dd>
                  </div>
                ))}
              </dl>
            </section>
          )}

          {app.aud && (
            <section className="ad-sec">
              <h2>{d.detail.aud}</h2>
              <ul className="ad-aud">
                {app.aud.map((a) => (
                  <li key={a.en}>
                    <Icon name="check" className="pp-tick" />
                    {locale === "de" ? a.de : a.en}
                  </li>
                ))}
              </ul>
            </section>
          )}

          <p className="ad-note">{d.detail.estimateNote}</p>
        </div>

        <aside className="ad-price">
          {/* eslint-disable-next-line @next/next/no-img-element -- the home page's fixed idea pictures */}
          <img src={`/home/idea-${app.slug}.webp`} alt="" width={360} height={200} />
          <div className="ad-price-body">
            <div className="ad-price-fig">
              <span>{d.detail.price}</span>
              <strong>{eur(app.price!, locale)}</strong>
            </div>
            <div className="ad-row">
              <span>{d.detail.delivery}</span>
              <strong>
                {DELIVERY_DAYS_LO}–{DELIVERY_DAYS_HI} {d.detail.daysUnit}
              </strong>
            </div>
            <div className="ad-row">
              <span>{d.detail.hosting}</span>
              <strong>
                {app.appType === "A" ? d.detail.hostingA : `${eur(hosting, locale)}/${locale === "de" ? "Monat" : "month"}`}
              </strong>
            </div>
            <div className="ad-row">
              <span className="badge badge-type">{typeLabel}</span>
            </div>
            <Link className="btn btn-primary ad-cta" href={`/${locale}/checkout?app=${app.slug}`}>
              {d.detail.cta}
              <Icon name="arrow" className="pp-btn-ico" />
            </Link>
          </div>
        </aside>
      </div>
    </main>
  );
}
