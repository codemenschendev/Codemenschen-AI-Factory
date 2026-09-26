import Link from "next/link";
import { CATALOG } from "@/lib/catalog";
import { eur, t, type Dict, type Locale } from "@/lib/i18n";
import { Icon } from "@/components/LineIcon";

/** One icon per app idea, matching what the app does. */
export const IDEA_ICONS: Record<string, string> = {
  formpilot: "doc",
  mealgrid: "fork",
  countbee: "box",
  praxo: "leaf",
  rechni: "campaign",
  shiftly: "calendar",
};

/** The app ideas grid and the own-idea box, shared by the home page and the app landing page. */
export function AppIdeas({ locale, d }: { locale: Locale; d: Dict }) {
  return (
    <>
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
    </>
  );
}
