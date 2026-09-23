"use client";

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict } from "@/lib/i18n";

interface Account {
  id: number;
  platform: "google" | "meta";
  external_id: string;
  page_id: string | null;
  page_name: string | null;
  status: "pending" | "active" | "refused" | "removed";
  name: string | null;
  checked_at: string | null;
  error: string | null;
}

interface Ours {
  google_manager_id: string;
  meta_business_id: string;
}

const PLATFORMS = ["google", "meta"] as const;

/**
 * Connecting the customer's own ad account, in their settings.
 *
 * Doing this by hand took hours on our own account. Here it is one number and one button, and the
 * steps for the part that happens on the platform stand next to the field rather than in a mail
 * somebody has to find again. The status is never guessed: Check status asks the platform.
 */
export function AdAccountsPanel({ d, token }: { d: Dict; token: string }) {
  const a = d.adAccounts;
  const [ours, setOurs] = useState<Ours | null>(null);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [drafts, setDrafts] = useState<Record<string, string>>({ google: "", meta: "" });
  // Meta publishes an ad from a page, so a Meta account without one cannot run anything.
  const [page, setPage] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    api<{ ours: Ours; accounts: Account[] }>("/me/ad-accounts", { token })
      .then((r) => {
        setOurs(r.ours);
        setAccounts(r.accounts);
      })
      .catch(() => setOurs({ google_manager_id: "", meta_business_id: "" }));
  }, [token]);

  const fail = (key: string, e: unknown) => {
    const body = e instanceof ApiError ? (e.body as { error?: string; message?: string } | null) : null;
    setErrors((p) => ({ ...p, [key]: body?.error ?? body?.message ?? "…" }));
  };

  async function ask(platform: string) {
    setBusy(platform);
    setErrors((p) => ({ ...p, [platform]: "" }));
    try {
      const r = await api<{ account: Account }>("/me/ad-accounts", {
        method: "POST",
        token,
        body: JSON.stringify({
          platform,
          external_id: drafts[platform],
          ...(platform === "meta" ? { page_id: page } : {}),
        }),
      });
      setAccounts((prev) => [...prev.filter((x) => x.id !== r.account.id), r.account]);
      setDrafts((p) => ({ ...p, [platform]: "" }));
      if (platform === "meta") setPage("");
    } catch (e) {
      fail(platform, e);
    }
    setBusy(null);
  }

  async function recheck(account: Account) {
    setBusy(`r${account.id}`);
    try {
      const r = await api<{ account: Account }>(`/me/ad-accounts/${account.id}/refresh`, { method: "POST", token });
      setAccounts((prev) => prev.map((x) => (x.id === r.account.id ? r.account : x)));
    } catch (e) {
      fail(account.platform, e);
    }
    setBusy(null);
  }

  async function remove(account: Account) {
    setBusy(`d${account.id}`);
    try {
      const r = await api<{ accounts: Account[] }>(`/me/ad-accounts/${account.id}`, { method: "DELETE", token });
      setAccounts(r.accounts);
    } catch (e) {
      fail(account.platform, e);
    }
    setBusy(null);
  }

  if (!ours) return null;

  const label = (s: Account["status"]) =>
    s === "active" ? a.stActive : s === "refused" ? a.stRefused : s === "removed" ? a.stRemoved : a.stPending;

  return (
    <section style={{ marginTop: 34 }}>
      <h2 style={{ marginBottom: 6 }}>{a.title}</h2>
      <p className="muted small" style={{ maxWidth: 620 }}>{a.intro}</p>

      <div className="grid" style={{ marginTop: 16 }}>
        {PLATFORMS.map((platform) => {
          const text = a[platform];
          const mine = accounts.filter((x) => x.platform === platform);
          const ourNumber = platform === "google" ? ours.google_manager_id : ours.meta_business_id;
          return (
            <div className="card" key={platform}>
              <h3>{text.name}</h3>
              {ourNumber && (
                <p className="small muted">
                  {a.ourId}: <strong className="num">{ourNumber}</strong>
                </p>
              )}

              {mine.map((account) => (
                <div key={account.id} style={{ borderTop: "1px solid var(--border)", paddingTop: 10, marginTop: 10 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", gap: 10, alignItems: "baseline" }}>
                    <strong className="num">{account.external_id}</strong>
                    <span className="badge badge-type">{label(account.status)}</span>
                  </div>
                  {account.name && <p className="small muted" style={{ marginTop: 2 }}>{account.name}</p>}
                  {account.platform === "meta" && (
                    <p className="small muted" style={{ marginTop: 2 }}>
                      {a.pageLabel}: {account.page_name ?? account.page_id ?? a.pageMissing}
                    </p>
                  )}
                  {account.status === "pending" && <p className="note" style={{ marginTop: 6 }}>{a.pendingHint}</p>}
                  {account.error && <p className="note" style={{ marginTop: 6 }}>{account.error}</p>}
                  <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
                    <button className="btn btn-ghost" onClick={() => recheck(account)} disabled={busy === `r${account.id}`}>
                      {busy === `r${account.id}` ? a.checking : a.recheck}
                    </button>
                    <button className="btn btn-ghost" onClick={() => remove(account)} disabled={busy === `d${account.id}`}>
                      {a.remove}
                    </button>
                  </div>
                </div>
              ))}

              <form
                style={{ marginTop: 12 }}
                onSubmit={(e) => {
                  e.preventDefault();
                  void ask(platform);
                }}
              >
                <label className="small muted" htmlFor={`adacc-${platform}`}>{a.idLabel}</label>
                <div style={{ display: "flex", gap: 8, marginTop: 4 }}>
                  <input
                    id={`adacc-${platform}`}
                    required
                    value={drafts[platform]}
                    onChange={(e) => setDrafts((p) => ({ ...p, [platform]: e.target.value }))}
                    style={{
                      flex: 1,
                      padding: "9px 10px",
                      fontSize: 14.5,
                      border: "1px solid var(--border)",
                      borderRadius: "var(--radius)",
                      background: "var(--surface)",
                      fontFamily: "var(--font-body)",
                    }}
                  />
                  <button className="btn btn-primary" type="submit" disabled={busy === platform}>
                    {busy === platform ? a.checking : a.add}
                  </button>
                </div>
                <p className="small muted" style={{ marginTop: 4 }}>{text.hint}</p>
                {platform === "meta" && (
                  <>
                    <label className="small muted" htmlFor="adacc-page" style={{ display: "block", marginTop: 8 }}>
                      {a.pageLabel}
                    </label>
                    <input
                      id="adacc-page"
                      value={page}
                      onChange={(e) => setPage(e.target.value)}
                      style={{
                        width: "100%",
                        marginTop: 4,
                        padding: "9px 10px",
                        fontSize: 14.5,
                        border: "1px solid var(--border)",
                        borderRadius: "var(--radius)",
                        background: "var(--surface)",
                        fontFamily: "var(--font-body)",
                      }}
                    />
                    <p className="small muted" style={{ marginTop: 4 }}>{a.pageHint}</p>
                  </>
                )}
                {errors[platform] && <p className="note" style={{ marginTop: 6 }}>{errors[platform]}</p>}
              </form>

              <ol className="small muted" style={{ marginTop: 10, paddingLeft: 18 }}>
                {text.steps.map((s) => (
                  <li key={s} style={{ marginBottom: 3 }}>{s}</li>
                ))}
              </ol>
            </div>
          );
        })}
      </div>
    </section>
  );
}
