/** The Werkprobe mark: an open "W" in the blue to violet of the site, then the name. */
export function Logo({ by }: { by?: string }) {
  return (
    <>
      <svg className="logo-mark" viewBox="0 0 30 26" aria-hidden="true">
        <defs>
          <linearGradient id="logo-g" x1="0" y1="1" x2="1" y2="0">
            <stop offset="0" stopColor="#1d4ed8" />
            <stop offset="1" stopColor="#7c3aed" />
          </linearGradient>
        </defs>
        <path d="M2.5 3.5 8.5 22.5 15 9.5 21.5 22.5 27.5 3.5" fill="none" stroke="url(#logo-g)" strokeWidth="4.2" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span className="logo-text">
        <span className="logo-name">Werkprobe</span>
        {by && <span className="logo-by">{by}</span>}
      </span>
    </>
  );
}
