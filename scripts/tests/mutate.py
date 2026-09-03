#!/usr/bin/env python3
"""
Literal-string mutator for the PR 7 controls.

⚠ WHY THIS EXISTS INSTEAD OF sed/perl.

The first version of the runner used
`perl -0777 -pi -e "s/\\Q$needle\\E/$replacement/"`. The shell interpolates
`$key`, `$banned` and friends INSIDE the double quotes before perl ever sees
them, so the pattern that reached perl was not the pattern intended — some
invocations died with a syntax error (visible, fine) and others exited 0
having changed NOTHING (invisible, and reported as a SURVIVING mutant).

That is the worst possible failure for a mutation runner: it manufactures
"the tests do not constrain this" out of a broken tool. 22 of 23 controls
"survived" that way.

So this script:
  * takes the needle and replacement as ARGV, never through a shell-quoted
    regex, and treats both as LITERAL text;
  * requires the needle to be present, and to be UNIQUE;
  * re-reads the file afterwards and asserts the bytes actually changed;
  * exits non-zero on any of those, so the runner reports BROKEN rather than
    silently counting a survivor.

⚠ Reads and writes with newline='' so a CRLF tree round-trips byte-exactly —
a normalizing write would rewrite every line and make the "did it change?"
check meaningless.

Usage: mutate.py <file> <needle-file> <replacement-file>
"""

import io
import sys


def main() -> int:
    if len(sys.argv) != 4:
        sys.stderr.write("usage: mutate.py <file> <needle-file> <replacement-file>\n")
        return 2

    target, needle_path, replacement_path = sys.argv[1], sys.argv[2], sys.argv[3]

    source = io.open(target, encoding="utf-8", newline="").read()
    needle = io.open(needle_path, encoding="utf-8", newline="").read()
    replacement = io.open(replacement_path, encoding="utf-8", newline="").read()

    # The control files are authored with \n; the tree is CRLF. Match the
    # target's convention so the needle can actually be found.
    if "\r\n" in source:
        needle = needle.replace("\r\n", "\n").replace("\n", "\r\n")
        replacement = replacement.replace("\r\n", "\n").replace("\n", "\r\n")

    # Trailing newline from the heredoc is not part of the needle.
    needle = needle.rstrip("\r\n")
    replacement = replacement.rstrip("\r\n")

    occurrences = source.count(needle)
    if occurrences == 0:
        sys.stderr.write(f"mutate: needle not found in {target}\n")
        return 3
    if occurrences > 1:
        sys.stderr.write(f"mutate: needle is ambiguous ({occurrences} matches) in {target}\n")
        return 4

    mutated = source.replace(needle, replacement, 1)

    if mutated == source:
        sys.stderr.write(f"mutate: replacement is identical to the needle in {target}\n")
        return 5

    io.open(target, "w", encoding="utf-8", newline="").write(mutated)

    # ⚠ Prove it landed. An exit code from the writer is not evidence the
    # bytes on disk changed.
    verify = io.open(target, encoding="utf-8", newline="").read()
    if verify != mutated:
        sys.stderr.write(f"mutate: write did not stick for {target}\n")
        return 6

    return 0


if __name__ == "__main__":
    sys.exit(main())
