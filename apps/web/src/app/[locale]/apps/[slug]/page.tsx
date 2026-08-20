import Link from "next/link";
import { notFound } from "next/navigation";
import { CATALOG, getEntry } from "@/lib/catalog";
import { HOSTING_MONTHLY } from "@ai-factory/pricing";
import { LOCALES, eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";

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
    <main className="wrap" style={{ padding: "40px 24px 72px" }}>
      <p>
        <Link href={`/${locale}#apps`} className="muted small" style={{ textDecoration: "none" }}>
          {d.detail.back}
        </Link>
      </p>

      <div className="detail-head">
        <span className="detail-icon" aria-hidden>
          {app.icon}
        </span>
        <h1 style={{ marginBottom: 0 }}>{app.name}</h1>
        <span className="badge">{t(app.cat, locale)}</span>
        <span className="badge badge-sample">{d.detail.sample}</span>
      </div>

      <div className="detail-cols" style={{ marginTop: 24 }}>
        <div>
          {app.lede && <p style={{ fontSize: 17 }}>{t(app.lede, locale)}</p>}

          {app.why && (
            <>
              <h2 style={{ marginTop: 36 }}>{d.detail.why}</h2>
              <div className="grid">
                {app.why.map((w) => (
                  <div className="card" key={w.h.en}>
                    <h3>{t(w.h, locale)}</h3>
                    <p className="muted" style={{ fontSize: 14.5 }}>
                      {t(w.p, locale)}
                    </p>
                  </div>
                ))}
              </div>
            </>
          )}

          {/* Guarded — the appwerk prototype crashed on entries without market
              data (rechni bug); here the section simply doesn't render. */}
          {app.market && app.market.length > 0 && (
            <>
              <h2 style={{ marginTop: 36 }}>{d.detail.market}</h2>
              <div className="tbl-wrap">
                <table>
                  <tbody>
                    {app.market.map((row) => (
                      <tr key={row[0].en}>
                        <td>{t(row[0], locale)}</td>
                        <td className="muted">{t(row[1], locale)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          {app.aud && (
            <>
              <h2 style={{ marginTop: 36 }}>{d.detail.aud}</h2>
              <ul className="aud-list">
                {app.aud.map((a) => (
                  <li key={a.en}>
                    <span aria-hidden>{a.i}</span> {locale === "de" ? a.de : a.en}
                  </li>
                ))}
              </ul>
            </>
          )}

          <p className="note" style={{ marginTop: 28 }}>
            {d.detail.estimateNote}
          </p>
        </div>

        <aside className="pricebox">
          <div className="row">
            <span className="muted">{d.detail.price}</span>
            <strong>{eur(app.price!, locale)}</strong>
          </div>
          <div className="row">
            <span className="muted">{d.detail.weeks}</span>
            <strong>
              {app.weeksLo}–{app.weeksHi} {d.detail.weeksUnit}
            </strong>
          </div>
          <hr />
          <div className="row">
            <span className="badge badge-type">{typeLabel}</span>
          </div>
          <div className="row">
            <span className="muted">{d.detail.hosting}</span>
            <strong>
              {app.appType === "A" ? d.detail.hostingA : `${eur(hosting, locale)}/${locale === "de" ? "Monat" : "month"}`}
            </strong>
          </div>
          <hr />
          <Link
            className="btn btn-primary btn-block"
            href={`/${locale}/checkout?app=${app.slug}`}
          >
            {d.detail.cta}
          </Link>
        </aside>
      </div>
    </main>
  );
}
