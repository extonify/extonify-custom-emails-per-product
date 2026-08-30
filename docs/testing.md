# Running the test suites

Two suites, deliberately kept apart.

## Unit — safe, always runnable

```bash
composer test:unit
```

Pure logic. Does **not** boot WordPress and does **not** touch any database.
`tests/Unit/SuiteIsolationTest.php` fails the run if WordPress ever creeps in.

---

## Integration — DESTRUCTIVE, runs against a dedicated test database

> ## ⚠ READ THIS BEFORE RUNNING THE INTEGRATION SUITE
>
> This suite **drops the plugin tables**. `SchemaVerificationTest` issues
> `DROP TABLE`, `ALTER TABLE … DROP COLUMN`, `ALTER TABLE … DROP INDEX` and even
> converts a table to MyISAM, because that is the only way to prove that
> `Migrator::verify_schema()` actually notices a broken schema — `dbDelta()`
> fails silently, so nothing weaker would be evidence.
>
> **The suite's shutdown repair recreates EMPTY tables. It restores no data.**
> Any rules or delivery history in the target database are gone permanently.
> That is why it gets a database of its own, and why you should never point it
> at the working development database.

### One-time setup: create the test database

> ## ⚠ THIS COPIES PERSONAL DATA
>
> `bin/create-test-db.php` clones **all structures and all data** — including
> `wp_users`, orders, billing and shipping addresses, email addresses and phone
> numbers.
>
> **Never run it against a database holding real client or production customer
> data.** Doing so creates a *second full copy* of that personal data, in a
> database explicitly marked as disposable and routinely dropped and recreated by
> an automated test suite. That is a data-protection problem in its own right,
> regardless of what the tests then do with it — a second copy is a second thing
> to secure, to include in a subject access response, and to erase.
>
> Run it only against a development install seeded with test data. The intended
> long-term setup is a **structure-only clone plus generated fixtures**, so no
> personal data is ever copied at all; that is recorded in `docs/p2-backlog.md`
> as pre-release work.

```bash
php bin/create-test-db.php
```

This creates `extonify_wcep_test` and clones the whole WordPress install into it
— structure and data — so the suite runs against a faithful, disposable copy:
same options, same active plugins, same WooCommerce tables, same orders.

- Re-run it any time to refresh: `php bin/create-test-db.php --force`.
- It refuses any target that is not alphanumeric and ending in `_test`/`_tests`,
  and refuses to target `DB_NAME`.
- Pick a different name with `--name=my_other_test`.

### Running it

```bash
EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 \
WP_ENVIRONMENT_TYPE=development \
EXTONIFY_WCEP_TEST_DB=extonify_wcep_test \
COMPOSER_PROCESS_TIMEOUT=0 \
composer test:integration
```

⚠ **`COMPOSER_PROCESS_TIMEOUT=0` IS NOT OPTIONAL, AND LEAVING IT OUT LOOKS LIKE A
TEST FAILURE.** Composer kills any script it runs after **300 seconds**; this suite
takes about half an hour. Without the variable the run dies mid-class with
*"The process … exceeded the timeout of 300 seconds"* and a **non-zero exit**, which
reads exactly like a failing suite until you get to the last line of the log. Prompt
13C Part H lost a gate-4 run to it. `vendor/bin/phpunit --testsuite integration` — the
same command, one layer down — has no such limit and is what every version-matrix
invocation in this file already uses.

`EXTONIFY_WCEP_TEST_DB` does two things: the bootstrap switches the live
connection onto that database before any test runs (and aborts if the switch
does not take effect), and the guard then measures **that** database rather than
the `wp-config.php` constant. The target database is printed before the suite
starts, whether it proceeds or refuses:

```
Extonify WCEP integration suite — target database: extonify_wcep_test
```

### The guard

The suite **refuses to run** unless all three conditions hold:

| Condition | Why |
|---|---|
| `EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1` | The operator has to opt in on purpose. |
| `WP_ENVIRONMENT_TYPE` is `local` or `development` | Never on a production install. |
| The **effective** database ends in `_test`/`_tests`, or equals `EXTONIFY_WCEP_TEST_DB` | The target is knowingly disposable. |

There is **no interactive confirmation fallback**: an automated run must fail
closed, not prompt.

The guard lives in `tests/bootstrap.php` so no individual test can forget it, and
every test that issues DDL additionally calls
`IntegrationTestCase::assert_destructive_tests_allowed()` before its first
destructive statement — so a future refactor of the bootstrap cannot silently
re-expose a live database.

> **Do not declare the development database as the test database.** The guard
> will accept it, because an explicit declaration is exactly how you say "yes, I
> mean this one" — and that is precisely the mistake to avoid. Use
> `bin/create-test-db.php` and target `extonify_wcep_test`.

### Recommended: set the environment type once

On any development install, put this in `wp-config.php` — correct independently
of this plugin, and it removes one variable from every invocation:

```php
define( 'WP_ENVIRONMENT_TYPE', 'development' );
```

---

## Both suites

```bash
composer test
```

Runs `test:unit` then `test:integration` as **separate** `phpunit` processes, so
the unit suite never inherits a booted WordPress. The integration half obeys the
same guard, so a plain `composer test` runs the unit suite and then refuses the
integration suite unless the variables above are set.

---

## Other destructive scripts

`bin/uninstall-smoke.php` drops the tables to exercise both uninstall paths. It
carries the same opt-in plus a typed confirmation of the database name:

```bash
EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 php bin/uninstall-smoke.php --i-understand
```

Note that this one runs against whatever `DB_NAME` wp-config points at — it does
not switch databases. Run it deliberately, and read the row counts it prints
before confirming.

`bin/activation-smoke.php` only activates and deactivates. It is non-destructive
to data and needs no guard.

`bin/wp-cli-uninstall-check.sh` is **gate 50's outcome half**. It runs the real
`wp plugin uninstall <slug> --deactivate` against a throwaway install and asserts
exit 0, no fatal in the output, and `Uninstalled 1 of 1` still reported:

```bash
bin/wp-cli-uninstall-check.sh /path/to/throwaway-wordpress
```

⚠ **It uninstalls the plugin and deletes its directory on the target — that is the
path under test.** Point it at a throwaway install, never at a tree you are working
in. It refuses unless the plugin is **active** there, because WP-CLI never boots an
inactive plugin and the check would otherwise pass vacuously. The mechanism half is
`tests/Integration/RenderShutdownTest.php`, which runs in the ordinary suite.

---

## Placeholder insertion — the seven manual cases

**⚠ NO PHP TEST CAN PROVE THIS, AND NONE PRETENDS TO.** Where a clicked placeholder
lands is decided at runtime by `assets/admin.js`, in a browser, from focus events and
TinyMCE's own state. The integration suite renders markup and parses it; it never runs
the script. Two things the suite *can* prove are asserted and are the reason the Part G
defect could exist unseen:

- `AdminOutputTest::test_every_placeholder_target_is_marked_and_nothing_else_is()` —
  every text-entry field the editor renders, pinned as a map, with exactly the subject,
  the heading and the body carrying `data-extonify-wcep-insertable`. The script branches
  on that attribute and on nothing else, so the map is the whole contract PHP owns.
- `AdminOutputTest::test_the_placeholder_reference_follows_the_content_fields()` — the
  reference is still **after** the fields in the DOM, so the two-column layout stays a
  CSS concern and tab order still runs fields → reference.

**Why there is no JavaScript test harness in the repository.** One was considered and
declined. `assets/admin.js` is hand-written and dependency-free by ADR-0017 §6, and the
only harness that would prove anything here needs a real browser: the defect lived in
the interaction between TinyMCE's iframe, WordPress's Visual/Text tab switch and DOM
focus events, none of which jsdom models. That means Node, a package manifest, a
headless Chrome and a second CI story — a build step in a plugin whose distinguishing
constraint is that it has none, to cover one function. **Part G verified all seven cases
in real Chrome instead**, driving the actual editor screen, and recorded the steps below
so the next round can repeat them by hand in five minutes. If a JS surface ever grows
past this one function, revisit the trade; today it does not earn its cost.

### The steps

Open the rule editor on any rule (**Product Emails → Rules → edit**). After each
insertion, look at **all three** fields — subject, heading and body — not just the one
you expected, because the failure this checks for is the token appearing somewhere else.

| # | Do this | Expect |
|---|---|---|
| 1 | Click into **Subject**, then click a placeholder button | The token is in **Subject**, at the caret. Heading and body unchanged |
| 2 | Click into **Heading**, then click a placeholder | The token is in **Heading**, at the caret. Subject and body unchanged |
| 3 | **Text** tab. Click into the body textarea, then click a placeholder | The token is in the **body**, at the caret. ⚠ **This is the merchant-reported defect**: before Part G the token went into Heading |
| 4 | **Visual** tab. Click into the editor, then click a placeholder | The token is in the **visual editor**, at the caret, inserted through `mceInsertContent` |
| 5 | **Visual** → insert → **Text** tab → insert → **Visual** tab → insert | All three tokens are in the body and nowhere else. ⚠ `tinymce-editor-init` fires ONCE, so this is the case that catches a stale "last focused field" surviving a tab switch |

**Cases 6 and 7 are as mandatory as the first five** — both are regressions found while
fixing the above, and both went to the wrong field before Part G (see the results table
below). They are listed separately only because they were written later:

| # | Do this | Expect |
|---|---|---|
| 6 | Click into **Heading**, switch to the **Text** tab, click into the body, insert | The token is in the **body**. The heading is only the target while it is the field you were last in |
| 7 | **Text** tab, click into the body, switch to the **Visual** tab **without clicking into it**, insert | The token appears in the **visual editor**. It must NOT go into the textarea behind it, which would look right and be discarded on the editor's next sync |

### What Part G observed

Driven in Google Chrome 151.0.7922.137 against the development install, on the rendered editor
screen. **The same script was run against the pre-Part-G code first, as a control** — a
verification that cannot fail before the fix proves nothing about after it:

| case | before the fix | after the fix |
|---|---|---|
| 1 Subject | token in Subject ✔ | token in Subject ✔ |
| 2 Heading | token in Heading ✔ | token in Heading ✔ |
| 3 Text tab, body focused | **token in Heading** ✘ | token in the body ✔ |
| 4 Visual tab, editor focused | **token in Heading** ✘ | token in the visual editor ✔ |
| 5 Visual → Text → Visual | **all three in Heading** ✘ | all three in the body ✔ |
| 6 Heading, then Text + body | **token in Heading** ✘ | token in the body ✔ |
| 7 Text → Visual, no re-focus | **token in Heading** ✘ | token in the visual editor ✔ |

⚠ **Case 4 is worse than the brief described.** The brief expected the Visual tab to
work "by accident", because `tinymce-editor-init` sets the remembered field to `null`.
It does — but only until the merchant touches a plain input. Once Subject or Heading has
been focused, nothing on the pre-Part-G path ever cleared the memory again, and the
Visual tab misdirected exactly like the Text tab. That is why the fix clears on the
editor's **`focus`** event and not only at init.

---

## The editor layout — what to look at

`assets/admin.css` puts the placeholder reference in a right-hand column at
**961px and up** and stacks it underneath below that, which is WordPress's own
`auto-fold` breakpoint. Check, in a browser:

- **wide (≥1200px)** — fields left, reference right, reference stays put while the page
  scrolls;
- **~1000px** — still two columns; the row labels stack above their inputs and the
  editor toolbar wraps, both of which are the pre-existing flex behaviour;
- **≤960px** — stacked, identical to the pre-Part-G screen;
- **admin menu folded** (Collapse Menu) — unchanged at every width; folding only widens
  the content area;
- **short viewport** — the reference grows its own scrollbar rather than clipping its
  lower groups (28 token buttons in five groups, about 2 000px of content), and
  tabbing to the last button scrolls it into view.

⚠ **CHECK THE STICKINESS BY SCROLLING, NOT BY READING THE COMPUTED STYLE.** Part G's first layout
reported `position: sticky` at every two-column width and did not stick at all: the reference was
the tallest grid item, so its grid area was its own height and it had nowhere to travel. The test
that catches this is to scroll the page and compare **how far each column moved** — the reference
must move less than the fields do. It is pinned for 116px at 1440 wide, 281px at 1000 wide and
213px on a 500px-high viewport, and then releases with the section it belongs to, because the
section is its containing block.

---

---

## Gate 48 — the clean-install smoke test

```bash
php bin/clean-install-smoke.php
```

Run it **from inside the site you want to test**, i.e. from the plugin directory of
that install — it boots that WordPress through `wp-load.php`.

Every step goes through a **rendered admin screen**, which is what gate 48 asks for:

1. the rules list is rendered and must show the empty state;
2. the rule editor is rendered for a new rule;
3. the form's nonce and delay-unit option are read **out of that markup** and posted
   back, so the payload is what the screen actually offers rather than what the script
   assumes it offers — and the same payload **without** the nonce must be denied;
4. the rules list is rendered again and must show the rule;
5. an order is completed and must send exactly one message from this plugin, addressed
   to the customer, with every placeholder resolved;
6. the delivery **history screen** is rendered and must show that delivery;
7. the order panel is rendered for the order;
8. the same trigger fires again and must send nothing more, and record no second
   tombstone.

It creates one product, one order and one administrator, and it does not clean them up
— so run it on a disposable install, ideally a brand-new WordPress with the **built
archive** installed, which is the only configuration that proves the zip works
somewhere it has never run.

Prompt 13's version of this test called `Admin\RuleActions::handle()` directly. That
exercised the real handler, nonce and sanitiser path, but gate 48 asks for a rule
created through the admin UI and the history screen opened, and calling a handler is
neither. A browser is still not required; rendering the screens and posting their forms
is (Prompt 13A item 7b).

---

## The ADR-0006 version matrix

The floor corner needs a WordPress, a WooCommerce and a PHP the development machine
does not have. The reproducible recipe, used by Prompt 13A:

```bash
# The BULK static builds. The `common` variant has mysqlnd but no mysqli, which wpdb
# requires. The compiled-in socket path differs from the system server's, so every
# invocation needs `-d mysqli.default_socket=`.
for v in 8.0.30 8.1.34 8.2.29 8.4.23; do
  curl -LO https://dl.static-php.dev/static-php-cli/bulk/php-$v-cli-linux-x86_64.tar.gz
  tar xzf php-$v-cli-linux-x86_64.tar.gz && mv php php-$v
done
./php-8.0.30 -d mysqli.default_socket=/var/run/mysqld/mysqld.sock -v
```

⚠ **AND A MATRIX RUNNER MUST CHECK EACH RUN'S EXIT CODE, NOT JUST LOOP.** Omitting
`-d mysqli.default_socket=` makes every runtime die in **about one second** with
*"Error establishing a database connection"* — rendered as an HTML error page, not as a
PHPUnit failure. Prompt 13C Part I wrote a `for` loop with no `$?` check and it printed
`MATRIX COMPLETE` over four one-second failures. A four-version matrix that finishes in
under five seconds has not run; check the exit code, and check the elapsed time against
the ~30 minutes one full suite actually takes.

⚠ **`-d memory_limit=` IS AS MANDATORY AS THE SOCKET, AND IT FAILS MUCH LATER.** The bulk
static builds default to **128M**; the system PHP this project's gate 4 runs on is set to
**2G**, and the integration suite legitimately peaks at **139 MB**. Without it every runtime
dies *partway through* — 9 to 21 minutes in, long enough to look like a real run — with
`Allowed memory size of 134217728 bytes exhausted`, in whatever file happened to be
allocating (Part I saw Sabberworm's CSS parser, `Requests/src/Iri.php` and Action
Scheduler's `OptionLock`, none of them this plugin's). Set it **equal to the system CLI
value** so the matrix varies the PHP version and nothing else:

```bash
"$BIN" -d mysqli.default_socket=/var/run/mysqld/mysqld.sock \
       -d memory_limit=2G vendor/bin/phpunit --testsuite integration
```

This is not tuning a failure away. The plugin's own memory behaviour is gated separately by
gate 10's collection census; a 128M runtime is simply not the runtime the declared range is
claimed against.

⚠ **The archive index is the only place to learn which patch versions exist** — the bulk
directory carries some patch levels and not others, so pin from a listing rather than
guessing:

```bash
curl -sSL https://dl.static-php.dev/static-php-cli/bulk/ \
  | grep -oE 'php-8\.[0-9]+\.[0-9]+-cli-linux-x86_64\.tar\.gz' | sort -uV
```

⚠ **These builds are disposable and do not survive a `/tmp` clear.** Prompt 13A's 8.0.30
build was gone by 13B, which is why that round could not execute the floor. Re-downloading
takes about a minute per version; treat the recipe, not the binary, as the artefact.

### The three Prompt 13C Part C environments — DIAGNOSTIC, not the ADR-0006 canonical corners

Two disposable installs plus the dev machine, each with its own directory, database and **copy**
of the plugin. Build scripts are throwaway; the shape is what matters:

| corner | path | database | WP | WC | PHP | HPOS | permalinks |
|---|---|---|---|---|---|---|---|
| floor | `/var/www/html/wcep-floor` | `wcep_floor_test` | 6.6.2 | 9.6.0 | 8.0.30 static | on | pretty |
| current | `/var/www/html/wcep-current` | `wcep_current_test` | 7.0.4 | 11.0.1 | 8.3.6 system | off | plain |
| dev | `/var/www/html/extonify` | `extonify_wcep_test` | 7.1 | 11.0.1 | 8.3.6 system | on | pretty |

⚠ **These three are NOT the ADR-0006 canonical corners, and they invert both of that pair's
configuration axes.** ADR-0006's current corner is HPOS **on** with **pretty** permalinks and
its floor corner is HPOS **off** with **plain** permalinks; the floor row above is HPOS on /
pretty and the current row is HPOS off / plain — opposite in each case. They exist to move
WordPress, WooCommerce and PHP together and to give gate 45 a floor, not to reproduce ADR-0006's
matrix. Cite a result from here as a *Part C diagnostic environment*, never as a canonical
corner, and do not compare its assertion totals with ADR-0006's.

⚠ **Sync and PROVE the sync before every corner run.** Copy the tree in, then:

```bash
diff -rq --exclude=poc --exclude=.phpunit.result.cache \
  <dev plugin dir> <corner plugin dir> && echo BYTE-IDENTICAL
```

Skipping this is what made Prompt 13A's `dev-full.log` worthless: a suite reporting green
against the wrong code looks exactly like a suite reporting green.

⚠ **Pin the corners.** Their `wp-config.php` sets `WP_AUTO_UPDATE_CORE false` and
`AUTOMATIC_UPDATER_DISABLED true`. The development install is **not** pinned and moved from
WordPress 7.0.4 to 7.1 in the middle of Prompt 13C — see the Part C finding in
`docs/p2-backlog.md`.

⚠ **`downloads.wordpress.org/plugin/woocommerce.zip` may serve a BETA.** It served
`11.1.0-beta.1` during Part C. Pin the stable version explicitly
(`woocommerce.11.0.1.zip`) rather than taking "latest".

⚠ **Keep the logs outside `/tmp`.** Part C wrote to `~/extonify-13c-logs/`; a `/tmp` clear has
already destroyed one round's evidence.

⚠ **Gate 44 does not need a whole corner.** A version-matrix run is the SAME WordPress,
WooCommerce, database and plugin tree under a different PHP binary — only gate 45's floor
CORNER needs its own install. Run the versions **sequentially** against the shared test
database: the suite drops and recreates the plugin tables, so two runtimes must never be in
flight at once.

⚠ **AND DO NOT CHECK THAT WITH `pgrep -cx php` — IT CANNOT SEE THE MATRIX RUNTIMES.** `-x`
matches a process whose name is **exactly** `php`. The static bulk builds run as `php-8.0.30`,
`php-8.1.34` and so on, so the check returns a confident **0** while a floor suite is running.
Prompt 13C Part E trusted it and started the floor suite **twice, 39 seconds apart**, both
pointed at `wcep_floor_test` — the precise collision the paragraph above warns about, reached by
obeying the rule. The doubled run was killed, the database dropped and recreated, the log
discarded and the corner reinstalled; nothing from it was reported. Use instead:

```bash
# counts any PHP BINARY running a suite, whatever that binary is called.
# Matches on the process NAME (comm) as well as the args, so a shell whose own
# command line happens to contain "phpunit" is not counted — a plain
# `grep -c '[p]hp.*phpunit'` over args alone reports those and was itself wrong.
ps -eo comm,args --no-headers | awk '$1 ~ /^php/ && /phpunit/' | wc -l
```

⚠ **`SIGKILL`ing a suite can leave the corner's WordPress mid-write.** After the kill above,
`wp core is-installed` returned false and the install had to be recreated before the clean re-run.
Budget for that: killing a suite is not free, and a corner that was interrupted must be rebuilt
and its tree re-proved byte-identical before anything it reports can be trusted.

Give each corner its own WordPress directory, its own database and its own **copy** of
the plugin — a symlink does not work, because `tests/bootstrap.php` resolves WordPress
with `dirname( __DIR__, 4 )` and `__DIR__` follows the symlink back to the original
install. Then point the suite at that corner's own database:

```bash
EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 WP_ENVIRONMENT_TYPE=development \
  EXTONIFY_WCEP_TEST_DB=<that corner's DB_NAME> \
  <php binary> -d memory_limit=2G vendor/bin/phpunit --testsuite integration
```

Declaring a corner's own database is the one case where pointing
`EXTONIFY_WCEP_TEST_DB` at `DB_NAME` is correct: the whole site is disposable.
