#!/usr/bin/env python3
"""Evidence of one CI job: what was tested together, and that the tests that matter ran.

Re-audit 19.11.2026, CI matrix P0: a green workflow is not enough. Every run has to say which commits of the three
plugins it built (engine local_catquiz, host mod_adaptivequiz, adapter adaptivequizcatmodel_catquiz), on which
Moodle, PHP and database, how many tests and assertions ran, what was skipped - and the tests named for the issues
must have run, passed and not been skipped. This script writes that record and fails the job when the named tests
are missing, failed or skipped.

    ci_evidence.py --title TITLE --moodle MOODLEDIR [--db pgsql|mariadb]
                   --component NAME=DIR [--component NAME=DIR ...]
                   [--junit FILE --required FILE] [--behat-log FILE --required-features FILE --features-dir DIR]
                   --out DIR

--component: a plugin of the run; DIR is its git checkout (commit) - version.php is read from there.
--junit:     the JUnit XML of the PHPUnit run (vendor/bin/phpunit --log-junit).
--required:  one test class per line (the short name, e.g. stop_matrix_test, or Class::method); # comments.
--behat-log: the console output of the Behat run - its summary lines (scenarios, steps, reruns) are recorded.
--required-features: one feature file name per line; each must exist under --features-dir, carry the component
             tag and no skip tag.

Writes OUT/evidence.md and OUT/evidence.json, appends the Markdown to $GITHUB_STEP_SUMMARY when set, and exits 1
when a required test or feature is not accounted for. Standard library only.
"""
import argparse
import json
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET


def run(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=30).stdout.strip()
    except Exception:  # pylint: disable=broad-except
        return ''


def php_value(path, name):
    """$plugin->version / $release etc. from a version.php, without running PHP."""
    try:
        text = open(path, encoding='utf-8').read()
    except OSError:
        return ''
    match = re.search(r'\$' + re.escape(name) + r"\s*=\s*'?([^';]+)'?\s*;", text)
    return match.group(1).strip() if match else ''


def component_info(name, directory):
    version = php_value(os.path.join(directory, 'version.php'), 'plugin->version')
    release = php_value(os.path.join(directory, 'version.php'), 'plugin->release')
    sha = run(['git', '-C', directory, 'rev-parse', 'HEAD']) if os.path.isdir(os.path.join(directory, '.git')) else ''
    ref = run(['git', '-C', directory, 'rev-parse', '--abbrev-ref', 'HEAD']) if sha else ''
    if ref == 'HEAD' and name == os.environ.get('CI_EVIDENCE_SELF', ''):
        ref = os.environ.get('GITHUB_REF_NAME', ref)
    # A tree with changes over its commit is not that commit: say so (never the case in a clean CI checkout).
    if sha and run(['git', '-C', directory, 'status', '--porcelain', '--untracked-files=no']):
        sha += ' + local changes'
    return {'component': name, 'dir': directory, 'sha': sha or 'unknown', 'ref': ref, 'version': version,
            'release': release}


def moodle_info(directory):
    for candidate in (os.path.join(directory, 'public', 'version.php'), os.path.join(directory, 'version.php')):
        if os.path.isfile(candidate):
            return {'release': php_value(candidate, 'release'), 'version': php_value(candidate, 'version'),
                    'branch': php_value(candidate, 'branch')}
    return {'release': 'unknown', 'version': '', 'branch': ''}


def db_info(db):
    if db == 'pgsql':
        version = run(['psql', '-h', '127.0.0.1', '-U', 'postgres', '-tAc', 'show server_version'])
        return 'PostgreSQL ' + (version or 'unknown')
    if db in ('mariadb', 'mysqli'):
        version = run(['mysql', '-h', '127.0.0.1', '-u', 'root', '-N', '-e', 'select version()'])
        return 'MariaDB/MySQL ' + (version or 'unknown')
    return db or 'n/a'


def parse_junit(path):
    tree = ET.parse(path)
    cases = []
    for case in tree.iter('testcase'):
        cls = case.get('class') or case.get('classname') or ''
        status = 'passed'
        message = ''
        for tag in ('failure', 'error', 'skipped'):
            node = case.find(tag)
            if node is not None:
                status = {'failure': 'failed', 'error': 'error', 'skipped': 'skipped'}[tag]
                message = (node.get('message') or node.text or '').strip().splitlines()[0:1]
                message = message[0][:200] if message else ''
                break
        cases.append({'class': cls, 'short': cls.rsplit('\\', 1)[-1], 'name': case.get('name', ''),
                      'file': case.get('file', ''), 'assertions': int(case.get('assertions') or 0),
                      'time': float(case.get('time') or 0), 'status': status, 'message': message})
    return cases


def read_list(path):
    items = []
    for line in open(path, encoding='utf-8'):
        line = line.split('#', 1)[0].strip()
        if line:
            items.append(line)
    return items


def check_required(cases, required):
    rows, problems = [], []
    for item in required:
        cls, _, method = item.partition('::')
        hit = [c for c in cases if c['short'] == cls and (not method or c['name'] == method
                                                           or c['name'].startswith(method + ' '))]
        count = {s: sum(1 for c in hit if c['status'] == s) for s in ('passed', 'failed', 'error', 'skipped')}
        ok = bool(hit) and count['failed'] == count['error'] == count['skipped'] == 0
        rows.append({'test': item, 'run': len(hit), **count, 'assertions': sum(c['assertions'] for c in hit),
                     'ok': ok})
        if not hit:
            problems.append(f'{item}: did not run')
        elif not ok:
            problems.append(f'{item}: {count["failed"]} failed, {count["error"]} errors, {count["skipped"]} skipped')
    return rows, problems


def behat_summary(path):
    lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
    summary = [l.strip() for l in lines if re.search(r'^\s*\d+ (scenarios?|steps?) \(', l)]
    reruns = [l.strip() for l in lines if re.search(r'rerun', l, re.I)][:20]
    failed = [l.strip() for l in lines if re.search(r'^\s*(Failed|Skipped) scenarios:', l)]
    return {'summary': summary, 'reruns': reruns, 'failed_sections': failed}


def check_features(required, directory, tag):
    rows, problems = [], []
    for name in required:
        path = os.path.join(directory, name if name.endswith('.feature') else name + '.feature')
        if not os.path.isfile(path):
            rows.append({'feature': name, 'ok': False, 'note': 'missing'})
            problems.append(f'{name}: feature file missing')
            continue
        text = open(path, encoding='utf-8').read()
        tags = set(re.findall(r'@[\w-]+', '\n'.join(l for l in text.splitlines() if l.strip().startswith('@'))))
        scenarios = len(re.findall(r'^\s*Scenario( Outline)?:', text, re.M))
        examples = len(re.findall(r'^\s*\|', text, re.M))
        skip = sorted(t for t in tags if t in ('@skip', '@_skip', '@wip'))
        ok = tag in tags and not skip
        note = f'{scenarios} scenario(s)' + (f'; skip tags {skip}' if skip else '') + ('' if tag in tags else f'; no {tag}')
        rows.append({'feature': name, 'ok': ok, 'note': note, 'scenarios': scenarios, 'table_rows': examples})
        if not ok:
            problems.append(f'{name}: {note}')
    return rows, problems


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--title', required=True)
    parser.add_argument('--moodle', required=True)
    parser.add_argument('--db', default='')
    parser.add_argument('--component', action='append', default=[])
    parser.add_argument('--junit')
    parser.add_argument('--required')
    parser.add_argument('--behat-log')
    parser.add_argument('--required-features')
    parser.add_argument('--features-dir')
    parser.add_argument('--tag', default='')
    parser.add_argument('--out', required=True)
    args = parser.parse_args()

    os.makedirs(args.out, exist_ok=True)
    record = {
        'title': args.title,
        'run': {k: os.environ.get(v, '') for k, v in (('repository', 'GITHUB_REPOSITORY'), ('sha', 'GITHUB_SHA'),
                                                        ('ref', 'GITHUB_REF_NAME'), ('run_id', 'GITHUB_RUN_ID'),
                                                        ('attempt', 'GITHUB_RUN_ATTEMPT'), ('job', 'GITHUB_JOB'))},
        'moodle': moodle_info(args.moodle),
        'php': run(['php', '-r', 'echo PHP_VERSION;']),
        'database': db_info(args.db),
        'components': [component_info(*c.split('=', 1)) for c in args.component],
    }
    problems = []
    md = [f'## Evidence: {args.title}', '',
          f'Run `{record["run"]["run_id"]}` attempt {record["run"]["attempt"] or "?"} of '
          f'`{record["run"]["repository"]}` @ `{record["run"]["sha"]}` ({record["run"]["ref"]})', '',
          '| Moodle | PHP | Database |', '|---|---|---|',
          f'| {record["moodle"]["release"]} ({record["moodle"]["version"]}) | {record["php"]} | {record["database"]} |',
          '', '| Component | Commit | Version | Ref |', '|---|---|---|---|']
    for c in record['components']:
        md.append(f'| {c["component"]} | `{c["sha"]}` | {c["version"]} {c["release"]} | {c["ref"]} |')

    if args.junit:
        if not os.path.isfile(args.junit):
            problems.append(f'JUnit file {args.junit} missing - the test step did not run to the end')
            cases = []
        else:
            cases = parse_junit(args.junit)
        totals = {s: sum(1 for c in cases if c['status'] == s) for s in ('passed', 'failed', 'error', 'skipped')}
        totals['tests'] = len(cases)
        totals['assertions'] = sum(c['assertions'] for c in cases)
        record['phpunit'] = {'totals': totals,
                             'skipped': [{k: c[k] for k in ('class', 'name', 'message')}
                                         for c in cases if c['status'] == 'skipped'],
                             'failed': [{k: c[k] for k in ('class', 'name', 'message')}
                                        for c in cases if c['status'] in ('failed', 'error')]}
        md += ['', '### PHPUnit', '', '| Tests | Assertions | Passed | Failed | Errors | Skipped/incomplete |',
               '|---:|---:|---:|---:|---:|---:|',
               f'| {totals["tests"]} | {totals["assertions"]} | {totals["passed"]} | {totals["failed"]} | '
               f'{totals["error"]} | {totals["skipped"]} |']
        if record['phpunit']['skipped']:
            md += ['', 'Skipped or incomplete (not among the required tests unless listed below):', '']
            md += [f'- `{s["class"]}::{s["name"]}` – {s["message"]}' for s in record['phpunit']['skipped']]
        if args.required:
            rows, missing = check_required(cases, read_list(args.required))
            record['phpunit']['required'] = rows
            problems += missing
            md += ['', '#### Required tests (issues)', '', '| Test | Run | Passed | Failed | Errors | Skipped | Assertions | |',
                   '|---|---:|---:|---:|---:|---:|---:|---|']
            md += [f'| `{r["test"]}` | {r["run"]} | {r["passed"]} | {r["failed"]} | {r["error"]} | {r["skipped"]} | '
                   f'{r["assertions"]} | {"✔" if r["ok"] else "✘"} |' for r in rows]

    if args.behat_log:
        if os.path.isfile(args.behat_log):
            record['behat'] = behat_summary(args.behat_log)
            md += ['', '### Behat', '', 'Summary lines of the run (a rerun adds its own):', '']
            md += [f'- `{l}`' for l in record['behat']['summary']] or ['- no summary line found']
            if record['behat']['reruns']:
                md += ['', 'Rerun lines:', ''] + [f'- `{l}`' for l in record['behat']['reruns']]
        else:
            problems.append(f'Behat log {args.behat_log} missing')
        if args.required_features and args.features_dir:
            rows, missing = check_features(read_list(args.required_features), args.features_dir, args.tag)
            record.setdefault('behat', {})['required'] = rows
            problems += missing
            md += ['', '#### Required features (issues)', '', '| Feature | | Note |', '|---|---|---|']
            md += [f'| `{r["feature"]}` | {"✔" if r["ok"] else "✘"} | {r["note"]} |' for r in rows]

    record['problems'] = problems
    md += ['', '### Verdict', '']
    md += ['All required tests ran, passed and were not skipped.'] if not problems else [f'- ✘ {p}' for p in problems]
    text = '\n'.join(md) + '\n'
    open(os.path.join(args.out, 'evidence.md'), 'w', encoding='utf-8').write(text)
    json.dump(record, open(os.path.join(args.out, 'evidence.json'), 'w', encoding='utf-8'), indent=2)
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        open(os.environ['GITHUB_STEP_SUMMARY'], 'a', encoding='utf-8').write(text)
    print(text)
    for p in problems:
        print(f'::error::{p}')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
