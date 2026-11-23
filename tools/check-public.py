"""Release gate: scan publishable files and EVERY reachable Git blob. No values printed."""
import argparse
import hashlib
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])


# Release identities are allowed only in verified author/committer email fields.
RELEASE_035 = '75271a4d53e62dad75f0f836012c64478c58a00d'
RELEASE_035_PARENT = '6b898759aeab14265d366e221847a8b3b1963f20'
RELEASE_036 = 'd2a733de24ad8dc64ebabfc335ec5a40beef09ee'
# Owner-approved identities in already-published commits; never a domain allowlist.
PUBLISHED_IDENTITIES = {
    '1e54d82770cbcd4c55c05bbf0b1834d9f98f934f': (
        '50d8112f11758cb48ce58ab4a344a9d8c7ec6b65', b'Shoxrux',
        '0fafc21188c42cb180d371c2ee6f25bb3b28bfdf133dfd2f4a91b18d477386d6'),
    '9e8658540274a06e319ab5e086b1f22135a9ad8f': (
        '1e54d82770cbcd4c55c05bbf0b1834d9f98f934f', b'Shoxrux',
        '0fafc21188c42cb180d371c2ee6f25bb3b28bfdf133dfd2f4a91b18d477386d6'),
}
EMAIL_DIGEST = '0fafc21188c42cb180d371c2ee6f25bb3b28bfdf133dfd2f4a91b18d477386d6'
MERGE_042 = '1f4536be6f97af093fdb50483fd934a4a9abf59c'
MERGE_042_PARENTS = (
    '9e8658540274a06e319ab5e086b1f22135a9ad8f',
    '2b79df7191ffc8e7ae84dcf304f7fff8a5e3c55f',
)
GITHUB_COMMITTER_DIGEST = '3c205d8fc749f72977b9331e3179773c315bb1f4860c366de2abe9ec9337730b'
REPAIR_MESSAGE = b'fix: verify SFP merge commit metadata'

def release_email(value):
    return hashlib.sha256(value).hexdigest() == EMAIL_DIGEST


def commit_metadata(sha, approved_sha):
    raw = git('cat-file', 'commit', sha)
    headers, separator, message = raw.partition(b'\n\n')
    lines = headers.split(b'\n')
    actual_parents = [line[7:] for line in lines if line.startswith(b'parent ')]
    parents = {RELEASE_035: RELEASE_035_PARENT, RELEASE_036: RELEASE_035}
    published = PUBLISHED_IDENTITIES.get(sha)
    merge = sha == MERGE_042
    # One bounded scanner-repair commit may use the already-approved owner's
    # identity when the GitHub connector cannot set a noreply author.
    repair = (
        sha not in parents and not published and not merge and sha != approved_sha
        and message.strip() == REPAIR_MESSAGE
        and actual_parents == [MERGE_042.encode()]
        and git('diff-tree', '--no-commit-id', '--name-only', '-r', sha).splitlines()
            == [b'tools/check-public.py']
    )
    if sha not in parents and sha != approved_sha and not published and not merge and not repair:
        return raw
    if published or merge or repair:
        # Check against the actual raw commit object, never just displayed metadata.
        object_header = b'commit ' + str(len(raw)).encode() + b'\0'
        if hashlib.sha1(object_header + raw).hexdigest() != sha:
            raise ValueError('Unexpected original commit content')

    if merge:
        expected_parents = [p.encode() for p in MERGE_042_PARENTS]
        identities = {
            b'author': (b'Shoxrux', EMAIL_DIGEST),
            b'committer': (b'GitHub', GITHUB_COMMITTER_DIGEST),
        }
    elif repair:
        expected_parents = [MERGE_042.encode()]
        identities = {
            b'author': (b'Shoxrux', EMAIL_DIGEST),
            b'committer': (b'Shoxrux', EMAIL_DIGEST),
        }
    elif published:
        expected_parent, name, email_digest = published
        expected_parents = [expected_parent.encode()]
        identities = {
            b'author': (name, email_digest),
            b'committer': (name, email_digest),
        }
    else:
        expected_parents = [parents.get(sha, RELEASE_036).encode()]
        identities = {
            b'author': (b'Shoxrux Soleyev', EMAIL_DIGEST),
            b'committer': (b'Shoxrux Soleyev', EMAIL_DIGEST),
        }

    if actual_parents != expected_parents:
        raise ValueError('Unexpected release parent for identity approval')
    for field in (b'author', b'committer'):
        indices = [i for i, line in enumerate(lines) if line.startswith(field + b' ')]
        if len(indices) != 1:
            raise ValueError('Unexpected identity headers')
        i = indices[0]
        expected_name, expected_email = identities[field]
        match = re.fullmatch(
            field + b' ' + re.escape(expected_name) + rb' <([^<>\s]+)> [0-9]+ [+-][0-9]{4}',
            lines[i],
        )
        if not match or hashlib.sha256(match[1]).hexdigest() != expected_email:
            raise ValueError('Unexpected release identity')
        lines[i] = lines[i][:match.start(1)] + lines[i][match.end(1):]
    return b'\n'.join(lines) + separator + message

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
            if not any(release_email(addr.lower().encode()) for addr in re.findall(pattern, text)):
                continue
        if re.search(pattern, text):
            problems.append(kind)
    return [(name, kind) for kind in problems]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--identity-commit', help='Exact full SHA approved for the v0.3.7 release identity')
    approved = parser.parse_args().identity_commit
    if approved and not re.fullmatch('[0-9a-f]{40}', approved):
        parser.error('Expected a full lowercase SHA')
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
        reachable = git('rev-list', '--all').decode().splitlines()
        if approved and approved not in reachable:
            parser.error('Approved commit is not reachable')
        for sha in reachable:
            errors.extend(check('git-metadata', commit_metadata(sha, approved)))
    for name, kind in sorted(set(errors)):
        print(f'BLOCKED {kind}: {name}')
    print(f'Files: {len(list(filter(None, files)))}; commits: {commits}; history blobs: {blobs}; findings: {len(errors)}')
    print('Heuristic scan; review diff, identities, attachments and dependency attribution manually too.')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())
