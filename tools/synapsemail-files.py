#!/usr/bin/env python3
# SynapseMail: writes app/SYNAPSEMAIL-FILES.txt by diffing app/ against a pristine Roundcube "complete" tree.
# Usage: tools/synapsemail-files.py /path/to/roundcubemail-1.7.5
import re, subprocess, sys, os
if len(sys.argv) != 2:
    sys.exit('usage: synapsemail-files.py <pristine roundcube dir>')
here = os.path.dirname(os.path.abspath(__file__)); app = os.path.join(here, '..', 'app'); base = sys.argv[1].rstrip('/')
version = re.search(r"RCMAIL_VERSION', '([^']+)'", open(os.path.join(base, 'program/include/iniset.php')).read()).group(1)
out = subprocess.run(['diff', '-rq', base, app], capture_output=True, text=True).stdout.splitlines()
skip = re.compile(r'^(config/(synapse\.inc\.php|config\.local\.inc\.php|ADMIN-URL\.txt|installed\.lock)|db/.*\.db.*|logs/.+|temp/.+|setup\.key|.*\.DS_Store)$')
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
