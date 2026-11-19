"""Prints the functions that take the most self time in an Xdebug cachegrind file (profile mode), as a share of the total."""
import re, sys, collections

def parse(path):
    self_cost = collections.Counter()
    calls = collections.Counter()
    fn = None
    skip_next = False
    names = {}
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
            skip_next = True
            calls[fn] += int(line.split('=')[1].split()[0])
            continue
        if line and (line[0].isdigit()):
            if skip_next:          # the cost line after calls= belongs to the callee, not to this function
                skip_next = False
                continue
            parts = line.split()
            if len(parts) >= 2:
                self_cost[fn] += int(parts[1])
    return self_cost, calls

if __name__ == '__main__':
    cost, calls = parse(sys.argv[1])
    total = sum(cost.values()) or 1
    limit = int(sys.argv[2]) if len(sys.argv) > 2 else 30
    print(f'{"self %":>7} {"calls":>9}  function')
    for f, c in cost.most_common(limit):
        print(f'{100 * c / total:7.2f} {calls[f]:9d}  {f}')
