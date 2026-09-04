import Link from "next/link";
import { notFound } from "next/navigation";
import { CATALOG } from "@/lib/catalog";
import { APP_ART, CONCEPT_ART } from "@/lib/art";
import { SCREENS } from "@/lib/screens";
import { eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";
import { LandingMotion } from "@/components/LandingMotion";
import "../home.css";

/** Inline SVG / mockup markup from our own modules, never user input. */
function Art({ html, className }: { html: string; className?: string }) {
  return <div className={className} aria-hidden dangerouslySetInnerHTML={{ __html: html }} />;
}

function PhoneFrame({ html, className }: { html: string; className?: string }) {
  return (
    <div className={className ? `ph-frame ${className}` : "ph-frame"} aria-hidden>
      <Art html={html} />
    </div>
  );
}

/** The card visual: the real app screen in a phone frame, illustration as fallback. */
function CardVisual({ slug }: { slug: string }) {
  const screen = SCREENS[slug]?.[0];
  if (screen) {
    return (
      <div className="card-phone">
        <PhoneFrame html={screen} />
      </div>
    );
  }
  return <Art className="card-art" html={APP_ART[slug] ?? ""} />;
}

export default async function Home({ params }: { params: Promise<{ locale: string }> }) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  const open = CATALOG.filter((a) => a.status === "available");
  const cheapest = Math.min(...open.map((a) => a.price ?? Infinity));
  const stepArt = ["validate", "license", "build", "dashboard"];
  const ownArt = ["build", "dashboard", "validate", "license"];

  return (
    <main className="lp">
      <noscript>
        <style>{`.lp .reveal { opacity: 1; transform: none; }`}</style>
      </noscript>
      <LandingMotion />

      {/* Hero: the promise, the two paths in, and the product itself */}
      <section className="hero">
        <div className="wrap hero-grid">
          <div>
            <p className="eyebrow reveal">{d.hero.eyebrow}</p>
            <h1 className="reveal">
              {d.hero.titleA}
              <br />
              {d.hero.titleB}
            </h1>
            <p className="lede reveal">{d.hero.lede}</p>
            <div className="hero-ctas reveal">
              <a className="btn btn-primary" href="#apps">
                {d.hero.ctaIdeas}
              </a>
              <Link className="btn btn-ghost" href={`/${locale}/create`}>
                {d.hero.ctaCreate}
              </Link>
            </div>
            <div className="trust-chips reveal">
              {d.hero.chips.map((c) => (
                <span className="tchip" key={c}>
                  {c}
                </span>
              ))}
            </div>
          </div>
          <div className="hero-art reveal">
            <div className="hero-phones">
              <PhoneFrame html={SCREENS.praxo[2]} />
              <PhoneFrame html={SCREENS.formpilot[2]} className="hero-phone-2" />
            </div>
          </div>
        </div>
      </section>

      {/* Proof band: three honest numbers, right under the fold */}
      <section className="statband">
        <div className="wrap statband-inner">
          <div className="stat">
            <span className="stat-n">{open.length}</span>
            <span>{d.stats.apps}</span>
          </div>
          <div className="stat">
            <span className="stat-n">{eur(cheapest, locale)}</span>
            <span>{d.stats.price}</span>
          </div>
          <div className="stat">
            <span className="stat-n">{d.stats.ownN}</span>
            <span>{d.stats.own}</span>
          </div>
        </div>
      </section>

      <section className="section" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{d.how.eyebrow}</p>
          <h2 className="reveal">{d.how.title}</h2>
          <div className="steps">
            {d.how.steps.map((s, i) => (
              <div className="step reveal" key={s.h}>
                <Art className="step-art" html={CONCEPT_ART[stepArt[i]] ?? ""} />
                <span className="step-num">{String(i + 1).padStart(2, "0")}</span>
                <h3>{s.h}</h3>
                <p>{s.p}</p>
              </div>
            ))}
          </div>
          <p className="reveal" style={{ textAlign: "center", marginTop: "2.6rem" }}>
            <a className="btn btn-primary" href="#apps">
              {d.how.cta}
            </a>
          </p>
        </div>
      </section>

      {/* Division of labor: what the factory does, what stays your call */}
      <section className="section section-dark">
        <div className="wrap">
          <p className="eyebrow reveal">{d.split.eyebrow}</p>
          <h2 className="reveal">{d.split.title}</h2>
          <div className="split">
            <div className="split-col reveal">
              <h3>{d.split.weH}</h3>
              <ul>
                {d.split.we.map((li) => (
                  <li key={li}>{li}</li>
                ))}
              </ul>
            </div>
            <div className="split-col reveal">
              <h3>{d.split.youH}</h3>
              <ul>
                {d.split.you.map((li) => (
                  <li key={li}>{li}</li>
                ))}
              </ul>
            </div>
          </div>
          <p className="split-note reveal">{d.split.note}</p>
        </div>
      </section>

      <section className="section" id="apps">
        <div className="wrap">
          <p className="eyebrow reveal">{d.ideas.eyebrow}</p>
          <h2 className="reveal">{d.ideas.title}</h2>
          <p className="section-lede reveal">{d.ideas.lede}</p>
          <div className="cards">
            {CATALOG.map((app) => {
              const taken = app.status === "built";
              return (
                <article className={taken ? "card card-taken reveal" : "card reveal"} key={app.slug}>
                  <span className={taken ? "badge badge-taken" : "badge badge-sample"}>
                    {taken ? d.ideas.built : d.detail.sample}
                  </span>
                  <CardVisual slug={app.slug} />
                  <h3>{app.name}</h3>
                  <p className="card-cat">{t(app.cat, locale)}</p>
                  <p className="card-desc">{t(app.cardDesc, locale)}</p>
                  <dl className="card-data">
                    <div>
                      <dt>{d.ideas.from}</dt>
                      <dd>{taken || !app.price ? "—" : eur(app.price, locale)}</dd>
                    </div>
                    <div>
                      <dt>{d.ideas.delivery}</dt>
                      <dd>
                        {taken || !app.weeksLo
                          ? "—"
                          : `${app.weeksLo}–${app.weeksHi} ${d.detail.weeksUnit}`}
                      </dd>
                    </div>
                    <div>
                      <dt>{d.ideas.type}</dt>
                      <dd>{app.appType === "A" ? d.detail.typeA : d.detail.typeB}</dd>
                    </div>
                  </dl>
                  {taken ? (
                    <span className="btn btn-ghost btn-block btn-disabled">{d.detail.ctaTaken}</span>
                  ) : (
                    <Link className="btn btn-primary btn-block" href={`/${locale}/apps/${app.slug}`}>
                      {d.ideas.view}
                    </Link>
                  )}
                </article>
              );
            })}
            <article className="card card-create reveal">
              <Art className="card-art" html={CONCEPT_ART.build} />
              <h3>{d.createBanner.title}</h3>
              <p className="card-desc" style={{ marginTop: 8 }}>
                {d.createBanner.p}
              </p>
              <Link className="btn btn-primary btn-block" href={`/${locale}/create`}>
                {d.createBanner.cta}
              </Link>
            </article>
          </div>
          <p className="placeholder-note reveal">{d.ideas.note}</p>
        </div>
      </section>

      {/* Honest risk: same typographic dignity as everything else, no primary
          CTA adjacent (appwerk docs 15/19/26). */}
      <section className="section section-risk" id="honest">
        <div className="wrap">
          <p className="eyebrow reveal">{d.honest.eyebrow}</p>
          <h2 className="reveal">{d.honest.title}</h2>
          <div className="ways-grid ways-2">
            {d.honest.items.map((it, i) => (
              <div className="way reveal" key={it.h}>
                <Art className="way-art" html={CONCEPT_ART[ownArt[i]] ?? ""} />
                <h3>{it.h}</h3>
                <p>{it.p}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section section-dark" id="about">
        <div className="wrap wrap-narrow">
          <p className="eyebrow reveal">{d.about.eyebrow}</p>
          <h2 className="reveal">{d.about.title}</h2>
          <p className="section-lede reveal">{d.about.p}</p>
          <a
            className="btn btn-ghost reveal"
            href="https://www.codemenschen.at"
            target="_blank"
            rel="noopener"
          >
            {d.about.cta}
          </a>
        </div>
      </section>

      <section className="section section-final">
        <div className="wrap wrap-narrow center">
          <h2 className="reveal">{d.final.title}</h2>
          <p className="section-lede reveal">{d.final.lede}</p>
          <div className="hero-ctas reveal" style={{ justifyContent: "center" }}>
            <a className="btn btn-primary" href="#apps">
              {d.final.cta1}
            </a>
            <Link className="btn btn-ghost" href={`/${locale}/create`}>
              {d.final.cta2}
            </Link>
          </div>
        </div>
      </section>

      {/* Sticky CTA: appears after the first screen, keeps the catalog one tap away */}
      <div className="cta-bar" id="ctaBar">
        <span className="cta-name">{d.bar.title}</span>
        <span className="cta-price">{d.bar.sub}</span>
        <span className="cta-spacer" />
        <a className="btn btn-primary btn-sm" href="#apps">
          {d.bar.cta}
        </a>
      </div>
    </main>
  );
}
