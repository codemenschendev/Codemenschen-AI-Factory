Photographs. Write the brief INSIDE the element and a real photograph replaces it:

  <div class="photo-wide" data-q="bakery bread basket">what a wide picture would show</div>
  <div class="photo-card" data-q="…">what a card's picture would show</div>
  <span class="photo-thumb" data-q="…">what a small square picture would show</span>

data-q is the search: two to four ENGLISH nouns naming what is in the picture, the way a
stock library files it. "laptop advent wreath", "carpenter workshop", "dentist chair".
No adjectives, no mood, no verbs: those go in the sentence, which is for a photographer.

Each of those holds THE SENTENCE AND NOTHING ELSE, as bare text: no span around it, no
heading, no price, no other element.
The whole element is replaced by the photograph, so anything else inside it disappears.
A card with a picture is the slot FIRST and then the card's own text beside or beneath it,
never the card wrapped in the slot.

Write the brief the way a photographer would be told it, in the visitor's own trade and
place: "Frische Kipferl im Weidenkorb, warmes Morgenlicht". Up to six in the page. Style
them yourself; give each one a size and a shape. The photograph arrives as an <img> inside
the element, so style that too: display block, width and height 100%, object-fit cover,
and it fills whatever shape you drew. If no photograph is found the element keeps whatever
background you gave it, so give it one worth looking at.
