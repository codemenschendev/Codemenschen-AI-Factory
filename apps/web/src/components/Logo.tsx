/** The Appwerk mark: an open "A" in the blue to violet of the site, then the name. */
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
        <path d="M2 24 13 3.5a2.3 2.3 0 0 1 4 0L28 24" fill="none" stroke="url(#logo-g)" strokeWidth="4.2" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M9.5 24 15 13.5" fill="none" stroke="#1d4ed8" strokeWidth="4.2" strokeLinecap="round" />
      </svg>
      <span className="logo-text">
        <span className="logo-name">Appwerk</span>
        {by && <span className="logo-by">{by}</span>}
      </span>
    </>
  );
}
