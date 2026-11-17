#!/usr/bin/env bash
# Waits until the cloned host (mod_adaptivequiz) has at least the version this subplugin requires.
#
# Why (CI run of 17.11.2026): the adapter and the host are separate repositories. When the adapter is pushed
# before the host, its CI clones the old host branch head and fails on classes and constants the old
# host lacks (17.11.2026: 21 errors, 33 failures, all "test_information_source not found" or
# "SCALES_COMPLETE undefined"). A few minutes later the host is there - so the job waits for it.
#
# Usage: await_host_version.sh <plugindir> <hostdir> <hostbranch> [<pinned host ref>]
#   A pinned ref (manual run) is taken as given: if it is too old, the job fails at once.
# Environment (for the self-test): AWAIT_TRIES (default 30), AWAIT_SLEEP seconds (default 30).
set -euo pipefail

plugindir=$1
hostdir=$2
branch=$3
pinned=${4:-}
tries=${AWAIT_TRIES:-30}
pause=${AWAIT_SLEEP:-30}

# Reads $plugin->version (key "version") or $plugin->dependencies[<key>] from a version.php.
read_version() {
    php -r '
        foreach (["MOODLE_INTERNAL" => 1, "MATURITY_ALPHA" => 50, "MATURITY_BETA" => 100,
                  "MATURITY_RC" => 150, "MATURITY_STABLE" => 200] as $c => $v) {
            defined($c) || define($c, $v);
        }
        $plugin = new stdClass();
        include($argv[2]);
        $key = $argv[1];
        echo $key === "version" ? (int) $plugin->version : (int) ($plugin->dependencies[$key] ?? 0);
    ' "$1" "$2"
}

need=$(read_version mod_adaptivequiz "$plugindir/version.php")
if [ "$need" -eq 0 ]; then
    echo "Keine Host-Abhängigkeit in $plugindir/version.php - nichts zu prüfen."
    exit 0
fi

attempt=1
while :; do
    have=$(read_version version "$hostdir/version.php")
    sha=$(git -C "$hostdir" rev-parse --short HEAD 2>/dev/null || echo '?')
    if [ "$have" -ge "$need" ]; then
        echo "Host mod_adaptivequiz $have @ $sha erfüllt die Abhängigkeit $need."
        exit 0
    fi
    if [ -n "$pinned" ]; then
        echo "::error::Der festgelegte Host ($pinned, Version $have) ist älter als die verlangte $need."
        exit 1
    fi
    if [ "$attempt" -ge "$tries" ]; then
        echo "::error::Host-Zweig $branch steht auf $have @ $sha, verlangt ist $need. Erst den Host pushen" \
             "(Push-Reihenfolge: local_catquiz, Host, Adapter), dann diesen Lauf neu starten."
        exit 1
    fi
    echo "::warning::Host-Zweig $branch steht auf $have @ $sha, verlangt ist $need - warte ${pause}s auf den Host-Push ($attempt/$tries)."
    sleep "$pause"
    git -C "$hostdir" fetch -q --depth 1 origin "$branch"
    git -C "$hostdir" checkout -q FETCH_HEAD
    attempt=$((attempt + 1))
done
