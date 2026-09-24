/** Line icons for the home page and the prototype form: 24px grid, stroke follows currentColor. */
const ICONS: Record<string, string> = {
  site: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>',
  app: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
  ads: '<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1Z"/><path d="M17 8.5a5 5 0 0 1 0 7M19.5 6a8.5 8.5 0 0 1 0 12"/>',
  email:
    '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
  campaign: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  preview:
    '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
  euro: '<path d="M17.5 6.5A7 7 0 1 0 17.5 17.5M4 10.5h9M4 13.5h9"/>',
  team: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 20a6.5 6.5 0 0 0-2.5-5.1"/>',
  shield:
    '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.6 8 10 4.6-1.4 8-5 8-10v-6Z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  fork: '<path d="M7 2.5v7a2.5 2.5 0 0 0 5 0v-7M9.5 2.5v19M17 2.5c-1.7 1.5-2.5 4-2.5 7v3h2.5v9"/>',
  box: '<path d="m12 2.5 8.5 4.5v10L12 21.5 3.5 17V7Z"/><path d="M3.5 7 12 11.5 20.5 7M12 11.5v10"/>',
  leaf: '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14"/><path d="M5 19c3-4 6-7 10-9"/>',
  calendar:
    '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"/>',
  spark:
    '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.9L12 18.5l-1.8-5.8L4.5 10.8 10.2 9Z"/><path d="M19 3.5v3M17.5 5h3M5 17.5v3M3.5 19h3"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
  pin: '<path d="M12 21.5s-7-6.3-7-11.5a7 7 0 0 1 14 0c0 5.2-7 11.5-7 11.5Z"/><circle cx="12" cy="10" r="2.5"/>',
  bulb: '<path d="M9 18h6M10 21.5h4M12 2.5a6.5 6.5 0 0 0-4 11.6c.6.5 1 1.2 1 2V16h6v-.9c0-.8.4-1.5 1-2A6.5 6.5 0 0 0 12 2.5Z"/>',
  doc: '<path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8Z"/><path d="M14 2.5V8h5.5M8.5 15l2.5 2.5 4.5-5"/>',
  rocket:
    '<path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2-.1-2.8A2.1 2.1 0 0 0 5 15Z"/><path d="m12 15-3-3a22 22 0 0 1 2-4A12.9 12.9 0 0 1 22 2c0 2.7-.8 7.5-6 11a22 22 0 0 1-4 2Z"/><path d="M9 12H4s.6-3 2-4c1.6-1.1 5 0 5 0M12 15v5s3-.6 4-2c1.1-1.6 0-5 0-5"/>',
  store: '<path d="M4 7h16l-1 13H5Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  image:
    '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="9.5" r="1.8"/><path d="m21 16-5.5-5.5L5 20"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  server:
    '<rect x="3" y="3.5" width="18" height="7" rx="2"/><rect x="3" y="13.5" width="18" height="7" rx="2"/><path d="M7 7h.01M7 17h.01"/>',
};

export function Icon({ name, className }: { name: string; className?: string }) {
  return (
    <svg
      className={className ?? "ico"}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      dangerouslySetInnerHTML={{ __html: ICONS[name] ?? "" }}
    />
  );
}
