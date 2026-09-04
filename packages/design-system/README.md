# Codemenschen house style

The design system behind every prototype Appwerk generates, and the one Claude Design should build
new work on. One stylesheet, `house.css`; one typeface, Instrument Sans, embedded in `font.css`.

The numbers are Tailwind's scales with shadcn's semantic naming. The point is not that they are
special: it is that they never change. Pick one palette on `<body>` (`t-slate`, `t-forest`,
`t-amber`, `t-indigo`, `t-rose`) and everything else follows.

## Rules

- Use the classes in `house.css` and no others. No new CSS in a page.
- One palette class on body. The accent is the whole colour decision.
- A hero may be light or dark: `<header class="hero">` or `<header class="hero invert">`.
- Every hero shows something: a `.browser` mock for a website, a `.phone` for an app, a `.fan` of cards.
- Real words everywhere. Services, prices, places, times. No "Item 1", no lorem ipsum.
- Short button labels, one or two words.
- No em dashes in copy. Use a comma, a colon or a full stop.

## App screens

`app-conventions.md` says what real app screens do, counted from 1268 labelled reference screens.
Four screens tell the story: what the user sees first, what they pick, what they fill in, what
they get back. Home ends in `.app-tabs`; the other three end in `.app-cta`.

## Cards in this project

    previews/type            the type scale and text colours
    previews/palette-*       the five palettes, one card each
    previews/actions         buttons, tags, lists, prices
    previews/site-hero       nav and light hero with a browser mock
    previews/site-hero-dark  dark hero with a phone
    previews/site-sections   cards with icons, split, stats, quote, featured price
    previews/site-close      call to action and footer
    previews/app-screens     the four phone screens
    previews/app-dark        stats tiles and tabs on a dark band
    previews/ads             story, square and link formats

Built by `build.py` from the files in `apps/api/resources/design/`. Do not edit `dist/` by hand;
change the stylesheet and rebuild, so Claude Design and the factory never drift apart.
