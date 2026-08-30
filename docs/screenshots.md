# The eight screenshots — what to photograph, and where they go

`readme.txt` carries eight numbered captions under `== Screenshots ==`. WordPress.org pairs
each caption with an image **by number, in order**. This file says which screen each number
is, what state that screen has to be in before the shutter goes, and which caption the shot
has to answer.

Nothing in this file changes the plugin. It is a checklist for the merchant — and the two
sections after the eight shots carry the rest of what submission needs: validating
`readme.txt` in a browser, and the manual WordPress.org steps no prompt performs.

---

## ⚠ SCREENSHOTS ARE NOT IN THE PLUGIN ZIP

They never have been and they must not be added to it. The release archive
(`dist/extonify-custom-emails-per-product-1.0.0.zip`) is the plugin; the screenshots are
directory metadata and live somewhere else entirely.

They belong in an `/assets/` directory at the **root of the WordPress.org SVN repository** —
a sibling of `trunk/` and `tags/`, *not* a directory inside `trunk/`:

```
https://plugins.svn.wordpress.org/extonify-custom-emails-per-product/
├── assets/          ← the eight screenshots go HERE
│   ├── screenshot-1.png
│   ├── screenshot-2.png
│   ├── screenshot-3.png
│   ├── screenshot-4.png
│   ├── screenshot-5.png
│   ├── screenshot-6.png
│   ├── screenshot-7.png
│   └── screenshot-8.png
├── tags/
│   └── 1.0.0/       ← the archive's contents
└── trunk/           ← the archive's contents
```

Putting them under `trunk/assets/` ships them to every installing site and still leaves the
directory page with no images. `/assets/` at the root is never downloaded by a store.

The filenames are fixed: `screenshot-1.png` … `screenshot-8.png`, lowercase, hyphenated,
numbered without padding. `.jpg` is accepted in place of `.png` but do not mix a number
across both extensions. **The number is the only thing that binds an image to its caption** —
if screenshot-6 and screenshot-7 are swapped, the directory page will confidently label each
with the other's caption and nothing will report an error.

---

## Before photographing anything

**Build the store the shots are taken from.** Use a scratch install with realistic sample
data, not the production store — every one of these screens can show a real customer's name,
email address and order total.

| requirement | why |
|---|---|
| **Browser width 1200–1600px** | The rule editor's content section is a two-column grid **only at a viewport of 961px and wider** (`assets/admin.css`, `@media screen and (min-width: 961px)`). Below that the placeholder reference stacks underneath the fields and caption 3's *"beside them"* becomes false. 1200–1600px gives the fields room without shrinking the reference panel to its 240px floor. |
| **Standard admin colour scheme** | The default scheme is what a reviewer expects and what the rest of the directory looks like. |
| **Admin menu unfolded** | Folding only widens the content area; nothing here is measured against it, but an unfolded menu shows *WooCommerce → Product Emails* in place, which is the navigation the Installation section describes. |
| **Realistic sample data** | Real-looking product names, order numbers and email subjects. **No Lorem Ipsum** — a reviewer reads these images as evidence the plugin works, and placeholder Latin reads as an unfinished screenshot. |
| **No personal or customer data** | No real names, real email addresses, real phone numbers, real postal addresses or real order totals. Use invented customers on the scratch store. This applies to the browser too: no bookmarks bar, no other tabs, no profile avatar, no notification badges. |
| **Crop to the content area** | Include the admin menu where it makes the screen recognisable (1, 6). Exclude the browser chrome, the OS taskbar and the desktop. |
| **One screen per image** | Do not compose two screens side by side. The caption describes one screen. |

**Consistency across all eight:** same store, same theme, same customer, same products, same
window size. The eight are read as a sequence; a different store in shot 5 reads as a
different plugin.

---

## The eight

### screenshot-1.png — the rules list

> **Caption 1.** *The rules list, showing each rule's trigger, targeting, delivery mode and status.*

**Screen:** `wp-admin/admin.php?page=extonify-wcep-rules` — reached at **WooCommerce → Product
Emails**.

**State it must show:**

- **At least four rules**, so the columns have something to distinguish. Give them different
  triggers, different delivery modes and different targeting; make at least one **Inactive**
  so the Status column shows both values.
- All eight columns populated and readable: **Name, Status, Trigger, Targets, Mode, Delay,
  Emails, Priority**. The caption names four of them — Trigger, Targets, Mode and Status — and
  those four must be legible without zooming.
- The **tab strip** (*Rules* | *Delivery History*) visible with *Rules* active. This is the
  navigation Part F introduced and it is how a merchant reaches shot 6.
- The **Add Email Rule** button visible beside the *Email Rules* heading.
- No admin notice sitting above the table. Reload the screen clean rather than arriving from
  a save.

**Answers:** that the plugin has a list, that a rule is a named thing with a state, and that
the four properties the caption names are visible at a glance.

---

### screenshot-2.png — the rule editor, trigger and targeting

> **Caption 2.** *The rule editor — choosing the trigger and targeting the rule by product, variation, category, tag or type, including exclusions.*

**Screen:** `wp-admin/admin.php?page=extonify-wcep-rules&action=edit&rule=<id>` — an
**existing, populated rule**, not a blank *Add Email Rule* form. A form full of empty selects
demonstrates nothing.

**State it must show:**

- The **When it sends** section with a trigger chosen. Prefer **Order moves between two
  statuses**, because it reveals both status selects and shows the trigger is more than a
  single dropdown.
- The **Which products** section carrying **both an include and an exclude**, and across **more
  than one kind** — for example include a *category* and a *product*, exclude a *variation* or
  a *product type*. The caption promises five targeting kinds and exclusions; a shot with one
  include and nothing excluded contradicts it.
- The chosen targets rendered as their readable labels (real product and category names from
  the sample store), not as bare IDs.
- The **match-all** control visible in its section.

**Scroll position:** both sections in one frame. They are adjacent, so scroll so that *When it
sends* starts near the top of the content area.

**Answers:** that targeting is granular and subtractive, not a single product picker.

---

### screenshot-3.png — the rule editor, the message and the placeholder reference

> **Caption 3.** *The rule editor — the message subject, heading and content, with the placeholder reference beside them.*

**Screen:** the same rule as shot 2, scrolled to the **What it says** section.

**State it must show:**

- **Two columns.** Fields left, the **Placeholders** reference right. If they are stacked, the
  window is under 961px — widen it and retake. This is the single most width-sensitive shot of
  the eight, and the caption says *beside them*.
- **Subject** and **Heading** both filled in, and **at least one containing a placeholder** so
  the reference has a visible purpose — e.g. `Your {product_name} order, {customer_first_name}`.
- The **content editor with real body text**, several lines long, containing two or three
  placeholders. Not one line, and not empty.
- The **placeholder reference expanded enough to show grouped tokens with their descriptions** —
  the *Customer*, *Order* and *Matched products* groups at minimum. The panel scrolls
  independently; position it at the top of its own list.

**Answers:** that the message is written here, that placeholders exist, and that the reference
is beside the field you are typing into rather than on another screen.

---

### screenshot-4.png — the rule editor, delivery mode, delay and consolidation

> **Caption 4.** *The rule editor — delivery mode, delay and the choice between one message per order and one per matched product.*

**Screen:** the same rule, scrolled to the **How it is delivered** section.

**State it must show:**

- **Delivery mode set to *Send as a separate email*.** In *Insert* mode the delay and
  consolidation controls are disabled, and a shot of three greyed-out controls is the exact
  opposite of what the caption claims. Separate mode is the one that shows all three live.
- A **non-zero delay** with a unit that is not the default — *2 days* or *4 hours* reads as a
  real setting; *0 seconds* reads as an unused field.
- The **consolidation control** showing its two options, so *One email for the whole order* and
  *One email per matched product* are both readable. The caption names both.
- If the section can be framed to also show the *Insert into a WooCommerce email* option in the
  mode dropdown, better — but not at the cost of the three controls above.

**Answers:** that timing and fan-out are per-rule decisions, and what the two consolidation
choices actually are.

---

### screenshot-5.png — the preview screen

> **Caption 5.** *The preview screen, showing what a rule would send for a real order.*

**Screen:** `wp-admin/admin.php?page=extonify-wcep-rules&action=preview&rule=<id>` — reached
by the **Preview** row action on the rules list.

**State it must show:**

- A **rendered HTML preview inside the store's WooCommerce email template** — header, footer,
  the store's colours. The whole point of the screen is that the message looks like the
  store's other emails, so the frame must include enough of the template to show that.
- The fact rows above it populated: **Order**, **Delivered as**, and the **Subject** with its
  placeholders resolved to real values.
- **A rule and order that actually match.** If the rule matches nothing on the chosen order the
  screen shows a warning saying so and every product placeholder renders empty — a true screen,
  but not the one this caption describes.
- Preferably a rule set to **one email per matched product** on an order with several matched
  products, so the **Messages** row states how many emails would be sent and names the shown one
  as representative. That is the behaviour the readme describes and it is only visible here.
- Include the **Send a test email** heading at the bottom of the frame if it fits. It is part of
  this screen and shows the preview is not the end of the road.

**Do not** capture the plain-text pane instead of the HTML one. Both are on the screen; the
HTML one is what the caption implies.

---

### screenshot-6.png — the delivery history screen

> **Caption 6.** *The delivery history screen, listing each delivery with its rule, order and status, and every attempt with its recipient, subject and outcome.*

**Screen:** `wp-admin/admin.php?page=extonify-wcep-history` — reached by the **Delivery
History** tab on the Product Emails screen.

**State it must show:**

- **Several deliveries across at least two different rules and two different orders**, with
  **more than one outcome** among them — a *Sent*, and at least one that is not (*Failed*,
  *Cancelled*, *Skipped* or *Scheduled*). A table of six identical *Sent* rows demonstrates
  nothing the caption claims.
- The **Attempts** column legible. This is where the caption's *recipient, subject and outcome*
  live: each attempt renders as *Attempt 1 — Automatic — Sent*, then *To: …*, then
  *Subject: …*, and a *Reason:* line where there is one. If this column is cropped or
  unreadable the shot does not answer its caption.
- The **tab strip** with *Delivery History* active.
- The **filters** above the table visible (order, rule, status, mode and date range) — they are part of the
  screen and show the history is queryable.

**⚠ The recipient addresses in this column are the most likely place for a real email address
to leak into a public screenshot.** Check every visible address before uploading.

---

### screenshot-7.png — the order edit screen panel

> **Caption 7.** *The "Custom product emails" panel on the order edit screen — this order's deliveries, and the control that sends one of them by hand.*

**Screen:** the WooCommerce order edit screen for an order that has deliveries. With HPOS on
that is `wp-admin/admin.php?page=wc-orders&action=edit&id=<id>`.

**State it must show:**

- The **Custom product emails** metabox, with its title bar visible so the panel is
  identifiable as this plugin's — the caption names it in quotes precisely so a reader can find
  it.
- **At least two rows** in the panel's table: Rule, Status, When, Attempts, Actions.
- The **Send one of these emails for this order** control below the table, with its rule
  dropdown and its **Choose…** button, and the line explaining that you will be shown the
  recipient and asked to confirm. That control is the caption's *"sends one of them by hand"*
  and it must be in frame.
- Enough of the surrounding order screen to make it obvious this is an order, not a plugin
  page — the order number in the heading is ideal.

**⚠ This screen is full of a customer's real data by design** — billing name, address, email,
phone, order total. Use an invented customer on the scratch store and re-read the whole frame
before uploading.

---

### screenshot-8.png — the confirmation screen

> **Caption 8.** *The confirmation screen shown before an email is sent or resent by hand, naming the rule, the order and exactly who the email will go to.*

**Screen:** reached by choosing a rule in shot 7's control and pressing **Choose…**, or by a
**Resend** action link on the delivery history screen. It is a real screen with its own URL;
it is not a modal, so nothing is dimmed behind it.

**State it must show:**

- The heading — *Send this email for this order?* or *Resend this email?* — and the explanatory
  line under it.
- The **Rule** and **Order** fact rows.
- **Will be sent to**, with a resolved address. This is the row the caption exists for: the
  merchant sees the recipient **before** anything is sent. If the rule resolves to no address
  the row says so instead — a true screen, but it does not answer this caption. Pick a rule
  that resolves.
- The **warnings** for the action. The one-off-send warning (*"It does not replace or switch
  off the rule's automatic delivery…"*) is worth having in frame: it shows the screen tells the
  merchant what the action will and will not do.
- Both buttons — the confirm button (*Send the email* / *Resend the email*) and **Cancel**.

**Do not** capture the *test send* variant for this shot. Its warnings are about test emails
and its address is the tester's, neither of which the caption describes.

---

## After the eight are taken

| step | note |
|---|---|
| Check every image for personal data one more time | Names, addresses, email addresses, phone numbers, order totals, and anything in the browser chrome that survived the crop. |
| Check the numbering against `readme.txt` | Open `== Screenshots ==` and read caption *n* beside `screenshot-n.png`. Eight captions, eight files, in order. |
| Keep them out of the zip | Do not add an `assets/` screenshot directory to the plugin. The plugin's own `assets/` holds `admin.css` and `admin.js` and nothing else; these images go to the **SVN root** `assets/`, which is a different directory in a different place. |
| Keep the sources | Retain the full-resolution originals outside the repository. A directory listing is re-screenshotted every release, and matching the previous shots is far easier with the originals to hand. |

## Before submitting: paste `readme.txt` into the hosted validator, by hand

**This is a merchant step, in a browser, and nothing automates it.**

<https://wordpress.org/plugins/developers/readme-validator/>

Open the page, paste the **entire final `readme.txt`** into the box — not a URL, not an
excerpt — and press **Validate**. Do it on the exact bytes that are about to be committed
to `trunk/`, after every other change is finished.

**What a clean result looks like.** The page comes back with the parsed readme rendered as
the directory would show it, under a green **"Your readme rocks. Seriously. Nice work."**
No `Fatal error` block, and no `Warnings` list naming a missing or unrecognised header. A
few things it may say that are **not** failures: notes about optional headers the plugin
deliberately omits, and the reminder that `Tested up to` should name the current WordPress
version. Read every line it prints; anything mentioning **Stable tag**, **Requires at
least**, **Requires PHP**, **License** or the **short description** length is worth acting
on before submitting, because those are the fields the review queue reads first.

**Why this is still owed, and is not already done.** Prompt 13C Part J tried the endpoint
from the command line in six request shapes and every one came back as the *unparsed input
form* rather than a result — the page does not answer a scripted POST. Part J fell back to
WordPress.org's own readme parser as shipped in **Plugin Check**, which reported *"Checks
complete. No errors found."* That is the same parser and it is strong evidence, but it is
not the hosted result, and a submission checklist should not record an equivalent as the
thing itself. **Do not retry the endpoint from a script** — that has been tried, it does
not work, and the browser takes a minute.

## Two things that are not part of any prompt

1. **The WordPress.org account must be approved before submission.** The plugin is submitted
   under the `extonify` contributor account named in `readme.txt`. Until that account exists
   and is approved there is nothing to submit to.
2. **Submission is a manual step the merchant performs.** No prompt in this series logs in to
   WordPress.org, uploads anything, or touches the plugin SVN repository. Taking the eight
   screenshots, creating the SVN `assets/` directory, committing `trunk/` and tagging `1.0.0`
   are all done by hand, by the merchant, after the review queue has accepted the plugin.
