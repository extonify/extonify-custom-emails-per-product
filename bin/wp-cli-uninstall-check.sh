#!/usr/bin/env bash
#
# GATE 50 — `wp plugin uninstall <slug> --deactivate` must exit 0 with no fatal.
#
# ⚠ WHY THIS EXISTS AS A SEPARATE SCRIPT. The defect it guards is a LIFECYCLE one and
# cannot be staged in-process: WP-CLI deactivates, uninstalls and then DELETES the plugin
# directory inside one PHP process, so WordPress's `shutdown` action fires after the
# plugin's own files are gone. Before Prompt 13C `RenderEvents::on_shutdown()` lazily
# constructed a `RenderContext` at that moment and the autoloader had nothing to include:
#
#     PHP Fatal error: Uncaught Error: Class "Extonify\WCEP\Render\RenderContext" not found
#     Exit code 255
#
# The uninstall's data work had already completed correctly — the tables were dropped and
# the options removed — so nothing was lost. What broke was the exit code, which is enough
# to fail a deployment script, and the trace, which is enough to make an operator think
# something was.
#
# ⚠ IT IS DESTRUCTIVE AND IT IS MEANT TO BE. It uninstalls the plugin from the target
# install and DELETES its directory there — that is the path under test. Point it at a
# throwaway install, never at a development tree you are working in.
#
# ⚠ THE PLUGIN MUST BE ACTIVE ON THE TARGET. `wp plugin uninstall` on an already-inactive
# plugin never boots it, so the shutdown hook is never registered and the check would pass
# vacuously. The script asserts activeness first and refuses rather than reporting a green
# it did not earn.
#
# Usage:
#   bin/wp-cli-uninstall-check.sh /path/to/throwaway-wordpress [slug]
#
# Companion evidence: tests/Integration/RenderShutdownTest.php asserts the MECHANISM
# (the sweep constructs nothing when nothing rendered). This asserts the OUTCOME.

set -u

WP_PATH="${1:-}"
SLUG="${2:-extonify-custom-emails-per-product}"

if [ -z "${WP_PATH}" ]; then
	echo "usage: $0 /path/to/throwaway-wordpress [slug]" >&2
	exit 2
fi

if [ ! -f "${WP_PATH}/wp-load.php" ]; then
	echo "not a WordPress install: ${WP_PATH}" >&2
	exit 2
fi

if ! command -v wp >/dev/null 2>&1; then
	echo "wp-cli is not on PATH." >&2
	exit 2
fi

echo "Extonify WCEP — gate 50: wp plugin uninstall --deactivate exit code"
echo "  install: ${WP_PATH}"
echo "  plugin:  ${SLUG}"
echo

# THE PREMISE, ASSERTED. An inactive plugin is never booted, so the shutdown hook is
# never registered and this whole check would prove nothing.
STATUS="$( wp --path="${WP_PATH}" --skip-themes plugin get "${SLUG}" --field=status 2>/dev/null )"

if [ "${STATUS}" != "active" ]; then
	echo "REFUSED: ${SLUG} is '${STATUS:-not installed}' on the target, not 'active'." >&2
	echo "         The single-process boot is the whole point of this check." >&2
	exit 2
fi

OUTPUT="$( wp --path="${WP_PATH}" --skip-themes plugin uninstall "${SLUG}" --deactivate 2>&1 )"
CODE=$?

echo "${OUTPUT}"
echo
echo "exit code: ${CODE}"

FAILED=0

if [ "${CODE}" -ne 0 ]; then
	echo "  FAIL — expected exit code 0."
	FAILED=1
fi

# ⚠ THE EXIT CODE ALONE IS NOT ENOUGH. A future fatal in a hook that runs before WP-CLI
# sets its own status could still leave a 0, and the trace is the thing an operator sees.
if printf '%s' "${OUTPUT}" | grep -qiE "fatal error|uncaught (error|exception)"; then
	echo "  FAIL — the output carries a fatal."
	FAILED=1
fi

# The data work must still have happened. A guard that skipped the sweep by skipping the
# uninstall would pass both checks above.
if ! printf '%s' "${OUTPUT}" | grep -qi "uninstalled 1 of 1"; then
	echo "  FAIL — WP-CLI did not report the plugin as uninstalled."
	FAILED=1
fi

if [ "${FAILED}" -eq 0 ]; then
	echo "  OK — exit 0, no fatal, plugin uninstalled."
	echo
	echo "GATE 50 (exit code) PASSED"
	exit 0
fi

echo
echo "GATE 50 (exit code) FAILED"
exit 1
