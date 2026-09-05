# Photographs in a prototype

The picture band in an app prototype holds a real photograph. The line the model wrote there is
the photo brief, and the picture comes from the first of these that answers, in this order:

1. **The shared photo library** on the host, under the project key `prototype`. Whatever an earlier
   prototype fetched or bought is already there, so a second bakery in the same week costs nothing.
2. **Pexels**, free, if `PEXELS_API_KEY` is set. Instant, and for a bakery or a salon a real
   photograph beats a generated one: no invented shop signs, no six-fingered hands.
3. **Generation** through the image sidecar, which costs money on Codemenschen's OpenAI account.
   **Off by default.** A prototype is given away by the hundred and a gradient band is a design;
   an invoice for one is not. Set `PROTOTYPE_GENERATE_PHOTOS=true` to allow it.
4. **Nothing**, and the band keeps its accent gradient, which reads as a deliberate design.

## Paid ads

Same chain, one rule on top. A scene that names a person is generated, never borrowed: the Pexels
licence allows commercial use but guarantees no model release, and it asks that the people in a
photo are not made to look as though they endorse the product. A stranger's face in a Meta ad for
somebody's salon is exactly that use.

Places, rooms, food, tools and products carry no such claim, and those are most scenes. The words
that trigger the rule are matched at the start of a word, never mid-word, because German compounds
put "hand" inside "Behandlungsstuhl" and English puts "face" inside "surface". Hands are not on the
list: a pair of hands kneading dough identifies nobody.

The error is deliberately one-sided. A false positive costs one generation; a false negative puts a
stranger's face in a published advertisement.

Photographers used in an ad are recorded on the ad as `spec.photo_credits`, not painted into the
frame: an ad has no room for a credit line, and the operator is the one who has to be able to
answer for a picture.

## What each prototype actually used

    docker exec infra-api-1 php artisan factory:photo-sources --days=7

One row per source. Before this existed an empty credit meant either the shared library or a
generation, two things that differ by the price of a call, and nobody could tell them apart after
the fact.

One photo per prototype, bought after the audit and any repair pass so a repaired page never pays
twice.

## The free key

https://www.pexels.com/api/ , sign in, "Your API Key". Free, instant, no card. 200 requests an
hour and 20 000 a month, which is far more than this uses.

    ssh -t -p 7172 root@65.108.206.249 'f=/var/www/ai-factory/apps/api/.env; read -r -s -p "PEXELS_API_KEY: " v; echo; grep -q "^PEXELS_API_KEY=" $f || echo "PEXELS_API_KEY=" >> $f; sed -i "s|^PEXELS_API_KEY=.*|PEXELS_API_KEY=$v|" $f'

Then recreate the containers, because env_file is read at creation and restart does not reload it:

    cd /var/www/ai-factory && docker compose -f infra/docker-compose.prod.yml up -d --force-recreate api horizon

With no key the source is skipped and everything behaves as it did before.

## Attribution

Pexels asks for a visible credit when their API is used. The photographer and the photo's page
travel back with the bytes, are stored on the prototype, and the share page prints them under the
phone, outside the mockup. A credit belongs to the page, not to the app being mocked up.

## Why generation is still there

An ad has to show the one scene its copy names, and no stock index holds that. Prototypes are
throwaway lead magnets and can take what the world already photographed; paid ads cannot.
