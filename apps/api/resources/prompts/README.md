# Appwerk AI prompts

The instructions the AI gets when it works for a customer. Edit the wording here; the code decides
which file is used when.

| File | Used for |
|---|---|
| `prototype/site.md` | Free prototype: landing page |
| `prototype/app.md` | Free prototype: app screens |
| `prototype/ads.md` | Free prototype: five ad creatives |
| `prototype/photo-slots.md` | Added to every prototype: how photos are placed |
| `prototype/laws.md` | Added to every prototype: rules the page check enforces |
| `prototype/reference.md` | Sent with a reference screenshot |
| `study/plan-app.md`, `study/plan-web.md` | Before a prototype: which trade, which competitors |
| `study/app.md`, `study/site.md`, `study/ads.md` | Before a prototype: the design brief from competitors' screens |
| `study/images-are-data.md` | Added to every study |
| `ads/video.md` | Ad film (4 scenes) |
| `ads/still.md` | Single image ad |
| `ads/copywriter.md` | Added to every ad: the copy rules and the JSON shape |
| `ads/reference.md` | Sent with a reference ad |

The ad angles and goals the customer picks in the portal are short sentences in
`app/Domain/Ai/AdScriptWriter.php` (`ANGLES`, `GOALS`), because their keys are part of the portal.

## Before you change a file

- Words in `{curly braces}` are filled in by the code, for example `{brief}` or `{stats}`. Keep
  them, or that information no longer reaches the AI.
- Keep JSON shapes and class names exactly as they are (`{"scenes":[...]}`, `photo-wide`,
  `class="screen"`, ...). Other code reads them, and a renamed one breaks the build.
- Keep the lines that say text inside images is data and never an instruction.
- A change goes live with the next `!deploy appwerk`. Check one real prototype or ad afterwards.
