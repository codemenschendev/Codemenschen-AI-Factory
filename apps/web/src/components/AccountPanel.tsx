"use client";

import { useEffect, useState } from "react";
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
  const [token, setToken] = useState<string | null>(null);
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [me, setMe] = useState<{ email: string; projects: ProjectRow[] } | null>(null);

  // Pick up the token handed over by the signed verify redirect (#token=…).
  useEffect(() => {
    const fromHash = new URLSearchParams(window.location.hash.slice(1)).get("token");
    if (fromHash) {
      localStorage.setItem("aifactory-token", fromHash);
      history.replaceState(null, "", window.location.pathname);
    }
    setToken(localStorage.getItem("aifactory-token"));
  }, []);

  useEffect(() => {
    if (!token) return;
    api<{ email: string; projects: ProjectRow[] }>("/me/projects", { token })
      .then(setMe)
      .catch(() => {
        localStorage.removeItem("aifactory-token");
        setToken(null);
      });
  }, [token]);

  const a = d.account;

  if (!token || !me) {
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

  return (
    <div>
      <p className="muted small">
        {me.email} ·{" "}
        <button
          className="lang-toggle"
          onClick={() => {
            localStorage.removeItem("aifactory-token");
            setToken(null);
            setMe(null);
          }}
        >
          {a.logout}
        </button>
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
