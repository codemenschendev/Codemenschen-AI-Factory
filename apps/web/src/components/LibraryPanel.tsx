"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface LibraryItem {
  id: string;
  caption: string;
  project: string;
  shared: boolean;
  bytes: number;
  /** Signed and good for an hour, because an <img> tag carries no Authorization header. */
  url: string;
}

interface Stats {
  images: number;
  shared: number;
  bytes: number;
  enabled: boolean;
}

const kb = (n: number) => (n >= 1_048_576 ? `${(n / 1_048_576).toFixed(1)} MB` : `${Math.round(n / 1024)} KB`);

/**
 * The photo library, as the operator sees it.
 *
 * These are the pictures already paid for once. A render searches here before it generates, so the
 * two decisions on this screen are the ones that matter: whether a photo may travel to another
 * customer, and whether it should exist at all.
 */
export function LibraryPanel({ token, d }: { token: string; locale: Locale; d: Dict }) {
  const a = d.admin;
  const [items, setItems] = useState<LibraryItem[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<string | null>(null);
  const [draft, setDraft] = useState("");

  const load = useCallback(
    async (query = q) => {
      setBusy(true);
      try {
        const r = await api<{ stats: Stats; items: LibraryItem[] }>(
          `/admin/library${query ? `?q=${encodeURIComponent(query)}` : ""}`,
          { token },
        );
        setStats(r.stats);
        setItems(r.items);
      } finally {
        setBusy(false);
      }
    },
    [token, q],
  );

  useEffect(() => {
    void load("");
    // Once, on mount: afterwards the search button and the actions below decide when to reload.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function toggleEnabled() {
    if (!stats) return;
    const r = await api<Stats>("/admin/library/state", {
      method: "POST",
      token,
      body: JSON.stringify({ enabled: !stats.enabled }),
    });
    setStats(r);
  }

  async function setShared(item: LibraryItem, shared: boolean) {
    await api(`/admin/library/${item.id}`, { method: "POST", token, body: JSON.stringify({ shared }) });
    setItems((xs) => xs.map((x) => (x.id === item.id ? { ...x, shared } : x)));
    setStats((s) => (s ? { ...s, shared: s.shared + (shared ? 1 : -1) } : s));
  }

  async function saveCaption(item: LibraryItem) {
    const caption = draft.trim();
    setEditing(null);
    if (!caption || caption === item.caption) return;
    await api(`/admin/library/${item.id}`, { method: "POST", token, body: JSON.stringify({ caption }) });
    setItems((xs) => xs.map((x) => (x.id === item.id ? { ...x, caption } : x)));
  }

  async function remove(item: LibraryItem) {
    if (!window.confirm(a.libDeleteConfirm)) return;
    await api(`/admin/library/${item.id}`, { method: "DELETE", token });
    setItems((xs) => xs.filter((x) => x.id !== item.id));
    setStats((s) =>
      s ? { ...s, images: s.images - 1, shared: s.shared - (item.shared ? 1 : 0), bytes: s.bytes - item.bytes } : s,
    );
  }

  return (
    <>
      <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "center", marginBottom: 16 }}>
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && void load()}
          placeholder={a.libSearchHint}
          style={{ minWidth: 260 }}
        />
        <button className="btn btn-ghost" onClick={() => void load()} disabled={busy}>
          {a.search}
        </button>

        {stats && (
          <>
            <span className="small muted">
              {stats.images} · {stats.shared} {a.libShared.toLowerCase()} · {kb(stats.bytes)}
            </span>
            <button
              className="btn btn-ghost"
              onClick={() => void toggleEnabled()}
              style={{ marginLeft: "auto" }}
              title={a.libToggleHint}
            >
              {stats.enabled ? a.libOn : a.libOff}
            </button>
          </>
        )}
      </div>

      {stats && !stats.enabled && <p className="note">{a.libOffNote}</p>}
      {!busy && items.length === 0 && <p className="muted">{a.empty}</p>}

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(auto-fill, minmax(240px, 1fr))",
          gap: 16,
          marginBottom: 24,
        }}
      >
        {items.map((it) => (
          <div key={it.id} className="tbl-wrap" style={{ padding: 12 }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={it.url}
              alt={it.caption}
              loading="lazy"
              style={{ width: "100%", aspectRatio: "16 / 10", objectFit: "cover", borderRadius: 6 }}
            />

            {editing === it.id ? (
              <textarea
                value={draft}
                autoFocus
                rows={4}
                onChange={(e) => setDraft(e.target.value)}
                onBlur={() => void saveCaption(it)}
                style={{ width: "100%", marginTop: 8 }}
              />
            ) : (
              <p
                className="small"
                style={{ margin: "8px 0", cursor: "text" }}
                title={a.libCaptionHint}
                onClick={() => {
                  setEditing(it.id);
                  setDraft(it.caption);
                }}
              >
                {it.caption}
              </p>
            )}

            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <span className="badge">{it.shared ? a.libShared : it.project}</span>
              <span className="small muted">{kb(it.bytes)}</span>
              <button
                className="btn btn-ghost"
                style={{ marginLeft: "auto" }}
                onClick={() => void setShared(it, !it.shared)}
                title={a.libShareHint}
              >
                {it.shared ? a.libUnshare : a.libShare}
              </button>
              <button className="btn btn-ghost" onClick={() => void remove(it)}>
                {a.libDelete}
              </button>
            </div>
          </div>
        ))}
      </div>
    </>
  );
}
