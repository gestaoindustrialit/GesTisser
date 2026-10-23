#!/usr/bin/env python3
"""Validate PHP 7.0 syntax and standalone tests in disposable source copies."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('PHP70_BIN', 'php'))
    parser.add_argument('tests', nargs='*', help='Names of tests/*_test.php; default: all')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    try:
        probe = subprocess.run([args.php, '-r', 'echo PHP_VERSION;'],
                               capture_output=True, text=True, check=True)
    except (OSError, subprocess.CalledProcessError) as error:
        parser.exit(2, 'PHP 7.0 unavailable: {}\n'.format(error))
    version = probe.stdout.strip()
    if not re.fullmatch(r'7\.0\.\d+(?:[-+].*)?', version):
        parser.exit(2, 'PHP 7.0 required; detected {}. No validation performed.\n'.format(version))
    # Resolve before changing cwd; allow both a PATH command and an explicit path.
    php = str(Path(shutil.which(args.php) or args.php).resolve())
    files = subprocess.check_output(['git', 'ls-files', '--cached', '--others',
                                     '--exclude-standard', '-z'], cwd=root).decode().split('\0')
    files = sorted({name for name in files if name and
                    not name.startswith(('work/', 'vendor/', 'node_modules/')) and
                    (root / name).is_file()})
    available = sorted(name for name in files if name.startswith('tests/') and
                       name.endswith('_test.php'))
    selected = ['tests/' + Path(name).name for name in args.tests] if args.tests else available
    for name in selected:
        if name not in available:
            parser.exit(2, 'Unknown test: {}\n'.format(name))
    results = []
    lint_failures = []
    print('Runtime: PHP {}'.format(version), flush=True)
    with tempfile.TemporaryDirectory(prefix='gestisser-php70-') as directory:
        source = Path(directory) / 'source'
        source.mkdir()
        for name in files:
            destination = source / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(root / name, destination)
        if (root / 'vendor').is_dir():
            (source / 'vendor').symlink_to((root / 'vendor').resolve(), target_is_directory=True)
        php_files = [name for name in files if name.endswith('.php')]
        for name in php_files:
            result = subprocess.run([php, '-l', str(source / name)],
                                    capture_output=True, text=True)
            if result.returncode:
                lint_failures.append({'file': name, 'output': result.stdout + result.stderr})
                print('SYNTAX FAILED: {}'.format(name), flush=True)
        print('PHP 7.0 syntax: {} passed, {} failed'.format(
            len(php_files) - len(lint_failures), len(lint_failures)), flush=True)
        for name in selected:
            # config.php also supports a legacy database path relative to source.
            for database in source.glob('database.sqlite*'):
                database.unlink()
            with tempfile.TemporaryDirectory(prefix='gestisser-test-') as database_dir:
                env = dict(os.environ, APP_ENV='development',
                           GESTISSER_DB_PATH=database_dir + '/database.sqlite')
                command = [php, str(source / name)]
                fixture_output = ''
                data_source = 'standalone'
                if name in ('tests/historical_import_current_database_test.php', 'tests/material_profile_test.php'):
                    audit_path = str(Path(database_dir) / 'audit.sqlite')
                    fixture_env = dict(env, GESTISSER_TEST_FIXTURE_ROOT=str(source))
                    fixture = subprocess.run([php, str(source / 'tests/support/create_audit_fixture.php')],
                                             cwd=source, env=fixture_env, capture_output=True, text=True, timeout=90)
                    fixture_output = fixture.stdout + fixture.stderr
                    if fixture.returncode or not (source / 'database.sqlite').is_file() or re.search(r'Fatal error|Uncaught |Erro interno \(500\)', fixture_output):
                        results.append({'test': name, 'status': 'failed', 'exit_code': fixture.returncode,
                                        'data_source': 'synthetic', 'output': fixture_output})
                        print('FAILED (fixture): {}\n{}'.format(name, fixture_output[-1800:]), flush=True)
                        continue
                    original = sqlite3.connect((source / 'database.sqlite').as_uri() + '?mode=ro', uri=True)
                    snapshot = sqlite3.connect(audit_path)
                    try:
                        original.backup(snapshot)
                    finally:
                        original.close()
                        snapshot.close()
                    data_source = 'synthetic'
                    env['HISTORICAL_TEST_DB'] = audit_path
                    if name == 'tests/material_profile_test.php':
                        command.append(audit_path)
                try:
                    result = subprocess.run(command, cwd=source, env=env,
                                            capture_output=True, text=True, timeout=90)
                    output = result.stdout + result.stderr
                    passed = result.returncode == 0 and not re.search(
                        r'Fatal error|Uncaught |Erro interno \(500\)|FAIL(?:ED)?\b', output)
                    status = 'passed' if passed else 'failed'
                    if passed and re.search(r'\bSKIP(?:PED)?\b', output, re.IGNORECASE):
                        status = 'skipped'
                    code = result.returncode
                except subprocess.TimeoutExpired:
                    status, code, output = 'failed', None, 'Test timed out after 90 seconds.'
                results.append({'test': name, 'status': status, 'exit_code': code,
                                'data_source': data_source, 'fixture_output': fixture_output, 'output': output})
                print('{}: {}'.format(status.upper(), name), flush=True)
                if status == 'failed':
                    print(output[-1800:], flush=True)
    report_dir = root / 'work' / 'php70'
    report_dir.mkdir(parents=True, exist_ok=True)
    counts = {status: sum(item['status'] == status for item in results)
              for status in ('passed', 'failed', 'skipped')}
    report = {'php_executable': php, 'php_version': version, 'counts': counts,
              'lint': {'checked': len(php_files), 'failures': lint_failures}, 'results': results}
    report_path = report_dir / 'results.json'
    report_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    print(json.dumps({'counts': counts, 'syntax_failures': len(lint_failures),
                      'report': str(report_path)}, indent=2))
    return 1 if lint_failures or counts['failed'] else 0


if __name__ == '__main__':
    sys.exit(main())
