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

  if (token === undefined) {
    return <p className="est-empty">…</p>;
  }

  if (!token) {
    return (
      <div style={{ maxWidth: 480 }}>
        <p className="muted">{a.emailPrompt}</p>
        <form
          onSubmit={async (e) => {
            e.preventDefault();
            await api("/auth/magic-link", {
              method: "POST",
              body: JSON.stringify({ email, locale }),
            });
            setSent(true);
          }}
          style={{ display: "flex", gap: 10 }}
        >
          <input
            type="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            style={{
              flex: 1,
              padding: "12px",
              fontSize: 15,
              border: "1px solid var(--border)",
              borderRadius: "var(--radius)",
              background: "var(--surface)",
              fontFamily: "var(--font-body)",
            }}
          />
          <button className="btn btn-primary" type="submit">
            {a.send}
          </button>
        </form>
        {sent && <p className="note" style={{ marginTop: 14 }}>{a.sent}</p>}
      </div>
    );
  }

  const monthUnit = locale === "de" ? "Monat" : "month";

  if (!me) {
    return <p className="est-empty">…</p>;
  }

  return (
    <div>
      <p className="muted small">
        {me.email} ·{" "}
        <button
          className="lang-toggle"
          onClick={() => {
            setToken(null);
            setMe(null);
          }}
        >
          {a.logout}
        </button>
        {/* The operator's own entrance. Shown to an admin only, and the page behind it asks the
            server again: this link hides nothing, it just saves typing the URL. */}
        {me.admin && (
          <>
            {" · "}
            <Link href={`/${locale}/admin`}>{d.admin.title}</Link>
          </>
        )}
      </p>
      <section className="pp-history" style={{ marginTop: 24, marginBottom: 40 }}>
        <h2>{a.protos}</h2>
        {protos !== null && protos.length === 0 && <p className="pp-note">{a.protosEmpty}</p>}
        {protos !== null && protos.length > 0 && (
          <ul>
            {protos.map((p) => {
              const left = daysLeft(p.expires_at);
              const label = p.title ?? (p.prompt.split(/\n/, 1)[0].trim() || d.proto.kinds[p.kind]);
              const open = p.status !== "expired" && p.status !== "failed";

              return (
                <li key={p.id}>
                  <span className="pp-history-ico">
                    <Icon name={p.kind} />
                  </span>
                  <div className="pp-history-text">
                    {open ? <Link href={`/${locale}/p/${p.id}`}>{label}</Link> : <a aria-disabled="true">{label}</a>}
                    <p>
                      {d.proto.kinds[p.kind]}
                      {" · "}
                      {new Date(p.created_at).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB")}
                      {" · "}
                      {p.bought ? a.protoBought : a.protoState[p.status] ?? p.status}
                      {!p.bought && p.status === "ready" && left !== null && (
                        <>
                          {" · "}
                          {left === 1 ? d.proto.oneDayLeft : d.proto.daysLeft.replace("{n}", String(left))}
                        </>
                      )}
                    </p>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
        <p style={{ marginTop: 14 }}>
          <Link href={`/${locale}/prototype`}>{a.protoNew}</Link>
        </p>
      </section>
      {me.projects.length === 0 && <p className="est-empty">{a.empty}</p>}
      <div className="grid" style={{ marginTop: 16 }}>
        {me.projects.map((p) => (
          <div className="card" key={p.id}>
            <span className="badge badge-type">{a.kinds[p.kind ?? "app"] ?? p.kind} · {p.status}</span>
            <h3>{p.name}</h3>
            {p.site_url && (
              <div style={{ display: "flex", justifyContent: "space-between", gap: 8, fontSize: 14.5 }}>
                <span className="muted">{a.liveAt}</span>
                <a className="num" href={p.site_url} target="_blank" rel="noopener noreferrer" style={{ overflowWrap: "anywhere", textAlign: "right" }}>{p.site_url.replace(/^https?:\/\//, "")}</a>
              </div>
            )}
            <div className="row" style={{ display: "flex", justifyContent: "space-between", fontSize: 14.5 }}>
              <span className="muted">{a.total}</span>
              <strong className="num">{eur(p.order.total_one_time_eur, locale)}</strong>
            </div>
            {p.order.hosting_monthly_eur > 0 && (
              <div style={{ display: "flex", justifyContent: "space-between", fontSize: 14.5 }}>
                <span className="muted">{a.hosting}</span>
                <strong className="num">
                  {eur(p.order.hosting_monthly_eur, locale)}/{monthUnit}
                </strong>
              </div>
            )}
            {p.build_starts_at && (
              <div style={{ display: "flex", justifyContent: "space-between", fontSize: 14.5 }}>
                <span className="muted">{a.buildStarts}</span>
                <strong>
                  {new Date(p.build_starts_at).toLocaleDateString(
                    locale === "de" ? "de-AT" : "en-IE",
                  )}
                </strong>
              </div>
            )}
            <div className="card-foot">
              <Link className="btn btn-ghost" href={`/${locale}/account/${p.id}`}>
                {d.project.open}
              </Link>
            </div>
          </div>
        ))}
      </div>
      {/* The customer's own ad accounts. Their settings, not ours: the connection is theirs to
          make and theirs to cut, so the steps live next to the field. */}
      <AdAccountsPanel d={d} token={token} />
    </div>
  );
}
