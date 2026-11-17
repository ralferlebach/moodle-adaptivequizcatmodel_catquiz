#!/usr/bin/env bash
# Waits until the cloned plugins have at least the versions the plugin under test names in its version.php.
#
# Why (CI runs of 17.11.2026): local_catquiz, mod_adaptivequiz and adaptivequizcatmodel_catquiz live in three
# repositories and are pushed one after the other. A run that starts before the last push clones the older
# branch head of the others and fails on what only the new state brings: the adapter on the interface
# test_information_source and the constant stop_reason::SCALES_COMPLETE, local_catquiz on the host's notice
# before the minimum (Behat) and on the dependency check of the installation (Playwright). The missing states
# arrive a few minutes later - so the job waits for them instead of testing a pair that does not belong together.
#
# Usage: await_dependencies.sh <version.php of the plugin under test> \
#            <component> <checkout dir> <branch> <pinned ref or ""> [<component> <dir> <branch> <pinned> ...]
#   A checkout needs its .git directory (call this before .git is removed).
#   A pinned ref (manual run) is taken as given: if it is too old, the job fails at once.
#   Components the version.php does not name are not checked.
# Environment (for the self-test): AWAIT_TRIES (default 30), AWAIT_SLEEP seconds (default 30) - 15 minutes.
set -euo pipefail

versionfile=$1
shift
if [ $(($# % 4)) -ne 0 ] || [ $# -eq 0 ]; then
    echo "::error::await_dependencies.sh: expected groups of <component> <dir> <branch> <pinned>" >&2
    exit 2
fi
tries=${AWAIT_TRIES:-30}
pause=${AWAIT_SLEEP:-30}

# Reads $plugin->version (key "version") or $plugin->dependencies[<key>] (0 when absent or ANY_VERSION).
read_version() {
    php -r '
        foreach (["MOODLE_INTERNAL" => 1, "MATURITY_ALPHA" => 50, "MATURITY_BETA" => 100,
                  "MATURITY_RC" => 150, "MATURITY_STABLE" => 200, "ANY_VERSION" => "any"] as $c => $v) {
            defined($c) || define($c, $v);
        }
        if (!is_file($argv[2])) {
            fwrite(STDERR, "::error::Keine version.php unter $argv[2]\n");
            exit(3);
        }
        $plugin = new stdClass();
        include($argv[2]);
        $key = $argv[1];
        $value = $key === "version" ? $plugin->version : ($plugin->dependencies[$key] ?? 0);
        echo is_numeric($value) ? (int) $value : 0;
    ' "$1" "$2"
}

args=("$@")
attempt=1
while :; do
    lagging=()
    for ((i = 0; i < ${#args[@]}; i += 4)); do
        component=${args[i]} dir=${args[i+1]} branch=${args[i+2]} pinned=${args[i+3]}
        need=$(read_version "$component" "$versionfile")
        [ "$need" -eq 0 ] && continue
        have=$(read_version version "$dir/version.php")
        sha=$(git -C "$dir" rev-parse --short HEAD 2>/dev/null || echo '?')
        if [ "$have" -ge "$need" ]; then
            echo "$component $have @ $sha erfüllt die Abhängigkeit $need."
            continue
        fi
        if [ -n "$pinned" ]; then
            echo "::error::Der festgelegte Stand von $component ($pinned, Version $have) ist älter als die verlangte $need."
            exit 1
        fi
        lagging+=("$i")
        echo "::warning::$component: Zweig $branch steht auf $have @ $sha, verlangt ist $need ($attempt/$tries)."
    done
    [ ${#lagging[@]} -eq 0 ] && exit 0
    if [ "$attempt" -ge "$tries" ]; then
        echo "::error::Abhängigkeiten nicht erfüllt. Erst die fehlenden Stände pushen (Reihenfolge: local_catquiz," \
             "Host, Adapter; je 1.3 vor 1.2), dann diesen Lauf neu starten."
        exit 1
    fi
    echo "Warte ${pause}s auf den Push der fehlenden Stände."
    sleep "$pause"
    for i in "${lagging[@]}"; do
        git -C "${args[i+1]}" fetch -q --depth 1 origin "${args[i+2]}"
        git -C "${args[i+1]}" checkout -q FETCH_HEAD
    done
    attempt=$((attempt + 1))
done
