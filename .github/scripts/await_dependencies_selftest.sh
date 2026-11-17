#!/usr/bin/env bash
# Self-test of await_dependencies.sh against local git origins (no network). Exit 0 = all cases as expected.
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
script=$here/await_dependencies.sh
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
export AWAIT_SLEEP=1 GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
fails=0

origin() { # origin <name> <version>: a bare origin with branch "line" carrying that version
    git init -q --bare "$work/$1.git"
    git init -q "$work/$1.src"
    git -C "$work/$1.src" checkout -q -b line
    printf '<?php\ndefined("MOODLE_INTERNAL") || die();\n$plugin->version = %s;\n$plugin->maturity = MATURITY_ALPHA;\n' "$2" \
        > "$work/$1.src/version.php"
    git -C "$work/$1.src" add version.php
    git -C "$work/$1.src" commit -qm "v$2"
    git -C "$work/$1.src" push -q "$work/$1.git" line 2>/dev/null
}
bump() { # bump <name> <version>: push a newer version to the origin
    sed -i "s/version = [0-9]*/version = $2/" "$work/$1.src/version.php"
    git -C "$work/$1.src" commit -qam "v$2"
    git -C "$work/$1.src" push -q "$work/$1.git" line 2>/dev/null
}
clone() { rm -rf -- "$work/$1"; git clone -q --depth 1 --branch line "file://$work/$1.git" "$work/$1" 2>/dev/null; }
expect() { # expect <case> <wanted rc> <pattern the output must hold, "" for none> <command...>
    local name=$1 want=$2 pattern=$3; shift 3
    "$@" > "$work/out.log" 2>&1; local rc=$?
    if [ "$rc" -eq "$want" ] && { [ -z "$pattern" ] || grep -q -- "$pattern" "$work/out.log"; }; then echo "OK   $name (rc $rc)"; else echo "FAIL $name (rc $rc, erwartet $want)"; sed 's/^/     /' "$work/out.log"; fails=1; fi
}

printf '<?php\ndefined("MOODLE_INTERNAL") || die();\n$plugin->version = 1;\n$plugin->dependencies = [\n    %s => 20,\n    %s => 30,\n    %s => ANY_VERSION,\n];\n' \
    "'host'" "'adapter'" "'other'" > "$work/version.php"

origin host 20; origin adapter 29
clone host; clone adapter
expect "Adapter zu alt, kein Push -> rot" 1 "Erst die fehlenden" env AWAIT_TRIES=2 bash "$script" "$work/version.php" \
    host "$work/host" line "" adapter "$work/adapter" line ""
expect "festgelegter alter Adapter -> sofort rot" 1 "festgelegte Stand von adapter" env AWAIT_TRIES=5 bash "$script" "$work/version.php" \
    host "$work/host" line "" adapter "$work/adapter" line "abc123"
( sleep 2; bump adapter 30 ) &
expect "Adapter kommt waehrend des Wartens -> gruen" 0 "adapter 30 @" env AWAIT_TRIES=10 bash "$script" "$work/version.php" \
    host "$work/host" line "" adapter "$work/adapter" line ""
wait
expect "nicht genannte Komponente wird nicht geprueft" 0 "" env AWAIT_TRIES=1 bash "$script" "$work/version.php" \
    unnamed "$work/missing" line ""
expect "ANY_VERSION wird nicht abgewartet" 0 "" env AWAIT_TRIES=1 bash "$script" "$work/version.php" \
    other "$work/missing" line ""
expect "falsche Argumentzahl -> Aufruffehler" 2 "groups of" bash "$script" "$work/version.php" host "$work/host" line
exit $fails
