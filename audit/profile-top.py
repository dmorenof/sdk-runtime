"""Summarizes an Xdebug cachegrind file (profile mode): self time by group, and self and inclusive time of the functions
that matter, as a share of the total. Usage: profile-top.py FILE [N]"""
import re, sys, collections

def parse(path):
    selfc = collections.Counter()
    incl = collections.Counter()
    names = {}
    fn = None
    callee_next = False
    for line in open(path, errors='ignore'):
        line = line.rstrip('\n')
        if line.startswith('fn='):
            m = re.match(r'fn=\((\d+)\)(?: (.*))?', line)
            if m:
                if m.group(2):
                    names[m.group(1)] = m.group(2)
                fn = names.get(m.group(1), m.group(1))
            continue
        if line.startswith('calls='):
            callee_next = True
            continue
        if line and line[0].isdigit():
            parts = line.split()
            if len(parts) < 2:
                continue
            cost = int(parts[1])
            if callee_next:          # inclusive cost of a call made by fn
                incl[fn] += cost
                callee_next = False
            else:
                selfc[fn] += cost
    for f in selfc:
        incl[f] += selfc[f]
    return selfc, incl

def group(name):
    if name.startswith('php::'):
        return 'PHP built-ins'
    for prefix, label in (('GuzzleHttp\\', 'Guzzle'), ('Psr\\', 'Guzzle'), ('Dmorenof\\SdkRuntime\\', 'runtime'),
                          ('TypedArray\\', 'typedarray'), ('Ours\\', 'generated SDK'), ('CasafariSDK\\', 'generated SDK')):
        if name.startswith(prefix):
            return label
    return 'other'

if __name__ == '__main__':
    selfc, incl = parse(sys.argv[1])
    limit = int(sys.argv[2]) if len(sys.argv) > 2 else 30
    total = sum(selfc.values()) or 1
    groups = collections.Counter()
    for f, c in selfc.items():
        groups[group(f)] += c
    print('Self time by group:')
    for g, c in groups.most_common():
        print(f'  {100 * c / total:6.2f} %  {g}')
    print()
    print(f'{"self %":>7} {"incl %":>7}  function (runtime and generated code only)')
    ours = [f for f in selfc if group(f) in ('runtime', 'typedarray', 'generated SDK')]
    for f in sorted(ours, key=lambda x: -incl[x])[:limit]:
        print(f'{100 * selfc[f] / total:7.2f} {100 * incl[f] / total:7.2f}  {f}')
