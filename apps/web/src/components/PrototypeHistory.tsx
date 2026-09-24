"use client";

import { useSyncExternalStore } from "react";
import Link from "next/link";
import { daysLeft, forget, getServerSnapshot, getSnapshot, subscribe } from "@/lib/history";
import type { Dict, Locale } from "@/lib/i18n";
import { Icon } from "./LineIcon";

/**
 * What this browser built before, under the box that builds the next one.
 *
 * The list lives in localStorage, which the server cannot see, so it is read through
 * useSyncExternalStore: the server renders nothing, the browser renders the list, and React knows
 * the two are meant to differ.
 */
/** Until the build names itself, the first line of what was typed stands in. A page-long brief is not a label. */
function label(prompt: string): string {
  const first = prompt.split(/\n/, 1)[0].trim();

  return first.length > 120 ? first.slice(0, 117).trimEnd() + "…" : first;
}

export function PrototypeHistory({ locale, d }: { locale: Locale; d: Dict }) {
  const p = d.proto;
  const mine = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  if (mine.length === 0) return null;

  return (
    <section className="pp-history">
      <h2>{p.mine}</h2>
      <p className="pp-note">{p.mineHint}</p>

      <ul>
        {mine.map((e) => {
          const left = daysLeft(e);

          return (
            <li key={e.id}>
              <span className="pp-history-ico">
                <Icon name={e.kind} />
              </span>
              <div className="pp-history-text">
                <Link href={`/${locale}/p/${e.id}`}>{e.title ?? label(e.prompt)}</Link>
                <p>
                  {p.kinds[e.kind]}
                  {" · "}
                  {new Date(e.at).toLocaleDateString(locale === "de" ? "de-AT" : "en-GB")}
                  {" · "}
                  {left === 1 ? p.oneDayLeft : p.daysLeft.replace("{n}", String(left))}
                </p>
              </div>
              <button type="button" className="pp-link" onClick={() => forget(e.id)}>
                {p.forget}
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
