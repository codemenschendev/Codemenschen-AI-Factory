/* Appwerk — App-Store-Mockups Teil B: Logos + Screens für praxo, rechni.
   Kein Text in den SVGs (i18n). Palette: #2B5BE3 #1E47C2 #DDE6FB #1A7F5A #FFFFFF #EDEBE6 #23272F. */
window.ART = window.ART || {};

window.ART.logos = Object.assign(window.ART.logos || {}, {
  praxo: `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="96" height="96" rx="22" fill="#1E47C2"/>
  <path d="M48 22c-10 0-17 8-17 18v10l-5 8h44l-5-8V40c0-10-7-8-17-18z" fill="#FFFFFF"/>
  <path d="M42 62a6 6 0 0 0 12 0z" fill="#FFFFFF"/>
  <circle cx="66" cy="30" r="10" fill="#1A7F5A"/>
  <path d="M61 30l4 4 6-7" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`,
  rechni: `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect x="1" y="1" width="94" height="94" rx="22" fill="#FFFFFF" stroke="#DDE6FB" stroke-width="2"/>
  <rect x="26" y="20" width="36" height="48" rx="6" fill="#DDE6FB"/>
  <rect x="32" y="30" width="24" height="4" rx="2" fill="#2B5BE3"/>
  <rect x="32" y="40" width="18" height="4" rx="2" fill="#2B5BE3"/>
  <rect x="32" y="50" width="21" height="4" rx="2" fill="#2B5BE3"/>
  <circle cx="62" cy="62" r="15" fill="#2B5BE3"/>
  <path d="M55 62l5 5 9-10" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`
});

/* Screens: einheitlicher Phone-Rahmen (rx 26, #23272F), Screen #FFFFFF rx 18, Notch oben. */
window.ART.screens = Object.assign(window.ART.screens || {}, {
  praxo: [
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <rect x="24" y="40" width="90" height="10" rx="5" fill="#DDE6FB"/>
  <g fill="#EDEBE6">
    <rect x="24" y="66" width="32" height="32" rx="8"/><rect x="64" y="66" width="32" height="32" rx="8"/><rect x="104" y="66" width="32" height="32" rx="8"/><rect x="144" y="66" width="32" height="32" rx="8"/>
    <rect x="24" y="106" width="32" height="32" rx="8"/><rect x="104" y="106" width="32" height="32" rx="8"/><rect x="144" y="106" width="32" height="32" rx="8"/>
    <rect x="24" y="146" width="32" height="32" rx="8"/><rect x="64" y="146" width="32" height="32" rx="8"/><rect x="144" y="146" width="32" height="32" rx="8"/>
  </g>
  <rect x="64" y="106" width="32" height="32" rx="8" fill="#2B5BE3"/>
  <rect x="104" y="146" width="32" height="32" rx="8" fill="#2B5BE3"/>
  <rect x="24" y="196" width="152" height="1" fill="#DDE6FB"/>
  <rect x="24" y="212" width="152" height="44" rx="10" fill="#DDE6FB"/>
  <circle cx="46" cy="234" r="12" fill="#2B5BE3"/>
  <path d="M40 234l4 4 8-9" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="66" y="224" width="80" height="6" rx="3" fill="#2B5BE3"/>
  <rect x="66" y="236" width="56" height="6" rx="3" fill="#23272F" opacity="0.25"/>
  <rect x="24" y="272" width="152" height="44" rx="10" fill="#EDEBE6"/>
  <circle cx="46" cy="294" r="12" fill="#1A7F5A"/>
  <path d="M40 294l4 4 8-9" stroke="#FFFFFF" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="66" y="284" width="70" height="6" rx="3" fill="#23272F" opacity="0.4"/>
  <rect x="66" y="296" width="48" height="6" rx="3" fill="#23272F" opacity="0.25"/>
  <rect x="24" y="344" width="152" height="40" rx="12" fill="#2B5BE3"/>
  <rect x="72" y="360" width="56" height="8" rx="4" fill="#FFFFFF"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <rect x="24" y="40" width="110" height="10" rx="5" fill="#DDE6FB"/>
  <rect x="98" y="64" width="4" height="300" rx="2" fill="#DDE6FB"/>
  <circle cx="100" cy="86" r="7" fill="#2B5BE3"/>
  <rect x="24" y="70" width="62" height="34" rx="10" fill="#DDE6FB"/>
  <rect x="32" y="80" width="46" height="5" rx="2.5" fill="#2B5BE3"/>
  <rect x="32" y="90" width="32" height="5" rx="2.5" fill="#2B5BE3" opacity="0.5"/>
  <circle cx="100" cy="170" r="7" fill="#2B5BE3"/>
  <rect x="114" y="152" width="62" height="34" rx="10" fill="#2B5BE3"/>
  <rect x="122" y="162" width="46" height="5" rx="2.5" fill="#FFFFFF"/>
  <rect x="122" y="172" width="30" height="5" rx="2.5" fill="#FFFFFF" opacity="0.6"/>
  <circle cx="100" cy="254" r="7" fill="#1A7F5A"/>
  <rect x="24" y="236" width="62" height="34" rx="10" fill="#1A7F5A"/>
  <path d="M40 253l6 6 12-13" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="100" cy="338" r="7" fill="#EDEBE6"/>
  <rect x="114" y="320" width="62" height="34" rx="10" fill="#EDEBE6"/>
  <rect x="122" y="330" width="44" height="5" rx="2.5" fill="#23272F" opacity="0.3"/>
  <rect x="122" y="340" width="28" height="5" rx="2.5" fill="#23272F" opacity="0.2"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <rect x="24" y="40" width="96" height="10" rx="5" fill="#DDE6FB"/>
  <rect x="24" y="64" width="152" height="200" rx="12" fill="#EDEBE6"/>
  <rect x="40" y="104" width="20" height="140" rx="6" fill="#1E47C2"/>
  <rect x="68" y="128" width="20" height="116" rx="6" fill="#2B5BE3"/>
  <rect x="96" y="156" width="20" height="88" rx="6" fill="#2B5BE3"/>
  <rect x="124" y="184" width="20" height="60" rx="6" fill="#DDE6FB"/>
  <rect x="152" y="206" width="12" height="38" rx="6" fill="#DDE6FB"/>
  <path d="M44 96 L160 190" stroke="#1A7F5A" stroke-width="3" fill="none" stroke-linecap="round"/>
  <circle cx="160" cy="190" r="10" fill="#1A7F5A"/>
  <path d="M155 190l4 4 7-8" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="24" y="284" width="152" height="42" rx="10" fill="#DDE6FB"/>
  <circle cx="46" cy="305" r="10" fill="#1A7F5A"/>
  <path d="M41 305l4 4 7-8" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="66" y="296" width="86" height="6" rx="3" fill="#2B5BE3"/>
  <rect x="66" y="308" width="58" height="6" rx="3" fill="#23272F" opacity="0.25"/>
  <rect x="24" y="342" width="152" height="42" rx="10" fill="#EDEBE6"/>
  <rect x="40" y="354" width="86" height="6" rx="3" fill="#23272F" opacity="0.35"/>
  <rect x="40" y="366" width="52" height="6" rx="3" fill="#23272F" opacity="0.2"/>
</svg>`
  ],
  rechni: [
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <rect x="24" y="40" width="104" height="10" rx="5" fill="#DDE6FB"/>
  <g>
    <rect x="24" y="66" width="152" height="48" rx="10" fill="#EDEBE6"/>
    <circle cx="46" cy="90" r="8" fill="#1A7F5A"/>
    <rect x="64" y="80" width="76" height="6" rx="3" fill="#23272F" opacity="0.4"/>
    <rect x="64" y="94" width="48" height="6" rx="3" fill="#23272F" opacity="0.2"/>
    <rect x="148" y="84" width="20" height="10" rx="5" fill="#1A7F5A"/>
  </g>
  <g>
    <rect x="24" y="122" width="152" height="48" rx="10" fill="#EDEBE6"/>
    <circle cx="46" cy="146" r="8" fill="#2B5BE3"/>
    <rect x="64" y="136" width="66" height="6" rx="3" fill="#23272F" opacity="0.4"/>
    <rect x="64" y="150" width="54" height="6" rx="3" fill="#23272F" opacity="0.2"/>
    <rect x="148" y="140" width="20" height="10" rx="5" fill="#DDE6FB"/>
  </g>
  <g>
    <rect x="24" y="178" width="152" height="48" rx="10" fill="#DDE6FB"/>
    <circle cx="46" cy="202" r="8" fill="#1E47C2"/>
    <rect x="64" y="192" width="80" height="6" rx="3" fill="#2B5BE3"/>
    <rect x="64" y="206" width="44" height="6" rx="3" fill="#2B5BE3" opacity="0.5"/>
    <rect x="148" y="196" width="20" height="10" rx="5" fill="#2B5BE3"/>
  </g>
  <g>
    <rect x="24" y="234" width="152" height="48" rx="10" fill="#EDEBE6"/>
    <circle cx="46" cy="258" r="8" fill="#1A7F5A"/>
    <rect x="64" y="248" width="70" height="6" rx="3" fill="#23272F" opacity="0.4"/>
    <rect x="64" y="262" width="50" height="6" rx="3" fill="#23272F" opacity="0.2"/>
    <rect x="148" y="252" width="20" height="10" rx="5" fill="#1A7F5A"/>
  </g>
  <rect x="24" y="344" width="152" height="40" rx="12" fill="#2B5BE3"/>
  <rect x="72" y="360" width="56" height="8" rx="4" fill="#FFFFFF"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <rect x="24" y="40" width="90" height="10" rx="5" fill="#DDE6FB"/>
  <rect x="24" y="66" width="152" height="70" rx="12" fill="#DDE6FB"/>
  <circle cx="50" cy="101" r="12" fill="#2B5BE3" opacity="0.35"/>
  <rect x="72" y="86" width="84" height="7" rx="3.5" fill="#2B5BE3" opacity="0.6"/>
  <rect x="72" y="102" width="56" height="7" rx="3.5" fill="#2B5BE3" opacity="0.35"/>
  <path d="M100 140v16" stroke="#DDE6FB" stroke-width="3" stroke-linecap="round"/><path d="M95 151l5 6 5-6" stroke="#DDE6FB" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="24" y="162" width="152" height="70" rx="12" fill="#DDE6FB"/>
  <circle cx="50" cy="197" r="12" fill="#2B5BE3" opacity="0.7"/>
  <rect x="72" y="182" width="84" height="7" rx="3.5" fill="#2B5BE3" opacity="0.8"/>
  <rect x="72" y="198" width="62" height="7" rx="3.5" fill="#2B5BE3" opacity="0.5"/>
  <path d="M100 236v16" stroke="#2B5BE3" stroke-width="3" stroke-linecap="round"/><path d="M95 247l5 6 5-6" stroke="#2B5BE3" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="24" y="258" width="152" height="70" rx="12" fill="#2B5BE3"/>
  <circle cx="50" cy="293" r="12" fill="#FFFFFF"/>
  <rect x="72" y="278" width="84" height="7" rx="3.5" fill="#FFFFFF"/>
  <rect x="72" y="294" width="52" height="7" rx="3.5" fill="#FFFFFF" opacity="0.6"/>
  <rect x="24" y="344" width="152" height="40" rx="12" fill="#1E47C2"/>
  <rect x="72" y="360" width="56" height="8" rx="4" fill="#FFFFFF"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
  <rect width="200" height="420" rx="26" fill="#23272F"/>
  <rect x="8" y="8" width="184" height="404" rx="18" fill="#FFFFFF"/>
  <rect x="78" y="14" width="44" height="8" rx="4" fill="#23272F"/>
  <circle cx="100" cy="140" r="52" fill="#1A7F5A"/>
  <path d="M74 140l18 18 34-38" stroke="#FFFFFF" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="52" y="216" width="96" height="10" rx="5" fill="#DDE6FB"/>
  <rect x="68" y="234" width="64" height="8" rx="4" fill="#EDEBE6"/>
  <rect x="24" y="268" width="152" height="52" rx="12" fill="#EDEBE6"/>
  <rect x="38" y="284" width="60" height="20" rx="6" fill="#1A7F5A"/>
  <path d="M110 294h44" stroke="#1A7F5A" stroke-width="4" stroke-linecap="round"/>
  <path d="M146 287l8 7-8 7" stroke="#1A7F5A" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="24" y="332" width="152" height="14" rx="7" fill="#DDE6FB"/>
  <rect x="24" y="332" width="104" height="14" rx="7" fill="#1A7F5A"/>
  <rect x="24" y="364" width="152" height="40" rx="12" fill="#2B5BE3"/>
  <rect x="72" y="380" width="56" height="8" rx="4" fill="#FFFFFF"/>
</svg>`
  ]
});
