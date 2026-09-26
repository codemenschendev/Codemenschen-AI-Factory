import Link from "next/link";
import { notFound } from "next/navigation";
import { getDict, isLocale, type Locale } from "@/lib/i18n";
import { TagOnMount } from "@/components/TagOnMount";
import { Icon } from "@/components/LineIcon";
import "../../../prototype.css";
import "../../../share.css";

export default async function SuccessPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ kind?: string }>;
}) {
  const { locale: raw } = await params;
  const { kind } = await searchParams;
  if (!isLocale(raw)) notFound();
  const locale = raw as Locale;
  const d = getDict(locale);

  return (
    <main className="sh">
      <TagOnMount event="purchase" />
      <div className="pp-band sh-band" />
      <div className="wrap sh-body">
        <div className="sh-status sh-status-ok">
          <span className="sh-status-ico">
            <Icon name="check" />
          </span>
          <h1>{d.success.title}</h1>
          <p>{kind === "site" ? d.success.site : d.success.p}</p>
          <Link className="btn btn-primary" href={`/${locale}/account`}>
            {d.success.cta}
            <Icon name="arrow" className="pp-btn-ico" />
          </Link>
        </div>
      </div>
    </main>
  );
}
