"use client";

import { useEffect } from "react";

/**
 * Landing-page motion, ported from appwerk/site/app.js: a once-per-element
 * rise-in, and the sticky CTA bar that appears below the hero and steps aside
 * for the final CTA section.
 *
 * Elements start at opacity 0, so nothing may stay hidden by accident. The
 * appwerk original used an IntersectionObserver; a plain rect check on scroll
 * is just as cheap here (the set shrinks as elements reveal) and cannot leave
 * content invisible when the page jumps to a deep anchor such as /en#pricing
 * before the observer has seen anything. Without JS the <noscript> block in
 * the page keeps everything visible.
 */
export function LandingMotion() {
  useEffect(() => {
    const revealVisible = () => {
      document.querySelectorAll<HTMLElement>(".lp .reveal:not(.in)").forEach((el) => {
        const r = el.getBoundingClientRect();
        const margin = Math.min(60, r.height * 0.12);
        if (r.top < window.innerHeight - margin && r.bottom > 0) el.classList.add("in");
      });
    };

    const bar = document.getElementById("ctaBar");
    const final = document.querySelector(".lp .section-final");
    const toggleBar = () => {
      if (!bar || !final) return;
      const nearEnd = final.getBoundingClientRect().top < window.innerHeight;
      bar.classList.toggle("show", window.scrollY > 550 && !nearEnd);
    };

    const update = () => {
      revealVisible();
      toggleBar();
    };

    update();
    // The browser jumps to a #hash after hydration; catch that scroll position
    // even if the jump produces no scroll event we hear.
    const late = window.setTimeout(update, 250);
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update, { passive: true });
    window.addEventListener("load", update);

    return () => {
      window.clearTimeout(late);
      window.removeEventListener("scroll", update);
      window.removeEventListener("resize", update);
      window.removeEventListener("load", update);
    };
  }, []);

  return null;
}
