=== Extonify Custom Emails Per Product for WooCommerce ===
Contributors: extonify
Tags: woocommerce, custom emails, product emails, order emails, email recipients
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 9.6
WC tested up to: 11.0.1

Send a custom WooCommerce email for chosen products, either added into a native order email or sent as a separate message.

== Description ==

Some products need to say something the rest of the order does not — licence terms for a
download, or a lead time for a made-to-order item.

Write that message once, choose which products it applies to, and decide when it goes
out. It runs through WooCommerce's own email system, so it uses the store's own template,
header, footer and sender settings.

= Rules =

A rule is one message plus the conditions under which it is sent. A store can have as many
as it needs, each with its own targeting and content. Trigger, timing, recipients and
subject belong to a rule sent as its own email; an inserted rule takes those from the
WooCommerce email it joins.

= Targeting =

Target a rule by:

* individual products
* individual variations of a variable product
* product categories
* product tags
* product types (simple, variable, and the other types the store has)

Each can **include** products or **exclude** them, so you can say "everything in Furniture
except the flat-pack range". A rule can also require that *all* of its conditions match
rather than any.

= When a rule fires =

* when an order **reaches** a status you choose
* when an order **moves** from one specific status to another
* when an order is **refunded**, in full or in part

These apply to a rule sent as its own email. An inserted rule has no trigger of its own
— WooCommerce decides when its email sends.

= How the message is delivered =

Two modes, chosen per rule:

* **Insert** — placed inside a WooCommerce email the store already sends, so its reader
  gets one email rather than two. You choose which of the store's **order** emails it
  joins — a customer one or an admin one — and where the message appears. The list
  leaves out any email this plugin can confirm will not show the order, and flags any
  it cannot confirm either way.
* **Separate** — sent as its own email, with its own subject line.

= Delayed sending =

A rule sent as its own email can wait — seconds, minutes, hours or days after the trigger.
Delayed messages are queued through Action Scheduler, WooCommerce's own background queue.
If the rule is disabled, or the order changes so the message no longer applies, a queued
message is cancelled rather than sent.

= Several matching products =

When one rule matches several products in one order, you choose what is sent: **one
message for the order**, listing the matched products, or **one message per product**, so
each can speak about its own item. This choice too belongs to a rule sent as its own
email.

= Who receives it =

Recipients belong to a rule sent as its own email. A line can be the word *customer* for
the order's billing address, *admin* for the site administrator, or any email address, and
a rule can carry separate To, Cc and Bcc lists. A rule with no To recipient sends nothing
and records that as the reason. An inserted rule has none of its own: its content goes into
the WooCommerce email already being sent, to whoever that email is addressed to.

= Placeholders =

Body content can carry placeholders filled in from the live order, as can the subject and
heading of a rule sent as its own email: customer name, email and phone; order number,
date, status, total, payment and shipping method; billing and shipping addresses and a
link to the order in the customer's account; store name, sender address and links; the
matched product's name, SKU, quantity, link and variation attributes, plus list
placeholders covering every matched product; and custom fields on the order or the
matched order line.

The rule editor lists every placeholder with a description.

= Preview and test =

* **Preview** renders a rule against a real order in the WooCommerce email layout, in
  both HTML and plain text. It writes nothing and sends nothing. A per-product rule shows
  one representative message and says how many would be sent.
* **Send a test email** delivers the rendered message to an address you choose. The
  rule's own recipients are not used — not its To, Cc or Bcc. It is a real email, and only
  a rule sent as its own email can be tested: an inserted rule has none of its own.

= Delivery history =

Every automatic delivery is recorded: which rule, which order, which recipient, what
subject line, whether it succeeded, and the reason if it did not — store-wide on its own
screen, and per-order on the order edit screen.

Delivery details are not cleared on a schedule in this release: they are removed by a
personal-data erasure request, or when you remove the plugin's data.

= Sending by hand =

From an order you can send any eligible rule's message on demand, and resend one that has
already gone out. A message still waiting on its delay can be sent or cancelled from the
same place. Each asks for confirmation and shows the recipient first.

= Compatibility =

This plugin uses WooCommerce's native email system and is built for compatibility with
email customizers.

It declares compatibility with WooCommerce's High-Performance Order Storage (HPOS) and is
tested with HPOS enabled.

== Installation ==

1. Install it from the WordPress.org plugin directory, or upload the zip under
   **Plugins > Add New > Upload Plugin**.
2. Activate the plugin.
3. Go to **WooCommerce > Product Emails** to create your first rule.

WooCommerce must be installed and active. If WordPress, PHP or WooCommerce is older than
this plugin requires, it stays inactive and shows a notice naming the unmet requirement —
it never partially activates. On multisite it activates per site; network activation is
refused with a notice rather than half-enabled.

== Frequently Asked Questions ==

= Does the recipient get two emails? =

Only if you choose that. An **insert** rule places its message inside an email
WooCommerce is already sending, so one email arrives. A **separate** rule sends its own.

= Will the message match my store's email design? =

Yes — messages render through WooCommerce's own templates and inherit the store's header,
footer, colours and sender settings.

= What happens if two rules match the same product? =

Both send, unless you tell one to stop the others. Each rule has a priority, and can be
marked to stop further rules for that trigger.

= Can the same email be sent twice for the same order? =

No. Each automatic delivery is recorded against the order, the rule and the event that
caused it, and that record prevents a repeat — so the same automatic message is not sent
again if the order changes status back and forth. Sending again by hand is always
possible, and is recorded as a deliberate action.

= What happens to a delayed message if I disable the rule? =

It is cancelled. Queued messages are re-checked against the live rule and the live order
at the moment they are due, so a message that no longer applies is not sent.

= Is my data removed if I uninstall? =

The plugin creates its own database tables. On uninstall you choose whether to remove
them: removal is opt-in, so uninstalling does not throw away delivery history unless you
ask it to.

= Does it support personal-data export and erasure? =

Yes. Delivery details are returned by WordPress's personal-data exporter and removed by
its eraser, including where a message was addressed to someone other than the customer.

= Can I translate it? =

Yes. The plugin ships with a translation template and its interface is translatable. One
exception is stated rather than glossed over: some delivery diagnostics are still English
only. The coded reasons a scheduled email was cancelled are translated, but the free-text
detail composed for other outcomes is not — the reason and failure lines on a delivery
attempt, shown on the delivery history screen and in the order-edit panel, and the note
about unresolved placeholders above a preview. It is scheduled to be made translatable in
a later release.

== Screenshots ==

1. The rules list, showing each rule's trigger, targeting, delivery mode and status.
2. The rule editor — choosing the trigger and targeting the rule by product,
   variation, category, tag or type, including exclusions.
3. The rule editor — the message subject, heading and content, with the placeholder
   reference beside them.
4. The rule editor — delivery mode, delay and the choice between one message per
   order and one per matched product.
5. The preview screen, showing what a rule would send for a real order.
6. The delivery history screen, listing each delivery with its rule, order and
   status, and every attempt with its recipient, subject and outcome.
7. The "Custom product emails" panel on the order edit screen — this order's
   deliveries, and the control that sends one of them by hand.
8. The confirmation screen shown before an email is sent or resent by hand, naming
   the rule, the order and exactly who the email will go to.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
