# Layout packs for the prototypes

A pack is a skeleton for one kind of prototype and one or more trades: how many sections, what
sits in the first screen, the order, how dense each one is. The model reads it for structure and
still writes its own CSS, so the colour, the type scale, the radius and the rhythm stay a decision
it makes for that trade. Bones from a designer, skin from the model.

Packs exist because a one-shot page written from nothing plays safe. They are a bet, not a
conclusion, which is why every switch is a runtime setting and the feature records what it did.

## Switching them

Admin panel, the "Layout packs" tile, or `POST /api/admin/layouts`:

    enabled   on or off, off by default
    kinds     which of site, app, ads use a pack
    share     how many builds in a hundred get one; the rest are the control group
    off       slugs held back one by one

Nothing here is an env value. The question a pack answers is whether the pages get better, and
that is answered by turning packs on, looking at what comes out, and turning them off again. A
deploy in between would compare two different weeks instead of two kinds of build.

Every page records what it followed, in `prototypes.qa`:

    "layout": null                                     written from nothing
    "layout": {"slug": "bakery-warm", "source": "lovable"}

That column is the evidence. With `share` at 70 a week of builds holds both groups, so the two can
be compared on the same traffic instead of on memory.

## Filing a pack

`apps/api/resources/layouts/README.md` has the file format and the manifest line. A pack obeys the
same laws the model writes to: one `<style>` block, no external URL, nothing scrolling sideways at
320px, about 20 KB, real words. A manifest line without a file behind it is ignored, so a
half-finished pack cannot reach a build.

## Where the first set comes from

Drawn in Lovable from invented trade briefs, then ported by hand to the laws above. Lovable's
terms give the output to the account that generated it, so the ported files are ours and can ship
in a product. What is kept is the composition; the markup is rewritten, because Lovable writes
React with Tailwind and hosted assets and our page has to render offline from one file.

No customer sentence and no customer data goes into Lovable. Only invented briefs.

`source` on each pack records where it came from, `lovable`, `hand` or `house`, so a bought
skeleton and a hand-written one can be told apart when the numbers are read.
