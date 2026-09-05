# What real app screens do

Distilled from 1268 labelled screens in the reference library. The numbers are what those screens
actually did, not taste. You are writing the CSS, so these are shapes to build, never classes to
use. Follow them unless the idea gives you a reason not to.

## The two things most generated screens get wrong

Real screens are calm: 397 of 1268 were sparse, 780 medium, only 91 dense. Four or five things on
a screen is normal, eight is not. Cut before you add.

The action a screen exists for sits at the bottom, pinned to the screen, not floating in the middle
of the content. 480 screens ended that way.

## The four screens, and what belongs on each

The story is: what the user sees first, what they pick, what they fill in, what they get back.

1. **Home.** 243 of 290 home screens carried a tab bar, 191 a grid of cards, 155 a row of things to
   swipe through. Open with the one thing the user came for, then two or three rows, and let the
   tab bar be the navigation. No pinned action here.
2. **The list they pick from.** 147 of 191 had a bar across the top and 139 a tab bar below, so the
   list sits between two fixed edges. A row carries two lines: what it is, then the detail that
   decides, a time, a price, a place. 75 marked something with a small label: frei, beliebt, neu,
   and only where it is true.
3. **What they fill in.** One field per thing, never more than three, then the action. 13 of 16
   form screens and 9 of 10 checkout screens ended in a pinned action, and checkout was the one
   kind that stayed sparse. A booking screen is a date, a service and a name, not a form.
4. **What they get back.** 145 of 216 detail screens ended in a pinned action and 176 opened with a
   picture. The confirmation lives here: the result, one or two lines of what was agreed, and an
   action only if there is a next step.

## Rules the library is clear about

- A tab bar and a pinned action together appeared on 108 screens out of 1268. Pick one per screen:
  tabs when the screen is a place the user returns to, an action when it has one job.
- Button labels are short: of 470 labelled actions, 335 were one or two words. "Termin buchen",
  "Weiter", "Jetzt zahlen". A sentence on a button reads as a placeholder.
- Onboarding is the airiest screen in the set, 142 of 159 sparse, the one that leans hardest on a
  picture, and the one that skews dark, 98 of 159.
- A screen of numbers uses two or three figures side by side, never a wall of them, and it is the
  one kind that is mostly dark: 37 of 46.
- A confirmation is sparse and pinned, and usually carries one picture and nothing else.
- A conversation is faces and lines, nothing else.
- Light and dark run close to even, 677 to 591. Dark suits numbers, night work, media and premium
  trades; light suits everything local, hands-on and transactional.

## What a screen is made of, and how often the library used it

A screen built only from identical rows is the failure these numbers warn about. Real screens mix.

    a picture                       708
    a tab bar at the bottom         541
    an action pinned to the bottom  480
    small labels on things          449
    a grid of shortcuts             443
    rows in a list                  306
    a row that scrolls sideways     275
    faces in a row                  207
    two or three modes to switch    201
    a search field                  174
    number tiles                    100

A home screen usually opens with a search field or a set of modes and a grid of four shortcuts. A
list you choose from by looking is photographs with prices beside them: a picture on the left, two
lines in the middle, the price on the right, and a heavier edge on the one already chosen.

Real content in every one of them: the visitor's own services, times, prices and places. A screen
full of "Item 1" sells nothing, and the auditor blocks a page that ships one.
