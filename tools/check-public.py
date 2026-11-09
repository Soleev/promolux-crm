"""Release gate: scan publishable files and EVERY reachable Git blob. No values printed."""
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


def check(name, data):
    problems = []
    path = name.lower()
    parts = Path(path).parts
    allowed_env = path in ('.env.example', '.env.production.example')
    roots = {'app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests', 'storage', 'public', 'tools'}
    root_files = {'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'vite.config.js', '.editorconfig', '.gitattributes', '.gitignore', '.env.example', '.env.production.example', '.cpanel.yml', 'readme.md', 'agents.md'}
    public_docs = {'docs/business-rules.md', 'docs/releases.md', 'docs/deployment.md', 'docs/public-releases.md'}
    if name != 'git-metadata' and path not in root_files and path not in public_docs and (len(parts) < 2 or parts[0] not in roots):
        problems.append('file-outside-release-allowlist')
    if (any(x in parts for x in ('vendor', 'node_modules', '.git', '.agents', '.claude', 'backups', 'uploads', 'internal', 'audits'))
            or (Path(path).name.startswith('.env') and not allowed_env)
            or re.search(r'\.(sqlite\d*|db|sql|dump|log|bak|zip|7z|tar|gz|pem|key|pfx|pdf|docx|xlsx|csv)([-.]|$)', path)
            or path in ('auth.json', '.mcp.json', 'deploy.local.env')
            or (path.startswith('storage/') and not path.endswith('/.gitignore'))
            or (path.startswith('bootstrap/cache/') and not path.endswith('/.gitignore'))):
        problems.append('private-file')
    text = data.decode('utf-8', errors='replace')
    patterns = {
        'private-key': r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
        'github-token': r'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})',
        'aws-key': r'\bAKIA[0-9A-Z]{16}\b',
        'app-key': r'(?m)^APP_KEY=.{10,}',
        'environment-password': r'(?m)^(?:DB_PASSWORD|MAIL_PASSWORD|AWS_SECRET_ACCESS_KEY)=\S+',
        'personal-path': r'(?i)[A-Z]:[/\\](?:Users|HERD)[/\\]',
        'personal-email': r'(?i)[a-z0-9._%+-]+@(?:yandex\.[a-z]+|mail\.ru|gmail\.com|outlook\.com)',
    }
    for kind, pattern in patterns.items():
        # Dependency attribution belongs to upstream maintainers, not CRM users.
        if kind == 'personal-email' and name in ('composer.lock', 'package-lock.json'):
            continue
        if re.search(pattern, text):
            problems.append(kind)
    return [(name, kind) for kind in problems]


def main():
    files = git('ls-files', '--cached', '--others', '--exclude-standard', '-z').decode().split('\0')
    errors = []
    for name in filter(None, files):
        file = ROOT / name
        if file.is_symlink():
            errors.append((name, 'symlink'))
        elif file.is_file():
            errors.extend(check(name, file.read_bytes()))
    has_head = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', '--verify', 'HEAD'], capture_output=True).returncode == 0
    blobs = 0
    commits = 0
    if has_head:
        commits = int(git('rev-list', '--all', '--count'))
        for row in git('rev-list', '--objects', '--all').decode().splitlines():
            sha, _, name = row.partition(' ')
            if git('cat-file', '-t', sha).strip() == b'blob':
                blobs += 1
                errors.extend(check(name, git('cat-file', 'blob', sha)))
        errors.extend(check('git-metadata', git('log', '--all', '--format=%an <%ae>%n%cn <%ce>%n%B')))
    for name, kind in sorted(set(errors)):
        print(f'BLOCKED {kind}: {name}')
    print(f'Files: {len(list(filter(None, files)))}; commits: {commits}; history blobs: {blobs}; findings: {len(errors)}')
    print('Heuristic scan; review diff, identities, attachments and dependency attribution manually too.')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())
