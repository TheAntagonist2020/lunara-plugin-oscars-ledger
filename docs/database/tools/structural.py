import json, re, collections, unicodedata
S='/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
R=json.load(open(f'{S}/rows.json'))
issues=collections.defaultdict(list)
def add(kind,row,detail): issues[kind].append({'row':row,'detail':detail})
def split(v): return [] if v in (None,'') else str(v).split('|')
cols=['Ceremony','Year','Class','CanonicalCategory','Category','Film','FilmId','Name','Nominees','NomineeIds','Winner','Detail','Note','Citation']
seen=collections.Counter()
for n,r in enumerate(R, start=2):  # spreadsheet row number
    # encoding artifacts
    for c in cols:
        v=r[c]
        if isinstance(v,str):
            if '\\"' in v or "\\'" in v: add('backslash_escape',n,f'{c}: {v[:120]}')
            if re.search(r'Ã.|â€|Â ', v): add('mojibake',n,f'{c}: {v[:120]}')
            if v!=v.strip(): add('edge_whitespace',n,f'{c}: {v!r}'[:140])
            if '  ' in v: add('double_space',n,f'{c}: {v[:120]}')
            if re.search(r'\|\s*\||^\||\|$', v): add('empty_pipe_slot',n,f'{c}: {v[:120]}')
            if unicodedata.normalize('NFC',v)!=v: add('non_nfc',n,f'{c}: {v[:80]}')
    films=split(r['Film']); fids=split(r['FilmId'])
    if films or fids:
        if len(films)!=len(fids): add('film_id_count_mismatch',n,f"{len(films)} titles vs {len(fids)} ids: {r['Film']} | {r['FilmId']}")
    for t in fids:
        if t!='?' and not re.fullmatch(r'tt\d{7,8}',t): add('film_id_not_title',n,t)
    noms=split(r['Nominees']); nids=split(r['NomineeIds'])
    if nids and len(noms)!=len(nids): add('nominee_id_count_mismatch',n,f"{len(noms)} names vs {len(nids)} ids: {r['Nominees']} | {r['NomineeIds']}")
    for t in nids:
        if ',' in t: add('comma_joined_ids',n,f"{t} for {r['Nominees']}")
    if r['FilmId'] and '?' in split(r['FilmId']): add('film_placeholder',n,f"{r['Film']} | {r['FilmId']}")
    if '?' in nids: add('nominee_placeholder',n,f"{r['Nominees']} | {r['NomineeIds']}")
    if r['Winner'] not in (None,1): add('winner_value',n,repr(r['Winner']))
    key=tuple(r[c] for c in cols)
    seen[key]+=1
    if seen[key]==2: add('exact_duplicate_row',n,f"{r['Ceremony']} {r['Category']} {r['Film']} {r['Name']}")
# ceremony/year
cy=collections.defaultdict(set)
for r in R: cy[r['Ceremony']].add(r['Year'])
for c,ys in sorted(cy.items()):
    if len(ys)!=1: add('ceremony_multi_year',0,f'{c}: {sorted(ys)}')
years=[(c,sorted(ys)[0]) for c,ys in sorted(cy.items())]
for (c1,y1),(c2,y2) in zip(years,years[1:]):
    a=int(y1[:4]); b=int(y2[:4])
    if b!=a+1 and not (c1<=6): add('year_gap',0,f'{c1}:{y1} -> {c2}:{y2}')
# canonical category -> class and category names
cc=collections.defaultdict(set)
for r in R: cc[r['CanonicalCategory']].add(r['Class'])
for k,v in cc.items():
    if len(v)>1: add('canonical_multi_class',0,f'{k}: {v}')
# winners per ceremony-category
wc=collections.Counter(); tot=collections.Counter()
for r in R:
    k=(r['Ceremony'],r['Category']); tot[k]+=1; wc[k]+= 1 if r['Winner']==1 else 0
for k in tot:
    if wc[k]==0 and tot[k]>0: add('category_without_winner',0,f'ceremony {k[0]} {k[1]} ({tot[k]} rows)')
# cross-row id->label consistency (nominees)
lab=collections.defaultdict(collections.Counter); flab=collections.defaultdict(collections.Counter)
for n,r in enumerate(R, start=2):
    noms=split(r['Nominees']); nids=split(r['NomineeIds'])
    if len(noms)==len(nids):
        for a,b in zip(noms,nids):
            if b and b!='?' and ',' not in b: lab[b][a.strip()]+=1
    films=split(r['Film']); fids=split(r['FilmId'])
    if len(films)==len(fids):
        for a,b in zip(films,fids):
            if b and b!='?': flab[b][a.strip()]+=1
def normname(s): return re.sub(r'[^a-z0-9]','',unicodedata.normalize('NFKD',s).encode('ascii','ignore').decode().lower())
for i,c in lab.items():
    if len({normname(x) for x in c})>1: add('id_multiple_labels',0,f'{i}: {dict(c)}')
for i,c in flab.items():
    if len({normname(x) for x in c})>1: add('title_id_multiple_titles',0,f'{i}: {dict(c)}')
# same label, multiple ids
rev=collections.defaultdict(set)
for i,c in lab.items():
    for x in c: rev[normname(x)].add(i)
for k,v in rev.items():
    if len(v)>1 and k: add('label_multiple_ids',0,f'{k}: {sorted(v)}')
json.dump(issues, open(f'{S}/structural_issues.json','w'), indent=1)
for k,v in sorted(issues.items(), key=lambda x:-len(x[1])): print(f'{len(v):6d}  {k}')
