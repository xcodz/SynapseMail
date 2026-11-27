#!/usr/bin/env python3
# SynapseMail: writes app/SYNAPSEMAIL-FILES.txt by diffing app/ against a pristine Roundcube "complete" tree, and
# tools/roundcube-files.txt (every pristine file with its SHA-256) for the release build's file check.
# Usage: tools/synapsemail-files.py /path/to/roundcubemail-1.7.7
import hashlib, re, subprocess, sys, os
if len(sys.argv) != 2:
    sys.exit('usage: synapsemail-files.py <pristine roundcube dir>')
here = os.path.dirname(os.path.abspath(__file__)); app = os.path.join(here, '..', 'app'); base = sys.argv[1].rstrip('/')
version = re.search(r"RCMAIL_VERSION', '([^']+)'", open(os.path.join(base, 'program/include/iniset.php')).read()).group(1)
out = subprocess.run(['diff', '-rq', base, app], capture_output=True, text=True).stdout.splitlines()
skip = re.compile(r'^(config/(synapse\.inc\.php|config\.local\.inc\.php|ADMIN-URL\.txt|installed\.lock)|db/.*\.db.*|logs/.+|temp/.+|setup\.key|plugins/synapse_demo(/.*)?|.*\.DS_Store)$')
added, changed, removed = [], [], []
for line in out:
    for kind, pat in (('added', r'Only in ' + re.escape(app) + r'/?(.*): (.*)'), ('removed', r'Only in ' + re.escape(base) + r'/?(.*): (.*)')):
        m = re.match(pat, line)
        if m:
            p = (m.group(1) + '/' if m.group(1) else '') + m.group(2)
            if not (kind == 'added' and skip.match(p)):
                (added if kind == 'added' else removed).append(p)
            break
    else:
        m = re.match(r'Files ' + re.escape(base) + r'/(.*) and .* differ', line)
        if m:
            changed.append(m.group(1))
# documents kept at the repo root and copied into the package by tools/build_release.sh
for doc in ('SECURITY.md', 'CHANGELOG-SYNAPSEMAIL.md', 'LICENSE-SYNAPSEMAIL.txt'):
    if doc not in added:
        added.append(doc)
if 'README.md' in removed:
    removed.remove('README.md')
    changed.append('README.md')
with open(os.path.join(app, 'SYNAPSEMAIL-FILES.txt'), 'w') as f:
    f.write(f'# SynapseMail files on top of Roundcube Webmail {version} (complete package)\n'
            '# Everything not listed here is unmodified Roundcube (GPL-3.0, see README-ROUNDCUBE.md and docs/LICENSE.md).\n'
            '# Used to build the overlay and WHM editions. Regenerate: tools/synapsemail-files.py <pristine roundcube dir>\n\n'
            '[added]\n' + '\n'.join(sorted(added)) + '\n\n[changed]\n' + '\n'.join(sorted(changed)) + '\n\n[removed]\n' + '\n'.join(sorted(removed)) + '\n')
print(f'{len(added)} added, {len(changed)} changed, {len(removed)} removed (Roundcube {version})')
# pinned Roundcube files: the release build refuses unlisted files and unmodified Roundcube files that differ
rows = []
for d, _, names in os.walk(base):
    for n in names:
        full = os.path.join(d, n)
        rel = os.path.relpath(full, base)
        if os.path.isfile(full) and not os.path.islink(full):
            h = '-' if rel in changed else hashlib.sha256(open(full, 'rb').read()).hexdigest()
            rows.append((rel, h))
with open(os.path.join(here, 'roundcube-files.txt'), 'w') as f:
    f.write(f'# Roundcube Webmail {version} (complete package): the files SynapseMail ships unmodified, with their SHA-256.\n'
            '# Paths are relative to app/. "-" marks a Roundcube file SynapseMail changes ([changed] in app/SYNAPSEMAIL-FILES.txt).\n'
            '# tools/build_release.sh refuses a package with a file in neither list, a listed file missing, or an unmodified\n'
            '# Roundcube file that differs from its hash here. Regenerate on a Roundcube upgrade:\n'
            '#   tools/synapsemail-files.py <pristine roundcube dir>   (writes this file and app/SYNAPSEMAIL-FILES.txt)\n'
            f'roundcube {version}\n' + ''.join(f'{h}  {p}\n' for p, h in sorted(rows)))
print(f'{len(rows)} Roundcube files pinned in tools/roundcube-files.txt')
