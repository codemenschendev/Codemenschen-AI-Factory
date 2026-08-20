/* Appwerk — per-app illustrations. One flat SVG scene per catalog app.
   Palette + geometry follow the shared art style (see art-concept.js). No text inside SVGs. */
window.ART = window.ART || {};
window.ART.apps = {

  /* PDF document → arrow → web form with fields */
  formpilot: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <rect x="18" y="28" width="72" height="98" rx="8" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
    <rect x="18" y="28" width="72" height="22" rx="8" fill="#EDEBE6"/>
    <rect x="30" y="62" width="48" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="30" y="76" width="40" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="30" y="90" width="46" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="30" y="104" width="32" height="6" rx="3" fill="#EDEBE6"/>
    <path d="M100 77 h26 m-8 -9 9 9 -9 9" fill="none" stroke="#2B5BE3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
    <rect x="140" y="20" width="84" height="114" rx="10" fill="#DDE6FB"/>
    <rect x="152" y="36" width="60" height="14" rx="6" fill="#FFFFFF"/>
    <rect x="152" y="58" width="60" height="14" rx="6" fill="#FFFFFF"/>
    <rect x="152" y="80" width="60" height="14" rx="6" fill="#FFFFFF"/>
    <rect x="152" y="104" width="38" height="16" rx="8" fill="#2B5BE3"/>
    <circle cx="206" cy="112" r="8" fill="#1A7F5A"/>
    <path d="M202 112 l3 3 6 -6" fill="none" stroke="#FFFFFF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
  </svg>`,

  /* open fridge → weekly meal-plan grid */
  mealgrid: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <rect x="22" y="18" width="66" height="120" rx="10" fill="#DDE6FB"/>
    <rect x="30" y="26" width="50" height="46" rx="6" fill="#FFFFFF"/>
    <rect x="30" y="80" width="50" height="50" rx="6" fill="#FFFFFF"/>
    <circle cx="42" cy="44" r="7" fill="#1A7F5A"/>
    <rect x="54" y="38" width="18" height="12" rx="4" fill="#2B5BE3"/>
    <rect x="36" y="58" width="26" height="8" rx="4" fill="#EDEBE6"/>
    <rect x="38" y="92" width="16" height="20" rx="4" fill="#EDEBE6"/>
    <circle cx="66" cy="102" r="8" fill="#2B5BE3"/>
    <path d="M98 76 h24 m-8 -9 9 9 -9 9" fill="none" stroke="#2B5BE3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
    <rect x="134" y="26" width="88" height="104" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
    <rect x="134" y="26" width="88" height="20" rx="10" fill="#2B5BE3"/>
    <g fill="#DDE6FB">
      <rect x="144" y="56" width="20" height="16" rx="4"/><rect x="168" y="56" width="20" height="16" rx="4"/><rect x="192" y="56" width="20" height="16" rx="4"/>
      <rect x="144" y="78" width="20" height="16" rx="4"/><rect x="192" y="78" width="20" height="16" rx="4"/>
      <rect x="144" y="100" width="20" height="16" rx="4"/><rect x="168" y="100" width="20" height="16" rx="4"/>
    </g>
    <rect x="168" y="78" width="20" height="16" rx="4" fill="#1A7F5A"/>
    <rect x="192" y="100" width="20" height="16" rx="4" fill="#2B5BE3"/>
  </svg>`,

  /* phone scanning a barcode on a box, tally checks */
  countbee: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <rect x="26" y="62" width="88" height="70" rx="8" fill="#EDEBE6"/>
    <rect x="26" y="62" width="88" height="18" rx="8" fill="#DDE6FB"/>
    <g fill="#23272F">
      <rect x="46" y="94" width="4" height="26" rx="2"/><rect x="54" y="94" width="2" height="26"/><rect x="60" y="94" width="6" height="26" rx="2"/>
      <rect x="70" y="94" width="3" height="26"/><rect x="77" y="94" width="5" height="26" rx="2"/><rect x="86" y="94" width="2" height="26"/><rect x="92" y="94" width="4" height="26" rx="2"/>
    </g>
    <rect x="40" y="100" width="62" height="4" rx="2" fill="#2B5BE3"/>
    <rect x="142" y="20" width="62" height="116" rx="12" fill="#1E47C2"/>
    <rect x="150" y="32" width="46" height="82" rx="6" fill="#FFFFFF"/>
    <rect x="156" y="40" width="34" height="10" rx="4" fill="#DDE6FB"/>
    <path d="M158 62 l4 4 8 -8" fill="none" stroke="#1A7F5A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M158 78 l4 4 8 -8" fill="none" stroke="#1A7F5A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M158 94 l4 4 8 -8" fill="none" stroke="#1A7F5A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    <rect x="178" y="60" width="12" height="6" rx="3" fill="#EDEBE6"/><rect x="178" y="76" width="12" height="6" rx="3" fill="#EDEBE6"/><rect x="178" y="92" width="12" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="164" y="120" width="18" height="6" rx="3" fill="#DDE6FB"/>
    <path d="M118 70 Q130 62 142 56" fill="none" stroke="#2B5BE3" stroke-width="3" stroke-dasharray="2 6" stroke-linecap="round"/>
  </svg>`,

  /* calendar sheet → outgoing SMS bubble with bell */
  praxo: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <rect x="24" y="26" width="96" height="106" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
    <rect x="24" y="26" width="96" height="26" rx="10" fill="#2B5BE3"/>
    <rect x="42" y="18" width="8" height="18" rx="4" fill="#1E47C2"/>
    <rect x="94" y="18" width="8" height="18" rx="4" fill="#1E47C2"/>
    <g fill="#DDE6FB">
      <rect x="36" y="64" width="18" height="14" rx="4"/><rect x="60" y="64" width="18" height="14" rx="4"/><rect x="84" y="64" width="18" height="14" rx="4"/>
      <rect x="36" y="86" width="18" height="14" rx="4"/><rect x="84" y="86" width="18" height="14" rx="4"/>
      <rect x="36" y="108" width="18" height="14" rx="4"/><rect x="60" y="108" width="18" height="14" rx="4"/>
    </g>
    <rect x="60" y="86" width="18" height="14" rx="4" fill="#1A7F5A"/>
    <path d="M126 74 Q146 66 158 58" fill="none" stroke="#2B5BE3" stroke-width="3" stroke-dasharray="2 6" stroke-linecap="round"/>
    <path d="M156 26 h58 a10 10 0 0 1 10 10 v34 a10 10 0 0 1 -10 10 h-38 l-14 14 v-14 h-6 a10 10 0 0 1 -10 -10 v-34 a10 10 0 0 1 10 -10 z" fill="#DDE6FB"/>
    <path d="M185 40 a9 9 0 0 1 9 9 v6 l4 6 h-26 l4 -6 v-6 a9 9 0 0 1 9 -9 z" fill="#2B5BE3"/>
    <circle cx="185" cy="64" r="3.5" fill="#2B5BE3"/>
    <circle cx="200" cy="38" r="6" fill="#1A7F5A"/>
    <rect x="166" y="106" width="48" height="8" rx="4" fill="#EDEBE6"/>
    <rect x="166" y="120" width="34" height="8" rx="4" fill="#EDEBE6"/>
  </svg>`,

  /* invoice with reminder bell + paid check, subtle scaffold (under construction) */
  rechni: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <g stroke="#EDEBE6" stroke-width="3" stroke-linecap="round">
      <path d="M170 132 h56 M178 132 v-36 M218 132 v-36 M178 96 h40 M178 114 l40 -18 M178 96 l40 18"/>
    </g>
    <rect x="46" y="20" width="92" height="116" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
    <rect x="58" y="34" width="36" height="8" rx="4" fill="#2B5BE3"/>
    <rect x="58" y="52" width="68" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="58" y="64" width="60" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="58" y="76" width="66" height="6" rx="3" fill="#EDEBE6"/>
    <rect x="58" y="94" width="40" height="10" rx="5" fill="#DDE6FB"/>
    <circle cx="122" cy="112" r="14" fill="#1A7F5A"/>
    <path d="M116 112 l4 4 8 -8" fill="none" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="146" cy="38" r="18" fill="#DDE6FB"/>
    <path d="M146 28 a8 8 0 0 1 8 8 v5 l3 5 h-22 l3 -5 v-5 a8 8 0 0 1 8 -8 z" fill="#2B5BE3"/>
    <circle cx="146" cy="49" r="3" fill="#2B5BE3"/>
    <path d="M166 22 q4 -6 10 -8 M170 30 q6 -4 13 -4" fill="none" stroke="#2B5BE3" stroke-width="2.5" stroke-linecap="round"/>
  </svg>`,

  /* shift-plan grid with avatars — muted greys, app is taken */
  shiftly: `<svg viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
    <rect x="34" y="24" width="140" height="112" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
    <rect x="34" y="24" width="140" height="22" rx="10" fill="#EDEBE6"/>
    <g fill="#EDEBE6">
      <rect x="46" y="58" width="26" height="16" rx="4"/><rect x="78" y="58" width="26" height="16" rx="4"/><rect x="110" y="58" width="26" height="16" rx="4"/><rect x="142" y="58" width="20" height="16" rx="4"/>
      <rect x="46" y="82" width="26" height="16" rx="4"/><rect x="110" y="82" width="26" height="16" rx="4"/>
      <rect x="78" y="106" width="26" height="16" rx="4"/><rect x="142" y="106" width="20" height="16" rx="4"/>
    </g>
    <rect x="78" y="82" width="26" height="16" rx="4" fill="#DDE6FB"/>
    <rect x="46" y="106" width="26" height="16" rx="4" fill="#DDE6FB"/>
    <g>
      <circle cx="196" cy="52" r="14" fill="#EDEBE6"/><circle cx="196" cy="47" r="5" fill="#FFFFFF"/><path d="M187 60 a9 9 0 0 1 18 0 z" fill="#FFFFFF"/>
      <circle cx="212" cy="86" r="14" fill="#DDE6FB"/><circle cx="212" cy="81" r="5" fill="#FFFFFF"/><path d="M203 94 a9 9 0 0 1 18 0 z" fill="#FFFFFF"/>
      <circle cx="192" cy="120" r="14" fill="#EDEBE6"/><circle cx="192" cy="115" r="5" fill="#FFFFFF"/><path d="M183 128 a9 9 0 0 1 18 0 z" fill="#FFFFFF"/>
    </g>
  </svg>`
};
