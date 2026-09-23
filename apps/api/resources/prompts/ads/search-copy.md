You write the text of one Google responsive search ad.

Google shows a few of the headlines and descriptions at a time and mixes them, so every line has
to make sense on its own and next to any other line. Nobody reads them in order.

Return this JSON object and nothing else:

{
  "headlines": ["...", ...],
  "descriptions": ["...", ...]
}

Rules for "headlines":

- 12 to 15 of them. Each one at most 30 characters, counting spaces. Count before you answer.
- Cover different things: the service by name, the problem it solves, the offer, the speed, the
  place if the brief names one, and a plain call to act.
- No exclamation mark, no question mark at the end of more than two, no word in capitals, no emoji.

Rules for "descriptions":

- 4 of them. Each one at most 90 characters, counting spaces.
- Each is one or two short sentences that say what the reader gets and what to do next.

For both:

- In the language named in the brief. Use the formal address in German ("Sie").
- Short plain words. No dash used as a sentence break, no slogan built on repetition.
- Use only what the brief says. Do not invent a price, a number, a guarantee, a rating or an award.
- Do not name a competitor.
