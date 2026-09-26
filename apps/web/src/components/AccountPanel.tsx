"use client";

import { useEffect, useState } from "react";
import { getToken, setToken, useToken } from "@/lib/token";
import Link from "next/link";
import { api } from "@/lib/api";
import { AdAccountsPanel } from "@/components/AdAccountsPanel";
import { eur, type Dict, type Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";

interface ProjectRow {
  id: string;
  name: string;
  kind?: "app" | "site";
  site_url?: string | null;
  status: string;
  build_starts_at: string | null;
  created_at: string;
  order: { total_one_time_eur: number; hosting_monthly_eur: number; status: string };
  events: { type: string; at: string }[];
}

interface ProtoRow {
  id: string;
  kind: "site" | "app" | "ads" | "email" | "campaign";
  status: string;
  title: string | null;
  prompt: string;
  bought: boolean;
  expires_at: string | null;
  created_at: string;
}

/** Whole days until the server drops it; null for a bought one, which stays. */
function daysLeft(expires: string | null): number | null {
  if (!expires) return null;
  const left = Date.parse(expires) - Date.now();

  return left <= 0 ? 0 : Math.ceil(left / 86400_000);
}

export function AccountPanel({ locale, d }: { locale: Locale; d: Dict }) {
  // undefined = not looked at localStorage yet (server render + first paint):
  // render a quiet placeholder then, never the sign-in form — otherwise every
  // reload flashes "enter your e-mail" before the projects appear.
  const token = useToken();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [me, setMe] = useState<{ email: string; admin?: boolean; projects: ProjectRow[] } | null>(null);
  const [protos, setProtos] = useState<ProtoRow[] | null>(null);

  // Pick up the token handed over by the signed verify redirect (#token=…).
  useEffect(() => {
    // The magic link lands here with the token in the hash. Store it and take it off the URL.
    const fromHash = new URLSearchParams(window.location.hash.slice(1)).get("token");
    if (fromHash) {
      setToken(fromHash);
      history.replaceState(null, "", window.location.pathname);
    }
    const stored = getToken();
    // A project page sent the visitor here to sign in: go back once a token exists.
    const next = localStorage.getItem("aifactory-next");
    // The prototype form sent them, to build ads or a second prototype: back to the form. A share
    // page sent them, to make its one change: back to that prototype.
    if (stored && next && (next.startsWith(`/${locale}/account/`) || next === `/${locale}/prototype` || /^\/(de|en)\/p\/[0-9a-f-]{36}$/.test(next))) {
      localStorage.removeItem("aifactory-next");
      window.location.replace(next);
    }
  }, [locale]);

  useEffect(() => {
    if (!token) return;
    api<{ email: string; admin: boolean; projects: ProjectRow[] }>("/me/projects", { token })
      .then(setMe)
      .catch(() => setToken(null));
    // Its own request: a failing list of prototypes must not sign the customer out.
    api<{ prototypes: ProtoRow[] }>("/me/prototypes", { token })
      .then((r) => setProtos(r.prototypes))
      .catch(() => setProtos([]));
  }, [token]);

  const a = d.account;
  const dateFmt = (iso: string) => new Date(iso).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB");

  if (token === undefined) {
    return <p className="est-empty">…</p>;
  }

  if (!token) {
    return (
      <div className="acc-signin">
        <span className="acc-signin-ico">
          <Icon name="mail" />
        </span>
        <h1>{a.signInTitle}</h1>
        <p className="acc-lead">{a.signInLead}</p>
        {sent ? (
          <p className="acc-sent">
            <Icon name="check" />
            <span>{a.sent}</span>
          </p>
        ) : (
          <form
            className="acc-form"
            onSubmit={async (e) => {
              e.preventDefault();
              await api("/auth/magic-link", {
                method: "POST",
                body: JSON.stringify({ email, locale }),
              });
              setSent(true);
            }}
          >
            <label htmlFor="acc-email">{a.emailLabel}</label>
            <input
              id="acc-email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
            <button className="btn btn-primary btn-block" type="submit">
              {a.send}
            </button>
          </form>
        )}
      </div>
    );
  }

  const monthUnit = locale === "de" ? "Monat" : "month";

  if (!me) {
    return <p className="est-empty">…</p>;
  }

  return (
    <div className="acc">
      <header className="acc-head">
        <div>
          <h1>{a.hello}</h1>
          <p className="acc-who">
            <span>{me.email}</span>
            {/* The operator's own entrance. Shown to an admin only, and the page behind it asks the
                server again: this link hides nothing, it just saves typing the URL. */}
            {me.admin && <Link href={`/${locale}/admin`}>{d.admin.title}</Link>}
            <button
              type="button"
              className="pp-link"
              onClick={() => {
                setToken(null);
                setMe(null);
              }}
            >
              {a.logout}
            </button>
          </p>
        </div>
        <Link className="btn btn-primary" href={`/${locale}/prototype`}>
          {a.protoNew}
        </Link>
      </header>

      <section className="acc-sec">
        <h2>
          {a.projects}
          {me.projects.length > 0 && <span className="acc-count">{me.projects.length}</span>}
        </h2>
        {me.projects.length === 0 ? (
          <div className="acc-empty">
            <p>{a.projectsEmpty}</p>
            <Link href={`/${locale}#prices`}>{a.seePrices}</Link>
          </div>
        ) : (
          <div className="acc-grid">
            {me.projects.map((p) => {
              const live = p.status === "PUBLISHED" || p.status === "live" || p.status === "READY";

              return (
                <Link className="acc-card acc-project" key={p.id} href={`/${locale}/account/${p.id}`}>
                  <div className="acc-card-top">
                    <span className="acc-ico">
                      <Icon name={p.kind ?? "app"} />
                    </span>
                    <span className={`acc-pill${live ? " is-ok" : p.status === "FAILED" ? " is-warn" : ""}`}>
                      {a.projectState[p.status] ?? a.projectState.BUILDING}
                    </span>
                  </div>
                  <h3>{p.name}</h3>
                  <dl className="acc-facts">
                    {p.site_url && (
                      <div>
                        <dt>{a.liveAt}</dt>
                        <dd className="num">{p.site_url.replace(/^https?:\/\//, "")}</dd>
                      </div>
                    )}
                    <div>
                      <dt>{a.total}</dt>
                      <dd className="num">{eur(p.order.total_one_time_eur, locale)}</dd>
                    </div>
                    {p.order.hosting_monthly_eur > 0 && (
                      <div>
                        <dt>{a.hosting}</dt>
                        <dd className="num">
                          {eur(p.order.hosting_monthly_eur, locale)}/{monthUnit}
                        </dd>
                      </div>
                    )}
                    {p.build_starts_at && (
                      <div>
                        <dt>{a.buildStarts}</dt>
                        <dd>{dateFmt(p.build_starts_at)}</dd>
                      </div>
                    )}
                  </dl>
                  <span className="acc-open">
                    {a.open}
                    <Icon name="arrow" />
                  </span>
                </Link>
              );
            })}
          </div>
        )}
      </section>

      <section className="acc-sec">
        <h2>
          {a.protos}
          {protos !== null && protos.length > 0 && <span className="acc-count">{protos.length}</span>}
        </h2>
        {protos !== null && protos.length === 0 && (
          <div className="acc-empty">
            <p>{a.protosEmpty}</p>
            <Link href={`/${locale}/prototype`}>{a.protoNew}</Link>
          </div>
        )}
        {protos !== null && protos.length > 0 && (
          <div className="acc-grid acc-grid-protos">
            {protos.map((p) => {
              const left = daysLeft(p.expires_at);
              const label = p.title ?? (p.prompt.split(/\n/, 1)[0].trim() || d.proto.kinds[p.kind]);
              const open = p.status !== "expired" && p.status !== "failed";
              const state = p.bought ? a.protoBought : a.protoState[p.status] ?? p.status;
              const inner = (
                <>
                  <span className="acc-ico">
                    <Icon name={p.kind} />
                  </span>
                  <div className="acc-proto-text">
                    <strong>{label}</strong>
                    <span>
                      {d.proto.kinds[p.kind]} · {dateFmt(p.created_at)}
                      {!p.bought && p.status === "ready" && left !== null && (
                        <> · {left === 1 ? d.proto.oneDayLeft : d.proto.daysLeft.replace("{n}", String(left))}</>
                      )}
                    </span>
                  </div>
                  <span className={`acc-pill${p.status === "ready" || p.bought ? " is-ok" : !open ? " is-off" : ""}`}>{state}</span>
                </>
              );

              return open ? (
                <Link className="acc-card acc-proto" key={p.id} href={`/${locale}/p/${p.id}`}>
                  {inner}
                </Link>
              ) : (
                <div className="acc-card acc-proto is-gone" key={p.id}>
                  {inner}
                </div>
              );
            })}
          </div>
        )}
      </section>

      {/* The customer's own ad accounts. Their settings, not ours: the connection is theirs to
          make and theirs to cut, so the steps live next to the field. */}
      <div className="acc-sec acc-ads">
        <AdAccountsPanel d={d} token={token} />
      </div>
    </div>
  );
}
