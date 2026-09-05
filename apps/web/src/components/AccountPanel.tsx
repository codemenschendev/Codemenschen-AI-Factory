"use client";

import { useEffect, useState } from "react";
import { getToken, setToken, useToken } from "@/lib/token";
import Link from "next/link";
import { api } from "@/lib/api";
import { eur, type Dict, type Locale } from "@/lib/i18n";

interface ProjectRow {
  id: string;
  name: string;
  status: string;
  build_starts_at: string | null;
  created_at: string;
  order: { total_one_time_eur: number; hosting_monthly_eur: number; status: string };
  events: { type: string; at: string }[];
}

export function AccountPanel({ locale, d }: { locale: Locale; d: Dict }) {
  // undefined = not looked at localStorage yet (server render + first paint):
  // render a quiet placeholder then, never the sign-in form — otherwise every
  // reload flashes "enter your e-mail" before the projects appear.
  const token = useToken();
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [me, setMe] = useState<{ email: string; admin?: boolean; projects: ProjectRow[] } | null>(null);

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
    if (stored && next && next.startsWith(`/${locale}/account/`)) {
      localStorage.removeItem("aifactory-next");
      window.location.replace(next);
    }
  }, [locale]);

  useEffect(() => {
    if (!token) return;
    api<{ email: string; admin: boolean; projects: ProjectRow[] }>("/me/projects", { token })
      .then(setMe)
      .catch(() => setToken(null));
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
      {me.projects.length === 0 && <p className="est-empty">{a.empty}</p>}
      <div className="grid" style={{ marginTop: 16 }}>
        {me.projects.map((p) => (
          <div className="card" key={p.id}>
            <span className="badge badge-type">{p.status}</span>
            <h3>{p.name}</h3>
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
    </div>
  );
}
