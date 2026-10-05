"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { track } from "@/lib/analytics";

const DEPTHS = [25, 50, 75, 100];

/** A landing section's name: its id, or its first class for the ones without (hero, trustbar). */
const sectionName = (s: Element) => s.id || s.classList[0] || "section";

/** The landing section an element sits in, or the page part around it. */
function sectionOf(el: Element): string {
  const s = el.closest("section");
  if (s) return sectionName(s);
  if (el.closest(".cta-bar")) return "cta-bar";
  return el.closest("header, nav, footer")?.tagName.toLowerCase() ?? "page";
}

/**
 * One page_view per route, including client-side navigation, and what the visitor did on that page:
 * how far they scrolled, which sections they saw, what they clicked, which FAQ they opened, whether
 * they tried a calculator, and how long the page was in front of them until they first left it
 * (page_leave). Each signal is sent once per page, so a busy visitor stays a handful of rows.
 * Same rules as the page_view: no cookies, no storage, nothing typed is ever sent. Renders nothing.
 */
export function PageViews() {
  const pathname = usePathname();
  useEffect(() => {
    // The sign-in handoff carries the token in the hash; the path alone is sent, never the hash.
    if (pathname.includes("/admin")) return;
    track("page_view");

    const path = pathname;
    const sent = new Set<string>();
    const once = (key: string, name: string, props: Record<string, string | number>) => {
      if (sent.has(key)) return;
      sent.add(key);
      track(name, props, path);
    };
    const seen = new Set<string>();
    let maxScroll = 0;
    let visibleMs = 0;
    let visibleSince = document.visibilityState === "visible" ? Date.now() : 0;
    let left = false;

    const onScroll = () => {
      const doc = document.documentElement;
      const pct = Math.min(100, Math.round(((window.scrollY + window.innerHeight) / Math.max(1, doc.scrollHeight)) * 100));
      maxScroll = Math.max(maxScroll, pct);
      for (const d of DEPTHS) if (pct >= d - 2) once(`scroll-${d}`, "scroll_depth", { pct: d });
    };

    const onClick = (e: MouseEvent) => {
      const el = (e.target as Element | null)?.closest?.("a, button");
      if (!el) return;
      let label = (el.getAttribute("data-cta") ?? el.textContent ?? el.getAttribute("aria-label") ?? "").replace(/\s+/g, " ").trim().slice(0, 60);
      // A signed-in visitor's menu may show their e-mail address: never send it.
      if (label.includes("@")) label = "(account)";
      const href = (el.getAttribute("href") ?? "").split("#token")[0].slice(0, 100);
      once(`click-${label}-${href}`, "ui_click", { label, href, section: sectionOf(el) });
    };

    const onToggle = (e: Event) => {
      const el = e.target as HTMLElement;
      if (el.tagName !== "DETAILS" || !(el as HTMLDetailsElement).open) return;
      const q = (el.querySelector("summary")?.textContent ?? "").replace(/\s+/g, " ").trim().slice(0, 80);
      once(`faq-${q}`, "faq_open", { q, section: sectionOf(el) });
    };

    const onInput = (e: Event) => {
      // Only a landing section's tools (the payback calculator); the wizard and forms have their own events.
      const section = (e.target as Element | null)?.closest?.("section[id]")?.id;
      if (section) once(`tool-${section}`, "tool_use", { section });
    };

    const io =
      "IntersectionObserver" in window
        ? new IntersectionObserver(
            (entries) => {
              for (const en of entries) {
                const id = sectionName(en.target);
                if (en.isIntersecting && !seen.has(id)) {
                  seen.add(id);
                  once(`section-${id}`, "section_view", { section: id });
                }
              }
            },
            { threshold: 0.35 },
          )
        : null;
    document.querySelectorAll("section").forEach((s) => io?.observe(s));

    const leave = () => {
      if (left) return;
      left = true;
      onScroll(); // where they were when they left, also when no scroll event came
      if (visibleSince) visibleMs += Date.now() - visibleSince;
      track("page_leave", { seconds: Math.round(visibleMs / 1000), max_scroll: maxScroll, sections: seen.size }, path);
    };
    // A phone browser (and the Facebook and Instagram in-app browser) often never fires pagehide:
    // the page going to the background is the last thing we hear.
    const onVisibility = () => {
      if (document.visibilityState === "hidden") leave();
      else if (!visibleSince) visibleSince = Date.now(); // opened in a background tab
    };

    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    document.addEventListener("click", onClick, true);
    document.addEventListener("toggle", onToggle, true);
    document.addEventListener("input", onInput, true);
    document.addEventListener("visibilitychange", onVisibility);
    window.addEventListener("pagehide", leave);
    return () => {
      leave();
      io?.disconnect();
      window.removeEventListener("scroll", onScroll);
      document.removeEventListener("click", onClick, true);
      document.removeEventListener("toggle", onToggle, true);
      document.removeEventListener("input", onInput, true);
      document.removeEventListener("visibilitychange", onVisibility);
      window.removeEventListener("pagehide", leave);
    };
  }, [pathname]);
  return null;
}
