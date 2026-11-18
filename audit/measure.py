import os,re,sys,hashlib,collections,json
def php_files(root):
    for d,_,fs in os.walk(root):
        for f in fs:
            if f.endswith('.php'): yield os.path.join(d,f)
def classify(root,p):
    r=os.path.relpath(p,root)
    if r.startswith(('test/','tests/')): return 'test'
    if r.startswith(('lib/','src/')): return 'src'
    return 'other'
def strip_comments(s):
    s=re.sub(r'/\*.*?\*/','',s,flags=re.S)
    return '\n'.join(re.sub(r'(^|\s)//.*$','',l) for l in s.split('\n'))
def measure(root):
    allfiles=[os.path.join(d,f) for d,_,fs in os.walk(root) for f in fs]
    out={'files_total':len(allfiles)}
    cat=collections.Counter(); loc=collections.Counter(); code=collections.Counter()
    for f in allfiles:
        r=os.path.relpath(f,root)
        if f.endswith('.php'):
            k=classify(root,f)
        elif r.startswith(('docs/','README')) or f.endswith('.md'): k='docs'
        else: k='config'
        txt=open(f,errors='ignore').read()
        cat[k]+=1; loc[k]+=txt.count('\n')+1
    out['files_by_kind']=dict(cat); out['lines_by_kind']=dict(loc)
    # source analysis
    src=[p for p in php_files(root) if classify(root,p)=='src']
    total_code=0;lines_hash=collections.Counter();untyped_params=0;params=0;props=0;untyped_props=0;classes=0;methods=0
    longest=0
    for p in src:
        s=open(p,errors='ignore').read()
        c=strip_comments(s)
        lines=[l.strip() for l in c.split('\n') if l.strip() and l.strip() not in ('{','}','<?php','?>','};')]
        total_code+=len(lines)
        for l in lines:
            if len(l)>=25: lines_hash[l]+=1
        classes+=len(re.findall(r'^\s*(?:abstract |final )?(?:class|interface|trait|enum)\s',c,re.M))
        for m in re.finditer(r'function\s+\w+\s*\((.*?)\)\s*(?::\s*[\w\\?|]+)?\s*[{;]',c,re.S):
            methods+=1
            sig=m.group(1)
            for prm in [x.strip() for x in re.split(r',(?![^(<\[]*[)>\]])',sig) if x.strip()]:
                params+=1
                if not re.match(r'^(?:public |private |protected |readonly )*\??[\w\\|&]+(?:\|[\w\\]+)*\s+(?:\.\.\.)?&?\$',prm) : untyped_params+=1
        for m in re.finditer(r'^\s*(?:public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?(?:(\??[\w\\|]+)\s+)?\$\w+',c,re.M):
            props+=1
            if not m.group(1): untyped_props+=1
        longest=max(longest,max([len(open(p,errors='ignore').read().split('\n'))],default=0))
    dup=sum(n for l,n in lines_hash.items() if n>1)
    uniq_lines=sum(1 for l,n in lines_hash.items() if n>1)
    big=sum(n for l,n in lines_hash.items())
    out.update(src_files=len(src),src_code_lines=total_code,classes=classes,methods=methods,
        params=params,untyped_params=untyped_params,props=props,untyped_props=untyped_props,
        repeated_line_instances=dup,long_lines_total=big,dup_pct=round(100*dup/max(1,big),1),largest_src_file_lines=longest)
    # largest files
    sizes=sorted(((len(open(p,errors='ignore').read().split('\n')),os.path.relpath(p,root)) for p in src),reverse=True)[:3]
    out['largest']=sizes
    return out
if __name__=='__main__':
    print(json.dumps(measure(sys.argv[1]),indent=1))
