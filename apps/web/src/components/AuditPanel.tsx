"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface Entry {
  id: number;
  at: string;
  actor: string;
  action: string;
  subject: string | null;
  status: number | null;
  data: Record<string, unknown> | null;
  ip: string | null;
}

/** The audit log: every change made in the console and every stop the spend guard made, newest first. */
export function AuditPanel({ token, locale, d }: { token: string; locale: Locale; d: Dict }) {
  const t = d.admin.audit;
  const [entries, setEntries] = useState<Entry[] | null>(null);
  const [actor, setActor] = useState("");
  const [action, setAction] = useState("");
  const [more, setMore] = useState(false);
  const tag = locale === "de" ? "de-AT" : "en-GB";

  const read = useCallback(
    (before?: number) => {
      const q = new URLSearchParams();
      if (actor.trim()) q.set("actor", actor.trim());
      if (action.trim()) q.set("action", action.trim());
      if (before) q.set("before", String(before));
      return api<{ entries: Entry[] }>(`/admin/audit${q.size ? `?${q}` : ""}`, { token })
        .then((r) => r.entries)
        .catch(() => [] as Entry[]);
    },
    [token, actor, action],
  );

  useEffect(() => {
    let alive = true;
    void read().then((r) => {
      if (!alive) return;
      setEntries(r);
      setMore(r.length === 100);
    });
    return () => {
      alive = false;
    };
  }, [read]);

  async function older() {
    if (!entries?.length) return;
    const r = await read(entries[entries.length - 1].id);
    setEntries([...entries, ...r]);
    setMore(r.length === 100);
  }

  const tone = (s: number | null) => (s === null ? "" : s < 300 ? "badge-live" : s < 500 ? "badge-wait" : "badge-bad");

  return (
    <>
      <p className="muted small" style={{ marginTop: 0, maxWidth: 720 }}>{t.intro}</p>
      <div className="ops-toolbar">
        <input placeholder={t.actor} value={actor} onChange={(e) => setActor(e.target.value)} style={{ maxWidth: 220 }} />
        <input placeholder={t.action} value={action} onChange={(e) => setAction(e.target.value)} style={{ maxWidth: 220 }} />
      </div>
      {!entries ? (
        <p className="est-empty">{d.admin.loading}</p>
      ) : (
        <div className="tbl-wrap">
          <table>
            <thead>
              <tr>
                <th>{t.when}</th>
                <th>{t.actor}</th>
                <th>{t.action}</th>
                <th>{t.subject}</th>
                <th>{t.result}</th>
                <th>{t.details}</th>
              </tr>
            </thead>
            <tbody>
              {entries.map((e) => (
                <tr key={e.id}>
                  <td className="num">{new Date(e.at).toLocaleString(tag, { dateStyle: "short", timeStyle: "medium" })}</td>
                  <td>{e.actor}</td>
                  <td><code>{e.action}</code></td>
                  <td className="small">{e.subject ?? ""}</td>
                  <td>{e.status !== null && <span className={`badge ${tone(e.status)}`}>{e.status}</span>}</td>
                  <td className="small muted" style={{ whiteSpace: "normal", maxWidth: 420, wordBreak: "break-word" }}>
                    {e.data ? JSON.stringify(e.data) : ""}
                    {e.ip && <span style={{ display: "block" }}>{e.ip}</span>}
                  </td>
                </tr>
              ))}
              {entries.length === 0 && (
                <tr><td colSpan={6} className="muted">{t.empty}</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
      {more && <button className="btn btn-ghost btn-sm" style={{ marginTop: 12 }} onClick={() => void older()}>{t.older}</button>}
    </>
  );
}
