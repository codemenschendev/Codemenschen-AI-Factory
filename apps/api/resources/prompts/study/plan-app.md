A customer wants this built:

{brief}

Answer with ONE JSON object and nothing else, no prose, no code fence:
{"industry": one of [{industries}],
 "screens": four of [{types}], in the order a first-time user meets them: what they see
            first, what they pick, what they fill in, what they get back,
 "apps": ["the three best-known apps of this trade WHERE THE CUSTOMER IS, by their store
          names, most used first"],
 "country": "ISO 3166-1 alpha-2 of where the customer's users are, from the brief's
             language and places; Vietnamese means vn, Austrian places mean at"}

The first screen of anything about going somewhere, ordering to an address or finding
what is nearby is "map". Pick "other" only when nothing fits.
