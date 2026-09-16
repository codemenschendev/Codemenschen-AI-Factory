# Layout packs

A pack is one skeleton for one kind (`site`, `app`, `ads`) and one or more trades. It is the
bones of a page: how many sections, what sits in the first screen, how the sections are ordered,
how dense they are. The model still picks the colour, the type scale, the radius and the rhythm.
Bones from a designer, skin from the model.

The switch in the admin panel came first, so a pack can be put in and taken out again without a
deploy, which is the only way to find out whether packs make the pages better. The first two packs
are `bakery-warm` and `physio-calm`, both for `site`.

## Adding one

1. `<slug>.html` in this directory, a complete file that obeys the same laws the model writes to:
   one `<style>` block, no external URL of any kind, nothing scrolling sideways at 320px, around
   20 KB. Real words, no lorem ipsum. Check it with `apps/api/tools/qa-page.cjs` before filing it.
2. A line in `packs.json`:

       {"slug": "bakery-warm", "kind": "site", "industries": ["Bäckerei", "Konditorei", "bakery"],
        "source": "lovable", "note": "opening screen is one photograph, three sections, order form last"}

   `industries` are matched against the customer's own sentence, case-insensitively and as
   substrings, so write the words a customer would use, in German and in English, and none so
   broad that another trade contains it ("Praxis" is also a dentist). A sentence that matches no
   pack gets no pack.

   `keeps` is what a page built on the pack must keep: a minimum number of `sections` and a list
   of `devices` from `LayoutFit::DEVICES`. A page that drops one goes to the repair pass.

   `source` is `lovable`, `hand` or `house`. It is recorded so a later comparison can tell a
   bought skeleton from a hand-written one.
3. A manifest line with no file behind it is ignored, so a half-finished pack cannot reach a build.

## Where the first set came from

Drawn in Lovable, then ported by hand to the laws above. Lovable's terms give the output to the
account that generated it, which is why the files can live here and ship in a product. Lovable
writes React with Tailwind and hosted assets, so the porting is real work and not a copy: what is
kept is the composition, never the markup.

Only invented trade briefs go into Lovable. No customer sentence, no customer data.
