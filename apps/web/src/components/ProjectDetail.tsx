"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { api, API_BASE } from "@/lib/api";
import { eur, type Dict, type Locale } from "@/lib/i18n";

interface Detail {
  id: string;
  name: string;
  status: string;
  failed_reason: string | null;
  criteria: { key: string; criterion: string; kind: string; status: string }[];
  builds: { id: number; platform: string; version: string; status: string }[];
  runs: { stage: string; attempt: number; status: string; started_at: string | null; finished_at: string | null }[];
  store_assets?: { id: number; kind: string; locale: string | null; content: string | null; status: string }[];
  submissions?: { id: number; store: string; status: string; account_ref: string | null }[];
  packages?: Record<string, boolean>;
  order?: { total_one_time_eur: number };
}

const runDot: Record<string, string> = {
  succeeded: "var(--valid)",
  failed: "#B3261E",
  running: "var(--accent)",
  queued: "var(--border)",
};

export function ProjectDetail({ locale, d, projectId }: { locale: Locale; d: Dict; projectId: string }) {
  const [token] = useState<string | null>(() =>
    typeof window === "undefined" ? null : localStorage.getItem("aifactory-token"),
  );
  const [p, setP] = useState<Detail | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    if (!token) return;
    api<Detail>(`/me/projects/${projectId}`, { token }).then(setP).catch(() => setP(null));
  }, [token, projectId]);

  useEffect(load, [load]);

  // Live-ish while the factory works
  useEffect(() => {
    if (!p || ["READY", "FAILED", "COMPLETED"].includes(p.status)) return;
    const t = setInterval(load, 20_000);
    return () => clearInterval(t);
  }, [p, load]);

  if (!token)
    return (
      <p className="note">
        <Link href={`/${locale}/account`}>{d.project.back}</Link>
      </p>
    );
  if (!p) return <p className="est-empty">…</p>;

  const download = async (buildId: number) => {
    setBusy(true);
    try {
      const res = await fetch(`${API_BASE}/api/me/projects/${p.id}/builds/${buildId}/download`, {
        headers: { authorization: `Bearer ${token}` },
      });
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = `${p.name}-build-${buildId}.tar.gz`;
      a.click();
      URL.revokeObjectURL(a.href);
    } finally {
      setBusy(false);
    }
  };

  const approve = async () => {
    setBusy(true);
    try {
      await api(`/me/projects/${p.id}/approve-review`, { method: "POST", token });
      load();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <p>
        <Link href={`/${locale}/account`} className="muted small" style={{ textDecoration: "none" }}>
          {d.project.back}
        </Link>
      </p>
      <div className="detail-head">
        <h1 style={{ marginBottom: 0 }}>{p.name}</h1>
        <span className="badge badge-type">{p.status}</span>
      </div>
      {p.order && (
        <p className="muted small num">{d.account.total}: {eur(p.order.total_one_time_eur, locale)}</p>
      )}

      {p.status === "FAILED" && <p className="note">{d.project.failed}</p>}
      {p.status === "READY" && <p className="note" style={{ background: "#E8F4EE", color: "var(--valid)" }}>{d.project.approved}</p>}

      <div className="wizard-cols" style={{ marginTop: 24 }}>
        <div>
          <h2>{d.project.timeline}</h2>
          <ul className="aud-list">
            {[...p.runs].reverse().map((r, i) => (
              <li key={i} style={{ display: "flex", alignItems: "center", gap: 10 }}>
                <span
                  aria-hidden
                  style={{
                    width: 10, height: 10, borderRadius: 99, display: "inline-block",
                    background: runDot[r.status] ?? "var(--border)",
                  }}
                />
                <code style={{ fontSize: 13 }}>{r.stage}#{r.attempt}</code>
                <span className="muted small">{r.status}</span>
              </li>
            ))}
          </ul>

          {(p.store_assets?.length ?? 0) > 0 && (
            <>
              <h2 style={{ marginTop: 28 }}>{d.project.assets}</h2>
              <p className="muted small">{d.project.assetsHint}</p>
              <div className="tbl-wrap">
                <table>
                  <tbody>
                    {p.store_assets!.map((a) => (
                      <tr key={a.id}>
                        <td>
                          {d.project.kinds[a.kind] ?? a.kind}
                          {a.locale ? ` · ${a.locale.toUpperCase()}` : ""}
                        </td>
                        <td className="muted" style={{ whiteSpace: "pre-wrap" }}>
                          {a.content}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          <h2 style={{ marginTop: 28 }}>{d.project.criteria}</h2>
          <div className="tbl-wrap">
            <table>
              <tbody>
                {p.criteria.map((c) => (
                  <tr key={c.key}>
                    <td>
                      <span
                        className="badge"
                        style={c.status === "passed" ? { color: "var(--valid)", borderColor: "currentColor" } : c.status === "failed" ? { color: "#B3261E", borderColor: "currentColor" } : {}}
                      >
                        {c.status}
                      </span>
                    </td>
                    <td className="muted">{c.criterion}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <aside className="est-panel">
          <h3 style={{ margin: 0 }}>{d.project.builds}</h3>
          {p.builds.length === 0 && <p className="est-empty">—</p>}
          {p.builds.map((b) => (
            <div className="row" key={b.id}>
              <span className="muted">
                {b.platform} v{b.version}
              </span>
              <button className="lang-toggle" disabled={busy} onClick={() => download(b.id)}>
                {d.project.download}
              </button>
            </div>
          ))}
          {p.status === "REVIEW" && (
            <>
              <hr />
              <p className="small muted" style={{ margin: 0 }}>{d.project.approveHint}</p>
              <button className="btn btn-primary btn-block" disabled={busy} onClick={approve}>
                {d.project.approve}
              </button>
            </>
          )}

          {["READY", "PUBLISHING", "PUBLISHED"].includes(p.status) && (
            <>
              <hr />
              <h3 style={{ margin: 0 }}>{d.project.publishing}</h3>
              {(p.submissions?.length ?? 0) === 0 ? (
                <>
                  <p className="small muted" style={{ margin: 0 }}>{d.project.publishingHint}</p>
                  <button
                    className="btn btn-primary btn-block"
                    disabled={busy}
                    onClick={async () => {
                      setBusy(true);
                      try {
                        await api(`/me/projects/${p.id}/publishing/start`, {
                          method: "POST",
                          token: token!,
                          body: JSON.stringify({ stores: ["apple", "google"] }),
                        });
                        load();
                      } finally {
                        setBusy(false);
                      }
                    }}
                  >
                    {d.project.publishStart}
                  </button>
                </>
              ) : (
                p.submissions!.map((s) => (
                  <div key={s.id} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <div className="row">
                      <strong>{d.project.stores[s.store] ?? s.store}</strong>
                      <span className="badge badge-type">{d.project.subStatus[s.status] ?? s.status}</span>
                    </div>
                    {s.status === "waiting_account" && (
                      <form
                        style={{ display: "flex", gap: 6 }}
                        onSubmit={async (e) => {
                          e.preventDefault();
                          const input = (e.currentTarget.elements.namedItem("ref") as HTMLInputElement).value;
                          setBusy(true);
                          try {
                            await api(`/me/projects/${p.id}/publishing/account`, {
                              method: "POST",
                              token: token!,
                              body: JSON.stringify({ store: s.store, account_ref: input }),
                            });
                            load();
                          } finally {
                            setBusy(false);
                          }
                        }}
                      >
                        <input
                          name="ref"
                          required
                          placeholder={d.project.accountPh}
                          style={{
                            flex: 1, padding: "8px 10px", fontSize: 13,
                            border: "1px solid var(--border)", borderRadius: "var(--radius)",
                            background: "var(--paper)", fontFamily: "var(--font-body)",
                          }}
                        />
                        <button className="lang-toggle" disabled={busy} type="submit">
                          {d.project.accountSave}
                        </button>
                      </form>
                    )}
                  </div>
                ))
              )}
            </>
          )}
        </aside>
      </div>
    </div>
  );
}
