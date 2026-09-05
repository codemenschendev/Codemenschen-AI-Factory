"use client";

import { useSyncExternalStore } from "react";
import Link from "next/link";
import { daysLeft, forget, getServerSnapshot, getSnapshot, subscribe } from "@/lib/history";
import type { Dict, Locale } from "@/lib/i18n";

/**
 * What this browser built before, under the box that builds the next one.
 *
 * The list lives in localStorage, which the server cannot see, so it is read through
 * useSyncExternalStore: the server renders nothing, the browser renders the list, and React knows
 * the two are meant to differ.
 */
export function PrototypeHistory({ locale, d }: { locale: Locale; d: Dict }) {
  const p = d.proto;
  const mine = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  if (mine.length === 0) return null;

  return (
    <section style={{ marginTop: 44, maxWidth: 640 }}>
      <h2 style={{ fontSize: "1.1rem", marginBottom: 4 }}>{p.mine}</h2>
      <p className="est-empty" style={{ marginTop: 0, marginBottom: 16 }}>{p.mineHint}</p>

      <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "grid", gap: 10 }}>
        {mine.map((e) => {
          const left = daysLeft(e);

          return (
            <li
              key={e.id}
              style={{
                display: "flex",
                gap: 12,
                alignItems: "baseline",
                justifyContent: "space-between",
                border: "1px solid var(--border)",
                borderRadius: 12,
                padding: "12px 14px",
              }}
            >
              <div style={{ minWidth: 0 }}>
                <Link href={`/${locale}/p/${e.id}`} style={{ fontWeight: 600 }}>
                  {e.title ?? e.prompt}
                </Link>
                <p className="small muted" style={{ margin: "4px 0 0" }}>
                  {p.kinds[e.kind]}
                  {" · "}
                  {new Date(e.at).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB")}
                  {" · "}
                  {left === 1 ? p.oneDayLeft : p.daysLeft.replace("{n}", String(left))}
                </p>
              </div>
              <button
                type="button"
                className="lang-toggle"
                onClick={() => forget(e.id)}
                style={{ cursor: "pointer", flex: "0 0 auto" }}
              >
                {p.forget}
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
