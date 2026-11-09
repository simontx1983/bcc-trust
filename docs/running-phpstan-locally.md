# Running PHPStan locally

CI runs `composer phpstan` (`phpstan analyse --memory-limit=4G`) at level 8 and
it is a blocking step. It is also the only check that catches a whole class of
defect `php -l` and the unit suite cannot see — a docblock type that has
widened, a reference to a class that does not exist, an `@param` lost when a
method was extracted. bcc-trust #368 shipped with three such errors because
this was not run locally first.

This is how to run it, and the three things that go wrong.

## The short version

```bash
cd app/public/wp-content/plugins/bcc-trust

composer update --no-interaction --prefer-dist --ignore-platform-req=ext-gmp
composer phpstan
```

Expected output: `[OK] No errors`.

## ⚠ Trap 1 — `composer install` cannot work here

`composer.lock` is **gitignored by design** (`.gitignore`): production `vendor/`
is committed for the Git-based deploy, and dev dependencies are restored on
demand. With no lock file there is nothing for `install` to install from, so
`composer install` is the wrong verb. `composer update` is what CI runs, for
the same reason.

`--ignore-platform-req=ext-gmp` is required on a machine without the GMP
extension: `simplito/elliptic-php` declares `ext-gmp`, and without the flag
composer refuses to resolve anything at all. PHPStan does not need GMP to
analyse the code.

## ⚠⚠ Trap 2 — `composer update` dirties ~675 tracked files

`.gitignore` ignores `/vendor/bin/`, `/vendor/phpstan/`, `/vendor/php-stubs/`
and `/vendor/szepeviktor/` with the comment that this keeps a local install
from dirtying `git status`. **Measured: that is only half true.** `update` also
upgrades the *production* dependencies, which ARE committed, so it rewrites and
deletes tracked files under `vendor/brick/`, `vendor/composer/` and others —
675 paths on a current checkout.

So after a local run, restore them:

```bash
git checkout -- vendor
git clean -fd vendor
```

⛔ **Do not `composer dump-autoload` to "fix" anything.** It rewrites the
tracked `vendor/composer/autoload_*.php` pair, and this repo is CRLF locally
while the blobs are LF, so it also produces line-ending churn across ~8
unrelated vendor files. If you have already run it: `git checkout -- vendor`.

## ⚠⚠⚠ Trap 3 — restoring `vendor/` breaks PHPStan, and the error lies to you

This is the one worth knowing, because the symptom points at the wrong thing.

The committed autoloader is generated `--no-dev`, so it maps **zero** PHPStan
or PHPUnit classes. Restoring `vendor/` therefore puts back an autoloader that
cannot see the dev tools, while they are still physically on disk (their paths
are gitignored, so the restore leaves them). PHPStan then fails in one of two
ways depending on its cache:

| State | What you see |
|---|---|
| stale container cached | a 30-frame stack trace ending `thrown in …/Temp/phpstan/cache/nette.configurator/Container_*.php on line 8353` |
| cache cleared | `Service 'rules.0': Class 'SzepeViktor\PHPStan\WordPress\HookCallbackRule' not found.` |

Neither message mentions the autoloader. The second is the honest one, and it
appears only after:

```bash
rm -rf "$TEMP/phpstan"      # or %TEMP%\phpstan on Windows
```

**So a clean `git status` and a working PHPStan are mutually exclusive in one
checkout.** Pick one:

- **analysing** — run `composer update …` and leave `vendor/` dirty until you
  are done; restore before committing;
- **committing** — restore `vendor/`, and re-run `composer update …` next time
  you need to analyse.

`scripts/phpstan-local.sh` does the first for you and restores afterwards.

## Other prerequisites

- **The bcc-core sibling.** `phpstan.neon` has `scanDirectories: ../bcc-core/src/`,
  which in the normal plugins layout is the real bcc-core plugin. In a git
  worktree it is not, so either work from the plugins layout or place a copy of
  bcc-core's `src/` next to the worktree:
  `git -C …/plugins/bcc-core archive origin/main src | tar -x -C ..`
  ⚠ Copy it from `origin/main`, not from the local checkout, which can be many
  commits behind.
- **`--memory-limit`.** CI uses `4G`. At `1G` the parallel worker dies and
  reports a *severe error* with an INCOMPLETE result, which does not look like
  a type error at all. `-1` is the safe local choice.
- **PHPStan does not analyse `tests/`.** `paths` is `app/` plus exactly two
  `includes/` files. A green run says nothing about a test file.

## Analysing one file

Much faster than the whole tree while iterating, and the output is scoped to
what you changed:

```bash
composer exec -- phpstan analyse --memory-limit=-1 --no-progress \
  app/Domain/Onchain/Services/GatedGroupProvisioningService.php
```

⚠ A single-file run still loads the whole config, so it can report errors in
that file caused by code elsewhere. It is for iterating, not for signing off —
finish with a full `composer phpstan`.

## Related

- `scripts/phpstan-local.sh` — the sequence above, with the restore
- `scripts/phpstan-all.sh` — every BCC plugin in turn
- `scripts/arch-guardrails.sh` — the grep-based §1–§9 rules
