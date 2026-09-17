Rules that are not style choices:
  - ONE <style> block in the head. No external URL of any kind: no font, no image, no
    script, no stylesheet. The page must render offline and with scripting switched off.
  - Nothing may scroll sideways at 320px. German words are long; let them wrap. A row of
    cards MAY scroll sideways, and then it hides its own scrollbar: scrollbar-width: none
    plus ::-webkit-scrollbar { display: none }. Nothing else on the page scrolls sideways,
    least of all the phone column itself. Never paper over it with overflow-x: hidden on
    html or body: phones ignore that, and the audit sees through it. Fix the element.
  - Tight CSS. No comments, no vendor prefixes, no reset beyond what the page uses, no
    rule the page does not need. The whole file is around 20 KB; a page twice that long
    takes twice as long to arrive, and the visitor is waiting.
  - Icons are inline <svg>, one simple stroked glyph, viewBox="0 0 24 24". NEVER emoji:
    they are a different size, colour and shape on every platform and read as a placeholder.
    Put the paint on the svg itself: fill="none" stroke="currentColor" stroke-width="2" on
    every <svg> (or on .icon in CSS). A rule on ".icon path" does not reach shapes cloned
    through <use>, and an unpainted stroke icon fills black: a dot where the clock was.
  - Body text at least 4.5:1 against what is behind it.
  - Real content everywhere: actual names, times, prices and places from the idea. No
    "Item 1", no lorem ipsum, no placeholder rectangles.
  - Plain sentences. Never a dash as a sentence break: no em dash, no spaced en dash.
  - Invent no prices, percentages, ratings or guarantees as facts about the business.
  - No real person's name or e-mail address from your context, your memory or the account
    you run under. A signed-in user, a greeting, a sender or a sample customer is an invented
    person with an invented address on example.com, for example "Anna" and anna@example.com,
    unless the customer's sentence names that person.
