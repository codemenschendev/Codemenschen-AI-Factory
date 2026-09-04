"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

interface RefItem {
  id: string;
  width: number;
  height: number;
  bytes: number;
  category: string | null;
  grade: string | null;
  source: string | null;
  screen_type: string | null;
  industry: string | null;
  patterns: string[];
  primary_action: string | null;
  density: string | null;
  scheme: string | null;
  accent: string | null;
  notes: string | null;
  /** Signed and good for an hour, because an <img> tag carries no Authorization header. */
  url: string;
}

type Facets = Record<string, Record<string, number>>;

interface RefPage {
  available: boolean;
  total: number;
  labelled: number;
  facets: Facets;
  matched?: number;
  page?: number;
  pages?: number;
  items: RefItem[];
}

type FilterKey = "screen_type" | "industry" | "pattern" | "scheme" | "grade" | "labelled";

const EMPTY: Record<FilterKey, string> = {
  screen_type: "",
  industry: "",
  pattern: "",
  scheme: "",
  grade: "",
  labelled: "",
};

/** The vocabulary is machine tokens on purpose; a human still wants to read them. */
const pretty = (v: string) => v.replace(/_/g, " ");

/**
 * The reference library, as the operator sees it.
 *
 * This screen answers one question: does the rule I wrote into app-conventions.md actually hold in
 * the pictures it came from. So the filters are the vocabulary the labeller used, and every count
 * next to a filter is the same count the conventions were distilled from. Nothing here is editable.
 */
export function ReferencePanel({ token, d }: { token: string; locale: Locale; d: Dict }) {
  const a = d.admin;
  const [data, setData] = useState<RefPage | null>(null);
  const [filters, setFilters] = useState<Record<FilterKey, string>>(EMPTY);
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [busy, setBusy] = useState(false);

  const load = useCallback(
    async (f: Record<FilterKey, string>, query: string, p: number) => {
      const params = new URLSearchParams();
      for (const [k, v] of Object.entries(f)) if (v) params.set(k, v);
      if (query.trim()) params.set("q", query.trim());
      if (p > 1) params.set("page", String(p));

      setBusy(true);
      try {
        setData(await api<RefPage>(`/admin/design-library?${params}`, { token }));
      } finally {
        setBusy(false);
      }
    },
    [token],
  );

  useEffect(() => {
    void load(filters, q, page);
    // The search box reloads on Enter, not on every keystroke; filters and paging do it here.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, page]);

  function setFilter(key: FilterKey, value: string) {
    setPage(1);
    setFilters((f) => ({ ...f, [key]: value }));
  }

  const select = (key: FilterKey, label: string, options: Record<string, number> | undefined) => (
    <label className="small" style={{ display: "grid", gap: 4 }}>
      <span className="muted">{label}</span>
      <select value={filters[key]} onChange={(e) => setFilter(key, e.target.value)}>
        <option value="">{a.refAll}</option>
        {Object.entries(options ?? {}).map(([v, n]) => (
          <option key={v} value={v}>
            {pretty(v)} ({n})
          </option>
        ))}
      </select>
    </label>
  );

  if (data && !data.available) return <p className="est-empty">{a.refUnavailable}</p>;

  return (
    <>
      <p className="note">{a.refNote}</p>

      <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "end", marginBottom: 16 }}>
        {select("screen_type", a.refScreenType, data?.facets.screen_type)}
        {select("industry", a.refIndustry, data?.facets.industry)}
        {select("pattern", a.refPattern, data?.facets.pattern)}
        {select("grade", a.refGrade, data?.facets.grade)}
        {select("scheme", a.refScheme, data?.facets.scheme)}

        <label className="small" style={{ display: "grid", gap: 4 }}>
          <span className="muted">{a.refLabelState}</span>
          <select value={filters.labelled} onChange={(e) => setFilter("labelled", e.target.value)}>
            <option value="">{a.refAll}</option>
            <option value="yes">{a.refYes}</option>
            <option value="no">{a.refNo}</option>
          </select>
        </label>

        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              setPage(1);
              void load(filters, q, 1);
            }
          }}
          placeholder={a.refSearchHint}
          style={{ minWidth: 220 }}
        />
        <button
          className="btn btn-ghost"
          onClick={() => {
            setQ("");
            setPage(1);
            setFilters(EMPTY);
          }}
        >
          {a.refReset}
        </button>
      </div>

      {data && (
        <p className="small muted" style={{ marginBottom: 12 }}>
          {a.refCount
            .replace("{matched}", String(data.matched ?? 0))
            .replace("{total}", String(data.total))
            .replace("{labelled}", String(data.labelled))}
        </p>
      )}

      {!busy && data && data.items.length === 0 && <p className="muted">{a.empty}</p>}

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(auto-fill, minmax(190px, 1fr))",
          gap: 16,
          marginBottom: 20,
        }}
      >
        {data?.items.map((it) => (
          <div key={it.id} className="tbl-wrap" style={{ padding: 10 }}>
            <a href={it.url} target="_blank" rel="noopener" title={a.refOpen}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={it.url}
                alt={it.notes ?? it.screen_type ?? it.id}
                loading="lazy"
                style={{
                  width: "100%",
                  aspectRatio: "9 / 19",
                  objectFit: "cover",
                  objectPosition: "top",
                  borderRadius: 6,
                  background: "#0b1220",
                }}
              />
            </a>

            <div style={{ display: "flex", gap: 6, flexWrap: "wrap", margin: "8px 0 6px" }}>
              {it.screen_type && <span className="badge">{pretty(it.screen_type)}</span>}
              {it.industry && <span className="badge">{pretty(it.industry)}</span>}
              {it.density && <span className="badge">{it.density}</span>}
              {it.scheme && <span className="badge">{it.scheme}</span>}
            </div>

            {it.notes && (
              <p className="small" style={{ margin: "0 0 6px" }}>
                {it.notes}
              </p>
            )}
            {it.primary_action && (
              <p className="small muted" style={{ margin: "0 0 6px" }}>
                &ldquo;{it.primary_action}&rdquo;
              </p>
            )}
            {it.patterns.length > 0 && (
              <p className="small muted" style={{ margin: "0 0 6px" }}>
                {it.patterns.map(pretty).join(", ")}
              </p>
            )}
            <p className="small muted" style={{ margin: 0 }}>
              {it.grade} · {it.width}×{it.height} · {it.source}
            </p>
          </div>
        ))}
      </div>

      {data && (data.pages ?? 1) > 1 && (
        <div style={{ display: "flex", gap: 12, alignItems: "center", marginBottom: 24 }}>
          <button className="btn btn-ghost" disabled={busy || page <= 1} onClick={() => setPage((p) => p - 1)}>
            {a.refPrev}
          </button>
          <span className="small muted">
            {a.refPage.replace("{page}", String(data.page ?? 1)).replace("{pages}", String(data.pages ?? 1))}
          </span>
          <button
            className="btn btn-ghost"
            disabled={busy || page >= (data.pages ?? 1)}
            onClick={() => setPage((p) => p + 1)}
          >
            {a.refNext}
          </button>
        </div>
      )}
    </>
  );
}
