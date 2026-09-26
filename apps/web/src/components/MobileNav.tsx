"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";

/**
 * The menu on a narrow screen: the links of the bar, and the start button, which the bar has no
 * room for on a phone. Closes on Escape, on a click outside and when a link is followed.
 */
export function MobileNav({ links, cta }: { links: { href: string; label: string }[]; cta: { href: string; label: string } }) {
  const path = usePathname();
  // Open is remembered per page, so going somewhere else closes the menu without an effect.
  const [openOn, setOpenOn] = useState<string | null>(null);
  const open = openOn === path;
  const setOpen = (next: boolean) => setOpenOn(next ? path : null);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpenOn(null);
    const onClick = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpenOn(null);
    };
    window.addEventListener("keydown", onKey);
    document.addEventListener("click", onClick);
    return () => {
      window.removeEventListener("keydown", onKey);
      document.removeEventListener("click", onClick);
    };
  }, []);

  return (
    <div className="nav-burger" ref={box}>
      <button
        type="button"
        className="nav-burger-btn"
        aria-expanded={open}
        aria-controls="nav-panel"
        aria-label="Menu"
        onClick={() => setOpen(!open)}
      >
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
          {open ? <path d="M6 6l12 12M18 6 6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
        </svg>
      </button>
      {open && (
        <nav id="nav-panel" className="nav-panel">
          {links.map((l) => (
            <Link key={l.href} href={l.href} aria-current={l.href === path ? "page" : undefined} onClick={() => setOpen(false)}>
              {l.label}
            </Link>
          ))}
          <Link className="btn btn-primary nav-panel-cta" href={cta.href} onClick={() => setOpen(false)}>
            {cta.label}
          </Link>
        </nav>
      )}
    </div>
  );
}
