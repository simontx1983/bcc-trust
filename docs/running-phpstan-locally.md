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

composer install --no-interaction --prefer-dist --ignore-platform-req=ext-gmp
composer phpstan
```

Expected output: `[OK] No errors`.

## ⚠ Trap 1 — use `install`, never `update` (INVERTED by #191)

⚠⚠ **This section used to say the opposite, and it was right at the time.**
Until #191, `composer.lock` was gitignored, there was nothing for `install`
to install from, and `update` was the only verb that worked — which is also
how CI came to be testing `guzzle 7.16.1` and `brick/math 1.0.0` while
production shipped `7.10.0` and `0.17.1`. #191 committed the lock and moved
all three CI jobs to `install`.

So now:

- **`composer install`** is correct. It reproduces exactly the pinned set
  that CI tests and the deploy ships.
- ⛔ **`composer update` REWRITES the committed `composer.lock`** as a side
  effect of asking for a type check, and the next person's `install` then
  silently gets a different dependency set. If you genuinely need to move a
  dependency, run `composer update` **deliberately** and commit the lock as
  its own reviewable change — never as a by-product.
- `config.platform.php` is pinned to **8.2.0** in `composer.json`, so the
  resolution does not depend on whose PHP ran composer.
  ⚠ It is pinned to the FLOOR, not to production. Production and staging run
  **PHP 8.5.12** while CI runs **8.2**; resolving against 8.5.12 pulls
  `symfony/finder v8.1.11`, which requires `php >= 8.4.1`, and because
  `platform` overrides make composer SKIP the real platform check, CI would
  install 8.4-only code onto 8.2 **without a warning**. Measured: 8.2.0 and
  8.5.12 differ by that one dev package and nothing else.

`--ignore-platform-req=ext-gmp` is required on a machine without the GMP
extension: `simplito/elliptic-php` declares `ext-gmp`, and without the flag
composer refuses even to verify the lock. PHPStan does not need GMP to
analyse the code.

## ⚠⚠ Trap 2 — the dev install dirties 7 tracked files (was ~675)

`.gitignore` ignores `/vendor/bin/`, `/vendor/phpstan/`, `/vendor/php-stubs/`
and `/vendor/szepeviktor/` with the comment that this keeps a local install
from dirtying `git status`. **Measured: that is only half true, and it still
is.** The committed autoloader is generated `--no-dev`, so a dev install
rewrites the TRACKED `vendor/composer/autoload_*.php` pair into its dev
flavour. Measured on the post-#191 tree, `composer install` dirties exactly
**7** tracked files, all metadata:

```
vendor/composer/autoload_classmap.php   vendor/composer/autoload_psr4.php
vendor/composer/autoload_files.php      vendor/composer/autoload_static.php
vendor/composer/autoload_namespaces.php vendor/composer/installed.json
                                        vendor/composer/installed.php
```

⚠ **The old "~675 files" figure in this document was wrong twice over.**
It came from `git status --porcelain`, which flags a file whose mtime moved
even when its content is unchanged: of 675 reported, only **272** had real
content differences and 403 were stat-cache noise. And all 272 were
`composer update` upgrading the committed PRODUCTION tree — the drift #191
existed to close. With the lock committed there is nothing left to upgrade,
so the number is 7. **Never quote a `git status` count as a change count;
use `git diff --name-only`, which runs the content comparison.**

So after a local run, restore them:

```bash
git checkout -- vendor
git clean -fd vendor
```

⛔ **Do not `composer dump-autoload` to "fix" anything.** It rewrites the
tracked `vendor/composer/autoload_*.php` pair into whichever flavour your
current install has. If you have already run it: `git checkout -- vendor`.

⚠ On line endings, since this repo is CRLF in the working tree and LF in the
blobs: `core.autocrlf=true` normalises on commit, so a tool that writes LF
produces the SAME blob as one that writes CRLF and there is nothing to fix.
Verify at the blob level (`git show HEAD:<path>`) against an untouched file
as a control — and do not use `grep -c $'$'` for this, which reports every
line as CRLF in Git Bash even on a pure-LF file.

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

- **analysing** — run `composer install …` and leave `vendor/` dirty until you
  are done; restore before committing;
- **committing** — restore `vendor/`, and re-run `composer install …` next time
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
- `composer.lock` — committed since #191; `composer install` is the only
  verb that honours it
- `scripts/phpstan-all.sh` — every BCC plugin in turn
- `scripts/arch-guardrails.sh` — the grep-based §1–§9 rules
