# What real app screens do

Distilled from 1268 labelled screens in the reference library. The numbers are what those screens
actually did, not taste. Follow them unless the idea gives you a reason not to.

## The two things most generated screens get wrong

Real screens are calm: 397 of 1268 were sparse, 780 medium, and only 91 dense. Four or five rows on
a screen is normal, eight is not. Cut before you add.

The action a screen exists for sits at the bottom, pinned, not floating in the middle of the
content: 480 screens ended in a sticky action. Use `.app-cta` for it.

## The four screens, and what belongs on each

The story is: what the user sees first, what they pick, what they fill in, what they get back.

1. **Home.** 243 of 290 home screens carried a tab bar, 191 a grid of cards, 155 a row of things to
   swipe through. Open with `.app-hero` naming the one thing the user came for, then two or three
   `.app-row`, and close with `.app-tabs`. No sticky action here: the tab bar is the navigation.
2. **The list they pick from.** 147 of 191 list screens had a bar across the top and 139 a tab bar
   below, so the list sits between two fixed edges. Rows carry two lines: what it is in `b`, the
   detail that decides in `span`, a time, a price, a place. 75 marked something with a small label;
   use `.app-tag` for "frei", "beliebt", "neu", and only where it is true.
3. **What they fill in.** `.app-field` per input, never more than three, then `.app-cta`. 13 of 16
   form screens and 9 of 10 checkout screens ended in that pinned action, and checkout was the one
   place that stayed sparse. A booking screen is a date, a service and a name, not a form.
4. **What they get back.** 145 of 216 detail screens ended in a sticky action and 176 opened with a
   picture. This is where the confirmation lives: `.app-hero` with the result, one or two rows of
   what was agreed, `.app-cta` only if there is a next step.

## Rules the library is clear about

- A tab bar and a sticky action together appeared on 108 screens out of 1268. Pick one per screen:
  tabs when the screen is a place the user returns to, a sticky action when it has one job.
- Button labels are short: of 470 labelled actions, 335 were one or two words. "Termin buchen",
  "Weiter", "Jetzt zahlen". A sentence on a button reads as a placeholder.
- Onboarding is the airiest screen in the set (142 of 159 sparse), the one that leans on a picture
  (134 illustrations, 119 hero images) and the one that skews dark (98 of 159). Here that means
  `.app-hero` big and almost nothing under it.
- A dashboard of numbers uses `.app-stat` tiles (41 of 46) and is the one screen type that is
  mostly dark (37 of 46). Two or three numbers, never a wall of them.
- A confirmation is sparse and pinned: 13 of 18 sparse, 15 of 18 ending in the action, and most of
  them carrying one picture and nothing else.
- A chat screen is avatars and rows, nothing else (26 of 35 avatars, 28 of 35 a bar on top).
- A profile screen is an avatar, then a segmented control or a grid of cards, then rows.
- Light and dark run close to even, 677 to 591. Dark suits numbers, night work, media and premium
  trades; light suits everything local, hands-on and transactional.

## The classes for all of this

    .app-bar        screen title, one line
    .app-hero       coloured band at the top of the body: the result, the greeting, the one number
    .app-row        b for the thing, span for the detail that decides
    .app-tag        small label inside a row: frei, beliebt, neu
    .app-field      one input, labelled in plain words
    .app-btn        an action inside the flow
    .app-cta        the action the screen exists for, pinned to the bottom
    .app-stat       one number with its label, two or three side by side
    .app-tabs       three or four tabs, one with class="on"

Real content in every one of them: the visitor's own services, times, prices and places. A screen
full of "Item 1" sells nothing, and the auditor now blocks a page that ships one.
