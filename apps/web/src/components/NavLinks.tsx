"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

/** The links of the bar; the page one is on is marked, so the menu says where you are. */
export function NavLinks({ links }: { links: { href: string; label: string }[] }) {
  const path = usePathname();

  return (
    <nav className="nav-links">
      {links.map((l) => (
        <Link key={l.href} href={l.href} aria-current={l.href === path ? "page" : undefined}>
          {l.label}
        </Link>
      ))}
    </nav>
  );
}
