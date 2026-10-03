/** The Sofabuilt mark: a low sofa drawn in two strokes, then the name. */
export function SbLogo() {
  return (
    <span className="sb-logo">
      <svg viewBox="0 0 36 24" aria-hidden="true" className="sb-logo-mark">
        <path d="M6 13V9a4 4 0 0 1 4-4h16a4 4 0 0 1 4 4v4" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        <path d="M3 13.5a3 3 0 0 1 6 0V16h18v-2.5a3 3 0 0 1 6 0V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="var(--sb-accent)" />
      </svg>
      <span className="sb-logo-name">
        Sofa<b>built</b>
      </span>
    </span>
  );
}
