"use client";

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import type { Dict } from "@/lib/i18n";

interface CampaignRow {
  id: number;
  name: string;
  status: string;
  published: boolean;
  on_google: boolean;
  keywords: number;
  negatives: number;
  waiting: number;
}

interface Keyword {
  id: number;
  text: string;
  match_type: "phrase" | "exact";
  negative: boolean;
  status: "proposed" | "approved" | "applied" | "paused";
  source: "ai" | "admin";
  live: boolean;
  error: string | null;
}

interface State {
  campaign: { id: number; name: string; status: string; on_google: boolean; google_id: string | null };
  keywords: Keyword[];
}

/**
 * The words a search campaign is bought for.
 *
 * The screen is built around one rule: Appwerk AI proposes and a person decides. A proposal is
 * shown as a proposal, nothing is ticked in advance, and only Apply changes anything on Google.
 * The count of what is waiting for a decision sits next to every campaign, because a list nobody
 * looks at is the same as no keywords at all.
 */
export function KeywordsPanel({ token, d, openId }: { token: string; d: Dict; openId?: number | null }) {
  const a = d.admin;
  const k = a.keywords;
  const [campaigns, setCampaigns] = useState<CampaignRow[]>([]);
  const [open, setOpen] = useState<State | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [draft, setDraft] = useState("");
  const [draftNegative, setDraftNegative] = useState(false);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const r = await api<{ campaigns: CampaignRow[] }>("/admin/keywords", { token }).catch(() => null);
      if (alive && r) setCampaigns(r.campaigns);
    })();
    return () => {
      alive = false;
    };
  }, [token]);

  // Arriving from a campaign screen: that campaign's list is what the person came for.
  useEffect(() => {
    if (!openId) return;
    let alive = true;
    void (async () => {
      const r = await api<State>(`/admin/marketing/${openId}/keywords`, { token }).catch(() => null);
      if (alive && r) setOpen(r);
    })();
    return () => {
      alive = false;
    };
  }, [openId, token]);

  const fail = (e: unknown) => {
    const body = e instanceof ApiError ? (e.body as { error?: string; message?: string } | null) : null;
    setNote(body?.error ?? body?.message ?? "…");
  };

  async function call<T>(path: string, init?: RequestInit): Promise<T | null> {
    setNote("");
    try {
      return await api<T>(path, { token, ...init });
    } catch (e) {
      fail(e);
      return null;
    }
  }

  async function show(id: number) {
    setBusy(`c${id}`);
    const r = await call<State>(`/admin/marketing/${id}/keywords`);
    if (r) setOpen(r);
    setBusy(null);
  }

  /** After anything that changed a list, the campaign row's counts are stale. */
  async function refreshCounts() {
    const r = await api<{ campaigns: CampaignRow[] }>("/admin/keywords", { token }).catch(() => null);
    if (r) setCampaigns(r.campaigns);
  }

  async function suggest(id: number) {
    setBusy("suggest");
    const r = await call<State & { proposed: number; skipped: number }>(`/admin/marketing/${id}/keywords/suggest`, { method: "POST" });
    if (r) {
      setOpen(r);
      setNote(k.proposedN.replace("{n}", String(r.proposed)).replace("{s}", String(r.skipped)));
      await refreshCounts();
    }
    setBusy(null);
  }

  async function set(id: number, body: Partial<Pick<Keyword, "status" | "match_type">>) {
    setBusy(`k${id}`);
    const r = await call<State>(`/admin/keywords/${id}`, { method: "PATCH", body: JSON.stringify(body) });
    if (r) {
      setOpen(r);
      await refreshCounts();
    }
    setBusy(null);
  }

  async function drop(id: number) {
    setBusy(`k${id}`);
    const r = await call<State>(`/admin/keywords/${id}`, { method: "DELETE" });
    if (r) {
      setOpen(r);
      await refreshCounts();
    }
    setBusy(null);
  }

  async function keepAll(id: number, negative: boolean) {
    setBusy(negative ? "keepN" : "keepK");
    const r = await call<State>(`/admin/marketing/${id}/keywords/keep-all`, {
      method: "POST",
      body: JSON.stringify({ negative }),
    });
    if (r) {
      setOpen(r);
      await refreshCounts();
    }
    setBusy(null);
  }

  async function add(id: number) {
    if (draft.trim() === "") return;
    setBusy("add");
    const r = await call<State>(`/admin/marketing/${id}/keywords`, {
      method: "POST",
      body: JSON.stringify({ text: draft, negative: draftNegative }),
    });
    if (r) {
      setOpen(r);
      setDraft("");
      await refreshCounts();
    }
    setBusy(null);
  }

  async function apply(id: number) {
    setBusy("apply");
    const r = await call<State & { applied: number; paused: number; pending: boolean }>(
      `/admin/marketing/${id}/keywords/apply`,
      { method: "POST" },
    );
    if (r) {
      setOpen(r);
      setNote(
        r.pending
          ? k.appliedLater
          : k.appliedN.replace("{n}", String(r.applied)).replace("{p}", String(r.paused)),
      );
      await refreshCounts();
    }
    setBusy(null);
  }

  const tone = (w: Keyword) =>
    w.status === "applied" ? "badge-live" : w.status === "proposed" ? "badge-wait" : "badge-dim";

  const label = (w: Keyword) =>
    w.status === "applied" ? k.stLive : w.status === "approved" ? k.stApproved : w.status === "paused" ? k.stPaused : k.stProposed;

  const list = (negative: boolean) => {
    const rows = (open?.keywords ?? []).filter((w) => w.negative === negative);
    if (rows.length === 0) return <p className="muted small">{k.empty}</p>;
    return (
      <table className="table">
        <tbody>
          {rows.map((w) => (
            <tr key={w.id}>
              <td style={{ width: "45%" }}>
                {w.text}
                {w.source === "ai" && <span className="badge badge-dim" style={{ marginLeft: 6 }}>{k.byAi}</span>}
                {w.error && <div className="small" style={{ color: "var(--danger)", marginTop: 2 }}>{w.error}</div>}
              </td>
              <td>
                {!negative && (
                  <select
                    value={w.match_type}
                    onChange={(e) => void set(w.id, { match_type: e.target.value as Keyword["match_type"] })}
                    disabled={busy === `k${w.id}`}
                  >
                    <option value="phrase">{k.phrase}</option>
                    <option value="exact">{k.exact}</option>
                  </select>
                )}
              </td>
              <td><span className={`badge ${tone(w)}`}>{label(w)}</span></td>
              <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                {w.status === "proposed" && (
                  <button className="btn btn-primary btn-sm" onClick={() => void set(w.id, { status: "approved" })} disabled={busy === `k${w.id}`}>
                    {k.keep}
                  </button>
                )}
                {(w.status === "approved" || w.status === "applied") && (
                  <button className="btn btn-ghost btn-sm" onClick={() => void set(w.id, { status: "paused" })} disabled={busy === `k${w.id}`}>
                    {k.pause}
                  </button>
                )}
                {!w.live && (
                  <button className="btn btn-ghost btn-sm" style={{ marginLeft: 6 }} onClick={() => void drop(w.id)} disabled={busy === `k${w.id}`}>
                    {k.remove}
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    );
  };

  const rowsAll = open?.keywords ?? [];
  const count = {
    live: rowsAll.filter((w) => w.live && w.status !== "paused").length,
    approved: rowsAll.filter((w) => w.status === "approved" && !w.live).length,
    proposed: rowsAll.filter((w) => w.status === "proposed").length,
    paused: rowsAll.filter((w) => w.status === "paused").length,
    // Taken out here but still running on Google: the next apply withdraws them.
    leaving: rowsAll.filter((w) => w.status === "paused" && w.live).length,
  };

  const head = (negative: boolean, title: string) => {
    const waiting = rowsAll.filter((w) => w.negative === negative && w.status === "proposed").length;
    const key = negative ? "keepN" : "keepK";
    return (
      <div className="kw-head">
        <h4>{title}</h4>
        {waiting > 0 && open && (
          <button className="btn btn-ghost btn-sm" onClick={() => void keepAll(open.campaign.id, negative)} disabled={busy === key}>
            {k.keepAll.replace("{n}", String(waiting))}
          </button>
        )}
      </div>
    );
  };

  return (
    <div>
      <p className="muted small" style={{ maxWidth: 680, marginTop: 0 }}>{k.intro}</p>
      {note && <p className="note" style={{ marginTop: 10 }}>{note}</p>}

      <table className="table" style={{ marginTop: 16 }}>
        <thead>
          <tr>
            <th>{k.campaign}</th>
            <th>{k.onGoogle}</th>
            <th>{k.count}</th>
            <th>{k.waiting}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {campaigns.map((c) => (
            <tr key={c.id}>
              <td>{c.name}</td>
              <td><span className={`badge ${c.on_google ? "badge-live" : "badge-wait"}`}>{c.on_google ? k.yes : k.no}</span></td>
              <td className="num">{c.keywords} / {c.negatives}</td>
              <td className="num">{c.waiting > 0 ? c.waiting : ""}</td>
              <td style={{ textAlign: "right" }}>
                <button className="btn btn-ghost btn-sm" onClick={() => void show(c.id)} disabled={busy === `c${c.id}`}>
                  {k.open}
                </button>
              </td>
            </tr>
          ))}
          {campaigns.length === 0 && (
            <tr><td colSpan={5} className="muted">{k.noCampaigns}</td></tr>
          )}
        </tbody>
      </table>

      {open && (
        <div className="card" style={{ marginTop: 24 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "baseline", flexWrap: "wrap" }}>
            <h3 style={{ margin: 0 }}>{open.campaign.name}</h3>
            <div style={{ display: "flex", gap: 8 }}>
              <button className="btn btn-ghost btn-sm" onClick={() => void suggest(open.campaign.id)} disabled={busy === "suggest"}>
                {busy === "suggest" ? k.thinking : k.suggest}
              </button>
              <button
                className="btn btn-primary btn-sm"
                onClick={() => void apply(open.campaign.id)}
                disabled={busy === "apply" || !open.campaign.on_google || count.approved + count.leaving === 0}
                title={open.campaign.on_google ? k.applyHint : k.publishFirst}
              >
                {busy === "apply" ? k.sending : open.campaign.on_google ? k.apply : k.publishFirst}
              </button>
            </div>
          </div>

          {/* Where this list stands with Google, in words, before any row. */}
          {open.campaign.on_google ? (
            <div className="kw-state kw-on">
              <b>{k.onGoogleH}</b>
              {open.campaign.google_id && <span>{k.onGoogleId.replace("{id}", open.campaign.google_id)}</span>}
              <span>{count.approved + count.leaving > 0 ? k.toSend.replace("{n}", String(count.approved + count.leaving)) : k.allSent}</span>
            </div>
          ) : (
            <div className="kw-state kw-off">
              <b>{k.notOnGoogleH}</b>
              <span>{k.notOnGoogleP}</span>
              <b style={{ marginTop: 6 }}>{k.stepsH}</b>
              <ol>
                {k.steps.map((st) => (
                  <li key={st}>{st}</li>
                ))}
              </ol>
            </div>
          )}

          <div className="kw-sum">
            <span><b className="kw-n kw-n-live">{count.live}</b> {k.sumLive}</span>
            <span><b className="kw-n">{count.approved}</b> {k.sumApproved}</span>
            <span><b className="kw-n kw-n-wait">{count.proposed}</b> {k.sumWaiting}</span>
            <span><b className="kw-n kw-n-dim">{count.paused}</b> {k.sumPaused}</span>
          </div>

          {head(false, k.positives)}
          {list(false)}

          {head(true, k.negativesTitle)}
          <p className="muted small">{k.negativesHint}</p>
          {list(true)}

          <form
            style={{ marginTop: 14, display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}
            onSubmit={(e) => {
              e.preventDefault();
              void add(open.campaign.id);
            }}
          >
            <input
              value={draft}
              placeholder={k.addPlaceholder}
              onChange={(e) => setDraft(e.target.value)}
              style={{ flex: "1 1 260px", padding: "8px 10px", border: "1px solid var(--border)", borderRadius: "var(--radius)", background: "var(--surface)" }}
            />
            <label className="small muted" style={{ display: "flex", gap: 6, alignItems: "center" }}>
              <input type="checkbox" checked={draftNegative} onChange={(e) => setDraftNegative(e.target.checked)} />
              {k.asNegative}
            </label>
            <button className="btn btn-ghost btn-sm" type="submit" disabled={busy === "add"}>{k.add}</button>
          </form>
        </div>
      )}
    </div>
  );
}
