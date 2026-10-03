/** The Sofabuilt mark in Appmitki's logo layout: a sofa in the same gradient, then the name. */
export function SbLogo({ by }: { by?: string }) {
  return (
    <>
      <svg className="logo-mark" viewBox="0 0 34 28" aria-hidden="true">
        <defs>
          <linearGradient id="sb-l" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stopColor="#38bdf8" />
            <stop offset="1" stopColor="#4f46e5" />
          </linearGradient>
          <linearGradient id="sb-r" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stopColor="#6366f1" />
            <stop offset="1" stopColor="#a855f7" />
          </linearGradient>
        </defs>
        <path d="M7 14V10a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v4" fill="none" stroke="url(#sb-l)" strokeWidth="3.4" strokeLinecap="round" />
        <path d="M2.5 15a3 3 0 0 1 6 0v2.5h17V15a3 3 0 0 1 6 0v6.5a2.5 2.5 0 0 1-2.5 2.5H5a2.5 2.5 0 0 1-2.5-2.5z" fill="url(#sb-r)" />
      </svg>
      <span className="logo-text">
        <span className="logo-name">
          Sofa<span className="logo-grad">built</span>
        </span>
        {by && <span className="logo-by">{by}</span>}
      </span>
    </>
  );
}
