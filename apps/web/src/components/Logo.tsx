/**
 * The Appmitki mark (2026-09-29): an open "A" of two rounded strokes, sky blue into indigo and a
 * lighter violet, with a dot at its foot; then the name, "App" in ink and "mitki" in the gradient.
 */
export function LogoMark({ className = "logo-mark" }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 34 28" aria-hidden="true">
      <defs>
        <linearGradient id="logo-l" x1="0.7" y1="0" x2="0.2" y2="1">
          <stop offset="0" stopColor="#38bdf8" />
          <stop offset="1" stopColor="#4f46e5" />
        </linearGradient>
        <linearGradient id="logo-r" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#6366f1" />
          <stop offset="1" stopColor="#a855f7" />
        </linearGradient>
      </defs>
      <path d="M15 5.5 22.5 18.5" fill="none" stroke="url(#logo-r)" strokeWidth="6.4" strokeLinecap="round" />
      <path d="M5.5 22.5 15 5.5" fill="none" stroke="url(#logo-l)" strokeWidth="6.4" strokeLinecap="round" />
      <circle cx="28.5" cy="23.5" r="3" fill="#9333ea" />
    </svg>
  );
}

export function Logo({ by }: { by?: string }) {
  return (
    <>
      <LogoMark />
      <span className="logo-text">
        <span className="logo-name">
          App<span className="logo-grad">mitki</span>
        </span>
        {by && <span className="logo-by">{by}</span>}
      </span>
    </>
  );
}
