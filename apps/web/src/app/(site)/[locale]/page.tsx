import Link from "next/link";
import { notFound } from "next/navigation";
import {
  PACKAGE_PRICES,
  PRICE_MIN,
  SITE_HOSTING_FREE_MONTHS,
  SITE_HOSTING_MONTHLY_EUR,
  SITE_PRICE_EUR,
} from "@ai-factory/pricing";
import { CATALOG } from "@/lib/catalog";
import { eur, getDict, isLocale, t, type Locale } from "@/lib/i18n";
import { LandingMotion } from "@/components/LandingMotion";
import "../../home.css";

/** Line icons, 24px grid, stroke follows currentColor. */
const ICONS: Record<string, string> = {
  site: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>',
  app: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
  ads: '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1Z"/><path d="M17 8.5a5 5 0 0 1 0 7M19.5 6a8.5 8.5 0 0 1 0 12"/>',
  email:
    '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
  campaign: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  preview:
    '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
  euro: '<path d="M17.5 6.5A7 7 0 1 0 17.5 17.5M4 10.5h9M4 13.5h9"/>',
  team: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-2.5-5.1"/>',
  shield:
    '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.6 8 10 4.6-1.4 8-5 8-10v-6Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  fork: '<path d="M7 2.5v7a2.5 2.5 0 0 0 5 0v-7M9.5 2.5v19M17 2.5c-1.7 1.5-2.5 4-2.5 7v3h2.5v9"/>',
  box: '<path d="m12 2.5 8.5 4.5v10L12 21.5 3.5 17V7Z"/><path d="M3.5 7 12 11.5 20.5 7M12 11.5v10"/>',
  leaf: '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c3-4 6-7 10-9"/>',
  calendar:
    '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/>',
  spark:
    '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.9L12 18.5l-1.8-5.8L4.5 10.8 10.2 9Z"/><path d="M19 3.5v3M17.5 5h3M5 17.5v3M3.5 19h3"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
  pin: '<path d="M12 21.5s-7-6.3-7-11.5a7 7 0 0 1 14 0c0 5.2-7 11.5-7 11.5Z"/><circle cx="12" cy="10" r="2.5"/>',
  bulb: '<path d="M9 18h6M10 21.5h4M12 2.5a6.5 6.5 0 0 0-4 11.6c.6.5 1 1.2 1 2V16h6v-.9c0-.8.4-1.5 1-2A6.5 6.5 0 0 0 12 2.5Z"/>',
  doc: '<path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8Z"/><path d="M14 2.5V8h5.5M8.5 15l2.5 2.5 4.5-5"/>',
  rocket:
    '<path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2-.1-2.8A2.1 2.1 0 0 0 5 15Z"/><path d="m12 15-3-3a22 22 0 0 1 2-4A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22 22 0 0 1-4 2Z"/><path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5"/>',
  store: '<path d="M4 7h16l-1 13H5Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  server:
    '<rect x="3" y="3.5" width="18" height="7" rx="2"/><rect x="3" y="13.5" width="18" height="7" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
};

function Icon({ name, className }: { name: string; className?: string }) {
  return (
    <svg
      className={className ?? "ico"}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      dangerouslySetInnerHTML={{ __html: ICONS[name] ?? "" }}
    />
  );
}

/** One icon per app idea, matching what the app does. */
const IDEA_ICONS: Record<string, string> = {
  formpilot: "doc",
  mealgrid: "fork",
  countbee: "box",
  praxo: "leaf",
  rechni: "campaign",
  shiftly: "calendar",
};

const fill = (s: string, v: Record<string, string>) =>
  s.replace(/\{(\w+)\}/g, (_, k) => v[k] ?? "");

/** The ad-spend meter: the SpendGuard promise in one picture. */
function BudgetMeter({
  of,
  stop,
  className,
}: {
  of: string;
  stop: string;
  className?: string;
}) {
  return (
    <div className={className} aria-hidden="true">
      <p className="budget-head">
        <Icon name="ads" className="meta-ico" /> Meta Ads
      </p>
      <div className="meter">
        <span style={{ width: "38%" }} />
      </div>
      <p className="budget-fig">{of}</p>
      <p className="budget-note">{stop}</p>
    </div>
  );
}

export default async function Home({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale: raw } = await params;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);
  const h = d.home;
  const a = h.art;
  const n = (x: number) =>
    x.toLocaleString(locale === "de" ? "de-AT" : "en-IE");

  // A price only where one exists in the pricing package; the rest says "after the preview".
  const services: {
    kind: keyof typeof h.services.items;
    price?: number;
    from?: boolean;
  }[] = [
    { kind: "site", price: SITE_PRICE_EUR },
    { kind: "app", price: PRICE_MIN, from: true },
    { kind: "ads", price: PACKAGE_PRICES.marketingLaunch, from: true },
    { kind: "email" },
    { kind: "campaign" },
  ];
  const trustIcons = ["preview", "euro", "team", "shield"];
  const stepPics = ["step-describe", "step-preview", "step-approve", "step-launch"];
  const priceIcons = ["site", "app", "store", "user", "ads", "server"];
  const prices = [
    {
      h: h.site.h,
      fig: fill(h.site.fig, { price: eur(SITE_PRICE_EUR, locale) }),
      p: fill(h.site.p, {
        months: String(SITE_HOSTING_FREE_MONTHS),
        monthly: eur(SITE_HOSTING_MONTHLY_EUR, locale),
      }),
    },
    ...d.pricing.items,
  ];
  const budgetOf = fill(a.budgetOf, {
    spent: eur(38, locale),
    cap: eur(100, locale),
  });
  const budgetStop = fill(a.budgetStop, { cap: eur(100, locale) });
  const proto = `/${locale}/prototype`;

  return (
    <main className="lp">
      <noscript>
        <style>{`.lp .reveal { opacity: 1; transform: none; }`}</style>
      </noscript>
      <LandingMotion />

      {/* Hero: the promise on the left, a real Appwerk site with its ad and numbers on the right */}
      <section className="hero">
        <div className="wrap">
          <div className="hero-frame">
            <div className="hero-copy">
              <p className="pill reveal">{h.eyebrow}</p>
              <h1 className="reveal">
                {h.titleA}
                <br />
                <span className="grad">{h.titleB}</span>
                <br />
                {h.titleC}
              </h1>
              <p className="lede reveal">{h.lede}</p>
              <div className="hero-ctas reveal">
                <Link className="btn btn-primary" href={proto}>
                  {h.cta} <Icon name="arrow" className="btn-ico" />
                </Link>
                <a className="btn btn-ghost" href="#prices">
                  {h.cta2}
                </a>
              </div>
            </div>

            {/* The whole photo, framed inside the page width; the copy sits on its bright window side. The two
              cards are page text, so they read in the page's language. */}
            <div className="hero-photo" aria-hidden="true">
              {/* eslint-disable-next-line @next/next/no-img-element -- one fixed hero picture */}
              <img
                src="/home/hero-people.webp"
                alt=""
                width={1960}
                height={802}
                fetchPriority="high"
              />
              <div className="float stats">
                <p className="float-label">{a.period}</p>
                <div className="stats-row">
                  <div>
                    <small>{a.visits}</small>
                    <b>{n(2384)}</b>
                    <em>↑ 12%</em>
                  </div>
                  <div>
                    <small>{a.signups}</small>
                    <b>186</b>
                    <em>↑ 24%</em>
                  </div>
                  <div>
                    <small>{a.spend}</small>
                    <b>{eur(128, locale)}</b>
                  </div>
                </div>
              </div>

              <BudgetMeter
                className="float budget"
                of={budgetOf}
                stop={budgetStop}
              />
            </div>
          </div>
        </div>
      </section>

      {/* Four promises, each one something the checkout and the terms actually hold */}
      <section className="trustbar">
        <div className="wrap">
          <div className="trust-grid">
            {h.trust.map((it, i) => (
              <div className="trust reveal" key={it.h}>
                <span className="trust-ico">
                  <Icon name={trustIcons[i]} />
                </span>
                <div>
                  <h3>{it.h}</h3>
                  <p>{it.p}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="section" id="services">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <p className="eyebrow reveal">{h.services.eyebrow}</p>
              <h2 className="reveal">{h.services.title}</h2>
              <p className="section-lede reveal">{h.services.lede}</p>
            </div>
            <Link className="sec-link" href={proto}>
              {h.services.all} <Icon name="arrow" className="btn-ico" />
            </Link>
          </div>
          <div className="svc-grid">
            {services.map((s) => {
              const it = h.services.items[s.kind];
              return (
                <Link
                  className="svc reveal"
                  key={s.kind}
                  href={`${proto}?kind=${s.kind}`}
                >
                  <div className="svc-thumb">
                    {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                    <img
                      src={`/home/svc-${s.kind}.webp`}
                      alt=""
                      width={348}
                      height={178}
                      loading="lazy"
                    />
                  </div>
                  <span className="svc-ico">
                    <Icon name={s.kind} />
                  </span>
                  <h3>{it.h}</h3>
                  <p>{it.p}</p>
                  <ul className="svc-points">
                    {it.points.map((pt) => (
                      <li key={pt}>
                        <Icon name="check" className="tick" />
                        {pt}
                      </li>
                    ))}
                  </ul>
                  <div className="svc-foot">
                    <div>
                      <small>
                        {s.price
                          ? s.from
                            ? h.services.from
                            : h.services.fixed
                          : h.services.try}
                      </small>
                      <b className={s.price ? undefined : "svc-free"}>
                        {s.price
                          ? eur(s.price, locale)
                          : h.services.afterPreview}
                      </b>
                    </div>
                    <span className="svc-go">
                      <Icon name="arrow" />
                    </span>
                  </div>
                </Link>
              );
            })}
          </div>

          {/* One idea, three parts, next to the budget guard that keeps the ads safe */}
          <div className="combo">
            <div className="combo-card reveal">
              <h2 className="combo-title">{h.campaign.title}</h2>
              <p className="combo-p">{h.campaign.p}</p>
              <div className="flow">
                <div className="flow-offer">
                  <p className="flow-label">{h.campaign.offerLabel}</p>
                  <p className="flow-input">{h.campaign.offerExample}</p>
                  <Link
                    className="btn btn-primary btn-sm"
                    href={`${proto}?kind=campaign`}
                  >
                    {h.campaign.offerBtn}{" "}
                    <Icon name="arrow" className="btn-ico" />
                  </Link>
                </div>
                {/* One rendered picture: the ad on a phone, the page on a laptop, the e-mail on a
                    phone. The three labels are real text placed over their part of the picture. */}
                <div className="flow-pic" aria-hidden="true">
                  {h.campaign.parts.map((label, i) => (
                    <p className={`part-tag part-tag-${i + 1}`} key={label}>
                      <span>{i + 1}</span> {label}
                    </p>
                  ))}
                  {/* eslint-disable-next-line @next/next/no-img-element -- one fixed picture */}
                  <img
                    src="/home/campaign-flow.webp"
                    alt=""
                    width={1656}
                    height={680}
                    loading="lazy"
                  />
                </div>
              </div>
            </div>

            <div className="combo-card budget-card reveal">
              <div className="budget-text">
                <h2 className="combo-title">{h.budget.title}</h2>
                <p className="combo-p">{h.budget.p}</p>
              </div>
              <div className="budget-box">
                <p className="budget-big">
                  {fill(h.budget.spentOf, {
                    spent: eur(38, locale),
                    cap: eur(100, locale),
                  })}
                </p>
                <div className="meter">
                  <span style={{ width: "38%" }} />
                </div>
              </div>
              <dl className="budget-rows">
                {h.budget.rows.map(([k, v], i) => (
                  <div key={k}>
                    <dt>{k}</dt>
                    <dd
                      className={
                        i === h.budget.rows.length - 1 ? "on" : undefined
                      }
                    >
                      {fill(v, { cap: eur(100, locale) })}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>
        </div>
      </section>

      <section className="section section-tint" id="how">
        <div className="wrap">
          <p className="eyebrow reveal">{d.how.eyebrow}</p>
          <h2 className="reveal">{d.how.title}</h2>
          <ol className="steps">
            {d.how.steps.map((st, i) => (
              <li className="step reveal" key={st.h}>
                <div className="step-pic">
                  {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                  <img
                    src={`/home/${stepPics[i]}.webp`}
                    alt=""
                    width={420}
                    height={230}
                    loading="lazy"
                  />
                  <span className="step-num">{i + 1}</span>
                </div>
                <h3>{st.h}</h3>
                <p>{st.p}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="section" id="prices">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <h2 className="reveal">{h.prices.title}</h2>
              <p className="section-lede reveal">{h.prices.lede}</p>
            </div>
          </div>
          <div className="price-grid">
            {prices.map((it, i) => (
              <div className="price-item reveal" key={it.h}>
                <span className="price-ico">
                  <Icon name={priceIcons[i]} />
                </span>
                <div>
                  <h3>{it.h}</h3>
                  <p className="price-fig">{it.fig}</p>
                  <p className="price-p">{it.p}</p>
                </div>
              </div>
            ))}
          </div>
          <p className="price-note reveal">{d.pricing.note}</p>
        </div>
      </section>

      <section className="section section-tint" id="apps">
        <div className="wrap">
          <div className="sec-head">
            <div>
              <p className="eyebrow reveal">{d.ideas.eyebrow}</p>
              <h2 className="reveal">{d.ideas.title}</h2>
              <p className="section-lede reveal">{d.ideas.lede}</p>
            </div>
          </div>
          <div className="idea-grid">
            {CATALOG.map((app) => {
              const taken = app.status === "built";
              const Tag = taken ? "div" : Link;
              return (
                <Tag
                  className={taken ? "idea idea-taken reveal" : "idea reveal"}
                  key={app.slug}
                  href={`/${locale}/apps/${app.slug}`}
                >
                  <div className="idea-body">
                    <p className="idea-head">
                      <span className="idea-ico">
                        <Icon name={IDEA_ICONS[app.slug] ?? "app"} />
                      </span>
                      <b>{app.name}</b>
                    </p>
                    {taken ? (
                      <p className="idea-price idea-price-taken">
                        {d.ideas.built}
                      </p>
                    ) : (
                      <p className="idea-price">
                        {app.price ? eur(app.price, locale) : d.detail.sample}
                      </p>
                    )}
                    <p className="idea-desc">{t(app.cardDesc, locale)}</p>
                    <ul className="idea-points">
                      {(app.highlights ?? []).map((pt) => (
                        <li key={pt.en}>
                          <Icon name="check" className="tick" />
                          {t(pt, locale)}
                        </li>
                      ))}
                    </ul>
                    {!taken && (
                      <span className="svc-go idea-go">
                        <Icon name="arrow" />
                      </span>
                    )}
                  </div>
                  <div className="idea-pic">
                    {/* eslint-disable-next-line @next/next/no-img-element -- fixed, small pictures */}
                    <img
                      src={`/home/idea-${app.slug}.webp`}
                      alt=""
                      width={256}
                      height={352}
                      loading="lazy"
                    />
                  </div>
                </Tag>
              );
            })}
          </div>

          {/* The own-idea box: one sentence, then the wizard with it already filled in */}
          <form
            className="own-idea reveal"
            action={`/${locale}/create`}
            method="get"
          >
            <span className="own-idea-ico">
              <Icon name="spark" />
            </span>
            <div className="own-idea-text">
              <b>{d.createBanner.title}</b>
              <small>{d.createBanner.p}</small>
            </div>
            <input
              className="own-idea-input"
              type="text"
              name="idea"
              maxLength={800}
              placeholder={d.createBanner.ph}
            />
            <button className="btn btn-primary" type="submit">
              {d.createBanner.cta} <Icon name="arrow" className="btn-ico" />
            </button>
          </form>
          <p className="placeholder-note reveal">{d.ideas.note}</p>
        </div>
      </section>

      <section className="section" id="about">
        <div className="wrap about-grid">
          <div>
            <p className="eyebrow reveal">{d.about.eyebrow}</p>
            <h2 className="reveal">{d.about.title}</h2>
            <p className="section-lede reveal">{d.about.p}</p>
            <div className="about-chips">
              {d.about.chips.map((c, i) => (
                <div className="about-chip reveal" key={c.h}>
                  <span className="trust-ico">
                    <Icon name={["team", "mail", "pin"][i]} />
                  </span>
                  <div>
                    <b>{c.h}</b>
                    <small>{c.p}</small>
                  </div>
                </div>
              ))}
            </div>
            <a
              className="btn btn-ghost reveal"
              href="https://www.codemenschen.at"
              target="_blank"
              rel="noopener"
            >
              {d.about.cta}
            </a>
          </div>
          <div className="about-pic reveal">
            {/* eslint-disable-next-line @next/next/no-img-element -- one fixed picture */}
            <img
              src="/home/about-team.webp"
              alt=""
              width={431}
              height={229}
              loading="lazy"
            />
          </div>
        </div>
      </section>

      {/* Closing band: the one next step */}
      <section className="section-final">
        <div className="wrap final-band reveal">
          <div className="final-text">
            <p className="eyebrow">{h.final.eyebrow}</p>
            <h2>{h.final.title}</h2>
            <p>{h.final.lede}</p>
          </div>
          <Link className="btn btn-light" href={proto}>
            {h.cta} <Icon name="arrow" className="btn-ico" />
          </Link>
        </div>
      </section>

      {/* Sticky CTA: appears after the first screen */}
      <div className="cta-bar" id="ctaBar">
        <span className="cta-name">{h.final.title}</span>
        <span className="cta-spacer" />
        <Link className="btn btn-primary btn-sm" href={proto}>
          {h.cta}
        </Link>
      </div>
    </main>
  );
}
