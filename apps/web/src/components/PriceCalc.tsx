"use client";

import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";
import type { Dict, Locale } from "@/lib/i18n";

type Part = { name: string; eur: number };
type State = { kind: "empty" } | { kind: "thinking"; last: Part[] | null } | { kind: "done"; parts: Part[]; total: number } | { kind: "off" };

const WAIT_MS = 1200;
const MIN_CHARS = 20;

/**
 * The price that follows the idea while it is typed (Patrick, 2026-10-05: "todo app estimate
 * rough: checkboxes 15, saving of items 5, releasing app to store 15"). PrototypeForm sends
 * the text as an "appmitki:idea" event; after a pause in typing the API (RoughEstimate) splits
 * it into priced parts. Only the newest answer is shown.
 */
export function PriceCalc({ d, locale }: { d: Dict; locale: Locale }) {
  const t = d.proto.calc;
  const [state, setState] = useState<State>({ kind: "empty" });
  const seq = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const money = (n: number) =>
    new Intl.NumberFormat(locale === "de" ? "de-AT" : "en-GB", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);

  useEffect(() => {
    const onIdea = (e: Event) => {
      const { text, kind } = (e as CustomEvent<{ text: string; kind: string }>).detail;
      if (timer.current) clearTimeout(timer.current);
      const id = ++seq.current;
      if (kind !== "app" || text.trim().length < MIN_CHARS) {
        setState({ kind: "empty" });
        return;
      }
      setState((s) => ({ kind: "thinking", last: s.kind === "done" ? s.parts : s.kind === "thinking" ? s.last : null }));
      timer.current = setTimeout(() => {
        api<{ parts: Part[]; total: number }>("/estimate/rough", { method: "POST", body: JSON.stringify({ text: text.trim(), locale }) })
          .then((r) => id === seq.current && setState(r.parts.length ? { kind: "done", parts: r.parts, total: r.total } : { kind: "empty" }))
          .catch(() => id === seq.current && setState({ kind: "off" }));
      }, WAIT_MS);
    };
    window.addEventListener("appmitki:idea", onIdea);
    return () => {
      window.removeEventListener("appmitki:idea", onIdea);
      if (timer.current) clearTimeout(timer.current);
    };
  }, [locale]);

  const parts = state.kind === "done" ? state.parts : state.kind === "thinking" ? state.last : null;
  const total = state.kind === "done" ? state.total : parts ? parts.reduce((s, p) => s + p.eur, 0) : 0;

  return (
    <div className="pp-card pp-calc" aria-live="polite">
      <h2>{t.title}</h2>
      {state.kind === "thinking" && (
        <p className="pp-calc-thinking">
          <span className="pp-calc-dots" aria-hidden="true"><i /><i /><i /></span>
          {t.thinking}
        </p>
      )}
      {state.kind === "empty" && <p className="pp-calc-empty">{t.empty}</p>}
      {state.kind === "off" && <p className="pp-calc-empty">{t.off}</p>}
      {parts && parts.length > 0 && (
        <>
          <ul className={`pp-calc-parts${state.kind === "thinking" ? " is-stale" : ""}`}>
            {parts.map((p) => (
              <li key={p.name}>
                <span>{p.name}</span>
                <b>{money(p.eur)}</b>
              </li>
            ))}
          </ul>
          <div className={`pp-calc-sum${state.kind === "thinking" ? " is-stale" : ""}`}>
            <span>{t.total}</span>
            <b className="pp-calc-total">{money(total)}</b>
          </div>
          <p className="pp-calc-note">{t.note}</p>
        </>
      )}
    </div>
  );
}
