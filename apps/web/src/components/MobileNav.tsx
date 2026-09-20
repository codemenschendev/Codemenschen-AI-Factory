"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";

/**
 * The menu on a narrow screen. The links in the bar are hidden under 1000px, and until now
 * nothing took their place: on a phone the site had no menu at all.
 */
export function MobileNav({ links }: { links: { href: string; label: string }[] }) {
  const path = usePathname();
  // Open is remembered per page, so going somewhere else closes the menu without an effect.
  const [openOn, setOpenOn] = useState<string | null>(null);
  const open = openOn === path;
  const setOpen = (next: boolean) => setOpenOn(next ? path : null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpenOn(null);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  return (
    <div className="nav-burger">
      <button
        type="button"
        className="nav-burger-btn"
        aria-expanded={open}
        aria-controls="nav-panel"
        aria-label="Menu"
        onClick={() => setOpen(!open)}
      >
        <span aria-hidden="true">{open ? "✕" : "☰"}</span>
      </button>
      {open && (
        <nav id="nav-panel" className="nav-panel">
          {links.map((l) => (
            <Link key={l.href} href={l.href} onClick={() => setOpen(false)}>
              {l.label}
            </Link>
          ))}
        </nav>
      )}
    </div>
  );
}
