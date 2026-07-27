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
composer test:integration
```

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
