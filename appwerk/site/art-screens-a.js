/* Appwerk — App-Store-Mockups Teil A: Logos + Phone-Screens für
   formpilot, mealgrid, countbee. Rein abstrakte UI-Blöcke, kein Text. */

window.ART = window.ART || {};

window.ART.logos = Object.assign(window.ART.logos || {}, {

  formpilot: `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img">
<rect width="96" height="96" rx="22" fill="#2B5BE3"/>
<rect x="26" y="18" width="44" height="58" rx="6" fill="#FFFFFF"/>
<rect x="33" y="28" width="30" height="5" rx="2.5" fill="#2B5BE3"/>
<rect x="33" y="40" width="30" height="5" rx="2.5" fill="#DDE6FB"/>
<rect x="33" y="52" width="20" height="5" rx="2.5" fill="#DDE6FB"/>
<circle cx="64" cy="68" r="14" fill="#1A7F5A"/>
<path d="M57 68 l5 5 l9 -10" stroke="#FFFFFF" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`,

  mealgrid: `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img">
<rect width="96" height="96" rx="22" fill="#1A7F5A"/>
<rect x="20" y="20" width="26" height="26" rx="7" fill="#FFFFFF"/>
<rect x="50" y="20" width="26" height="26" rx="7" fill="#FFFFFF"/>
<rect x="20" y="50" width="26" height="26" rx="7" fill="#FFFFFF"/>
<circle cx="63" cy="63" r="13" fill="#FFFFFF"/>
<circle cx="63" cy="63" r="6" fill="#1A7F5A"/>
</svg>`,

  countbee: `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img">
<rect width="96" height="96" rx="22" fill="#DDE6FB"/>
<rect x="22" y="30" width="5" height="36" rx="2" fill="#1E47C2"/>
<rect x="31" y="30" width="9" height="36" rx="2" fill="#1E47C2"/>
<rect x="44" y="30" width="4" height="36" rx="2" fill="#1E47C2"/>
<rect x="52" y="30" width="7" height="36" rx="2" fill="#1E47C2"/>
<rect x="63" y="30" width="4" height="36" rx="2" fill="#1E47C2"/>
<rect x="71" y="30" width="8" height="36" rx="2" fill="#1E47C2"/>
</svg>`
});

window.ART.screens = Object.assign(window.ART.screens || {}, {

  formpilot: [
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="86" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="72" width="152" height="180" rx="12" fill="#FFFFFF" stroke="#2B5BE3" stroke-width="2.5" stroke-dasharray="8 6"/>
<rect x="78" y="106" width="44" height="56" rx="6" fill="#DDE6FB"/>
<rect x="86" y="118" width="28" height="4" rx="2" fill="#2B5BE3"/>
<rect x="86" y="128" width="28" height="4" rx="2" fill="#2B5BE3"/>
<rect x="86" y="138" width="18" height="4" rx="2" fill="#2B5BE3"/>
<path d="M100 200 v-22 m-9 9 l9 -9 l9 9" stroke="#2B5BE3" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<rect x="40" y="280" width="120" height="38" rx="19" fill="#2B5BE3"/>
<rect x="70" y="295" width="60" height="8" rx="4" fill="#FFFFFF"/>
<rect x="60" y="342" width="80" height="8" rx="4" fill="#EDEBE6"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="70" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="76" width="52" height="7" rx="3.5" fill="#EDEBE6"/>
<rect x="24" y="90" width="152" height="30" rx="8" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="24" y="136" width="66" height="7" rx="3.5" fill="#EDEBE6"/>
<rect x="24" y="150" width="152" height="30" rx="8" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="24" y="196" width="44" height="7" rx="3.5" fill="#EDEBE6"/>
<rect x="24" y="210" width="152" height="30" rx="8" fill="#FFFFFF" stroke="#2B5BE3" stroke-width="2"/>
<rect x="32" y="220" width="70" height="9" rx="4.5" fill="#DDE6FB"/>
<rect x="24" y="256" width="58" height="7" rx="3.5" fill="#EDEBE6"/>
<rect x="24" y="270" width="152" height="56" rx="8" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="40" y="352" width="120" height="38" rx="19" fill="#2B5BE3"/>
<rect x="74" y="367" width="52" height="8" rx="4" fill="#FFFFFF"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="96" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="72" width="152" height="96" rx="10" fill="#DDE6FB"/>
<rect x="40" y="128" width="14" height="28" rx="4" fill="#2B5BE3"/>
<rect x="62" y="112" width="14" height="44" rx="4" fill="#2B5BE3"/>
<rect x="84" y="120" width="14" height="36" rx="4" fill="#1E47C2"/>
<rect x="106" y="96" width="14" height="60" rx="4" fill="#2B5BE3"/>
<rect x="128" y="88" width="14" height="68" rx="4" fill="#1A7F5A"/>
<rect x="24" y="188" width="152" height="40" rx="9" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<circle cx="44" cy="208" r="9" fill="#1A7F5A"/><path d="M40 208 l3 3 l6 -6" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<rect x="62" y="203" width="84" height="9" rx="4.5" fill="#EDEBE6"/>
<rect x="24" y="238" width="152" height="40" rx="9" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<circle cx="44" cy="258" r="9" fill="#1A7F5A"/><path d="M40 258 l3 3 l6 -6" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<rect x="62" y="253" width="64" height="9" rx="4.5" fill="#EDEBE6"/>
<rect x="24" y="288" width="152" height="40" rx="9" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<circle cx="44" cy="308" r="9" fill="#DDE6FB"/>
<rect x="62" y="303" width="94" height="9" rx="4.5" fill="#EDEBE6"/>
</svg>`
  ],

  mealgrid: [
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="90" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="76" width="152" height="34" rx="17" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="36" y="88" width="58" height="9" rx="4.5" fill="#EDEBE6"/>
<rect x="24" y="128" width="56" height="26" rx="13" fill="#DDE6FB"/>
<rect x="86" y="128" width="44" height="26" rx="13" fill="#1A7F5A"/>
<rect x="136" y="128" width="40" height="26" rx="13" fill="#DDE6FB"/>
<rect x="24" y="162" width="40" height="26" rx="13" fill="#1A7F5A"/>
<rect x="70" y="162" width="62" height="26" rx="13" fill="#DDE6FB"/>
<rect x="24" y="196" width="50" height="26" rx="13" fill="#DDE6FB"/>
<circle cx="100" cy="290" r="26" fill="#1A7F5A"/>
<path d="M100 278 v24 M88 290 h24" stroke="#FFFFFF" stroke-width="4" stroke-linecap="round"/>
<rect x="52" y="342" width="96" height="8" rx="4" fill="#EDEBE6"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="78" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="72" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="72" width="66" height="38" rx="7" fill="#1A7F5A"/><rect x="128" y="72" width="48" height="38" rx="7" fill="#DDE6FB"/>
<rect x="24" y="118" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="118" width="48" height="38" rx="7" fill="#DDE6FB"/><rect x="110" y="118" width="66" height="38" rx="7" fill="#2B5BE3"/>
<rect x="24" y="164" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="164" width="120" height="38" rx="7" fill="#DDE6FB"/>
<rect x="24" y="210" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="210" width="58" height="38" rx="7" fill="#1A7F5A"/><rect x="120" y="210" width="56" height="38" rx="7" fill="#DDE6FB"/>
<rect x="24" y="256" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="256" width="70" height="38" rx="7" fill="#2B5BE3"/>
<rect x="24" y="302" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="302" width="120" height="38" rx="7" fill="#DDE6FB"/>
<rect x="24" y="348" width="24" height="38" rx="7" fill="#EDEBE6"/><rect x="56" y="348" width="52" height="38" rx="7" fill="#1A7F5A"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="84" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="74" width="20" height="20" rx="6" fill="#1A7F5A"/><path d="M28 84 l4 4 l8 -8" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><rect x="54" y="79" width="86" height="9" rx="4.5" fill="#EDEBE6"/>
<rect x="24" y="112" width="20" height="20" rx="6" fill="#1A7F5A"/><path d="M28 122 l4 4 l8 -8" stroke="#FFFFFF" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/><rect x="54" y="117" width="64" height="9" rx="4.5" fill="#EDEBE6"/>
<rect x="24" y="150" width="20" height="20" rx="6" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="54" y="155" width="98" height="9" rx="4.5" fill="#23272F"/>
<rect x="24" y="188" width="20" height="20" rx="6" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="54" y="193" width="74" height="9" rx="4.5" fill="#23272F"/>
<rect x="24" y="226" width="20" height="20" rx="6" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="54" y="231" width="90" height="9" rx="4.5" fill="#23272F"/>
<rect x="24" y="286" width="152" height="2.5" rx="1" fill="#EDEBE6"/>
<rect x="24" y="306" width="72" height="9" rx="4.5" fill="#EDEBE6"/><rect x="140" y="306" width="36" height="9" rx="4.5" fill="#1A7F5A"/>
<rect x="40" y="348" width="120" height="38" rx="19" fill="#1A7F5A"/>
<rect x="72" y="363" width="56" height="8" rx="4" fill="#FFFFFF"/>
</svg>`
  ],

  countbee: [
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="74" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="70" width="152" height="210" rx="12" fill="#23272F"/>
<rect x="56" y="140" width="6" height="60" rx="2" fill="#FFFFFF"/><rect x="68" y="140" width="10" height="60" rx="2" fill="#FFFFFF"/><rect x="84" y="140" width="5" height="60" rx="2" fill="#FFFFFF"/><rect x="94" y="140" width="8" height="60" rx="2" fill="#FFFFFF"/><rect x="108" y="140" width="5" height="60" rx="2" fill="#FFFFFF"/><rect x="118" y="140" width="11" height="60" rx="2" fill="#FFFFFF"/><rect x="134" y="140" width="6" height="60" rx="2" fill="#FFFFFF"/>
<path d="M40 100 v-14 h14 M160 86 h-14 M160 86 v14 M40 250 v14 h14 M160 264 h-14 v0 M160 264 v-14" stroke="#2B5BE3" stroke-width="4" fill="none" stroke-linecap="round"/>
<rect x="36" y="168" width="128" height="3.5" rx="1.75" fill="#1A7F5A"/>
<rect x="40" y="300" width="120" height="38" rx="19" fill="#2B5BE3"/>
<rect x="76" y="315" width="48" height="8" rx="4" fill="#FFFFFF"/>
<rect x="56" y="360" width="88" height="8" rx="4" fill="#EDEBE6"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="88" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="74" width="152" height="52" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="34" y="90" width="58" height="9" rx="4.5" fill="#23272F"/><circle cx="118" cy="100" r="11" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="112" y="98.5" width="12" height="3" rx="1.5" fill="#23272F"/><rect x="136" y="90" width="18" height="20" rx="5" fill="#DDE6FB"/><circle cx="167" cy="100" r="11" fill="#2B5BE3"/><path d="M167 94 v12 M161 100 h12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
<rect x="24" y="136" width="152" height="52" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="34" y="152" width="72" height="9" rx="4.5" fill="#23272F"/><circle cx="118" cy="162" r="11" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="112" y="160.5" width="12" height="3" rx="1.5" fill="#23272F"/><rect x="136" y="152" width="18" height="20" rx="5" fill="#DDE6FB"/><circle cx="167" cy="162" r="11" fill="#2B5BE3"/><path d="M167 156 v12 M161 162 h12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
<rect x="24" y="198" width="152" height="52" rx="10" fill="#FFFFFF" stroke="#2B5BE3" stroke-width="2"/>
<rect x="34" y="214" width="50" height="9" rx="4.5" fill="#23272F"/><circle cx="118" cy="224" r="11" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="112" y="222.5" width="12" height="3" rx="1.5" fill="#23272F"/><rect x="136" y="214" width="18" height="20" rx="5" fill="#2B5BE3"/><circle cx="167" cy="224" r="11" fill="#2B5BE3"/><path d="M167 218 v12 M161 224 h12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
<rect x="24" y="260" width="152" height="52" rx="10" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="34" y="276" width="64" height="9" rx="4.5" fill="#23272F"/><circle cx="118" cy="286" r="11" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2.5"/><rect x="112" y="284.5" width="12" height="3" rx="1.5" fill="#23272F"/><rect x="136" y="276" width="18" height="20" rx="5" fill="#DDE6FB"/><circle cx="167" cy="286" r="11" fill="#2B5BE3"/><path d="M167 280 v12 M161 286 h12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"/>
<rect x="40" y="348" width="120" height="38" rx="19" fill="#1A7F5A"/><rect x="74" y="363" width="52" height="8" rx="4" fill="#FFFFFF"/>
</svg>`,
`<svg viewBox="0 0 200 420" xmlns="http://www.w3.org/2000/svg" role="img">
<rect x="4" y="4" width="192" height="412" rx="26" fill="#23272F"/>
<rect x="12" y="12" width="176" height="396" rx="18" fill="#FFFFFF"/>
<rect x="76" y="20" width="48" height="8" rx="4" fill="#23272F"/>
<rect x="24" y="46" width="66" height="10" rx="5" fill="#23272F"/>
<rect x="24" y="72" width="152" height="30" rx="6" fill="#DDE6FB"/>
<rect x="24" y="108" width="152" height="26" rx="4" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="24" y="140" width="152" height="26" rx="4" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="24" y="172" width="152" height="26" rx="4" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="24" y="204" width="152" height="26" rx="4" fill="#FFFFFF" stroke="#EDEBE6" stroke-width="2"/>
<rect x="74" y="72" width="2.5" height="158" fill="#EDEBE6"/>
<rect x="126" y="72" width="2.5" height="158" fill="#EDEBE6"/>
<rect x="32" y="118" width="34" height="7" rx="3.5" fill="#EDEBE6"/><rect x="84" y="118" width="24" height="7" rx="3.5" fill="#EDEBE6"/><rect x="136" y="118" width="28" height="7" rx="3.5" fill="#1A7F5A"/>
<rect x="32" y="150" width="28" height="7" rx="3.5" fill="#EDEBE6"/><rect x="84" y="150" width="30" height="7" rx="3.5" fill="#EDEBE6"/><rect x="136" y="150" width="20" height="7" rx="3.5" fill="#1A7F5A"/>
<rect x="32" y="182" width="36" height="7" rx="3.5" fill="#EDEBE6"/><rect x="84" y="182" width="20" height="7" rx="3.5" fill="#EDEBE6"/><rect x="136" y="182" width="26" height="7" rx="3.5" fill="#1A7F5A"/>
<rect x="32" y="214" width="30" height="7" rx="3.5" fill="#EDEBE6"/><rect x="84" y="214" width="26" height="7" rx="3.5" fill="#EDEBE6"/><rect x="136" y="214" width="22" height="7" rx="3.5" fill="#1A7F5A"/>
<rect x="40" y="330" width="120" height="42" rx="21" fill="#2B5BE3"/>
<path d="M100 340 v16 m-8 -8 l8 8 l8 -8" stroke="#FFFFFF" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`
  ]
});
