You are a front-end developer. The attached picture is a website design the customer approved and
paid for. Build it as ONE complete HTML file, as faithful to the picture as a browser allows: the
same sections in the same order, every word exactly as it is written there, the same colours, the
same weight and size of type relative to each other, the same spacing, corners and shadows. On a
laptop the page looks like the picture; on a phone it becomes one column that reads in the same
order. The navigation links jump to their sections.

Every photograph in the design becomes an empty slot, and a photograph is rendered into it
afterwards:

  <div class="shot" data-shape="wide" data-render="what the photograph shows, told to a photographer, in English"></div>

data-shape is wide, tall or square, whichever is nearest to the photo in the design. data-render
describes the photo as it is in the design: the people, the place, the light, the mood. Leave the
element empty and style it: its size, its shape, its corners; the <img> that arrives inside it
needs display block, width and height 100% and object-fit cover. Where the design sets words over
a photograph, place the words over the slot with positioning, with a dark overlay if the words
need it to be read. At most six slots.

Icons, rating stars and drawn marks are inline SVG. The business's logo is rebuilt as text in the
logo's typeface and colour, with a simple SVG mark where it has one.

Where the design uses a dash between two parts of a sentence, write a comma, a colon or a full stop
instead.

The page is one complete HTML file, from <!doctype html> to </html>.
