"""Assemble the corrections ledger from verified findings.

Only three kinds of change are allowed in:
  encoding     every literal backslash-quote (\\") becomes a plain quote.
               Rule-based: the source uses \\" for every quote (194 occurrences,
               0 other backslashes), so the rule cannot misfire.
  credit_text  a nominee slot that kept credit wording from the credit line
               ("Screenplay - ", "In collaboration with "), found by exhaustive scan,
               plus one stray-space typo ("Metro- Goldwyn-Mayer").
  wrong_id     an IMDb ID that identifies a different entity. Applied ONLY when
               both independent skeptics confirmed it. When the true ID is not
               proven, the slot becomes '?' (unlinked), never a guess.

Everything a skeptic refuted, or that split the two skeptics, goes to
needs_review.json instead, untouched.

    python assemble_corrections.py <out_dir>
"""
import json, glob, os, sys, re, copy

A = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
W = '/root/.claude/projects/-home-user/cffd3175-765f-5476-bc55-babd0243164e/subagents/workflows'
OUT = sys.argv[1]
R = json.load(open(f'{A}/rows.json'))
ROUNDS = {  # verification run -> the change list it was given
    'wf_d62fa668-8f8': f'{A}/changes_round1_slim.json',
    'wf_9c6d773e-b66': f'{A}/changes_round2_slim.json',
    'wf_fda8249f-7f6': f'{A}/changes_round3_slim.json',
}
extra = f'{A}/verify_rounds_extra.json'
if os.path.exists(extra):
    ROUNDS.update(json.load(open(extra)))


def split(v):
    return [] if v in (None, '') else str(v).split('|')


# Working copy with the encoding rule applied first, so later "before" values
# are compared against clean text.
rows = copy.deepcopy(R)
corrections = []


def add(nid, field, before, after, reason, evidence, verification, **extra_fields):
    corrections.append({'nomination_id': nid, 'field': field, 'before': before, 'after': after, 'reason': reason,
                        'evidence': evidence, 'verification': verification, **extra_fields})


# 1. encoding
for i, r in enumerate(rows, start=1):
    for f in ('Film', 'Name', 'Nominees', 'Detail', 'Note', 'Citation'):
        v = r.get(f)
        if isinstance(v, str) and '\\"' in v:
            new = v.replace('\\"', '"')
            add(i, f, v, new, 'encoding', 'The source escapes every double quote as \\" inside cells (194 occurrences in 84 cells; no other backslashes anywhere).', 'rule: exhaustive scan')
            r[f] = new

# 2. credit-text artifacts in nominee slots (exhaustive scan) + the stray-space typo
PREFIX = re.compile(r'^\s*(in collaboration with |screenplay\s*[-–:]\s*)', re.I)
for i, r in enumerate(rows, start=1):
    noms = split(r['Nominees'])
    changed = False
    for k, n in enumerate(noms):
        m = PREFIX.match(n)
        if m:
            noms[k] = n[m.end():].strip()
            changed = True
        if n.startswith('Metro- Goldwyn-Mayer'):
            noms[k] = n.replace('Metro- Goldwyn-Mayer', 'Metro-Goldwyn-Mayer')
            changed = True
    if changed:
        new = '|'.join(noms)
        add(i, 'Nominees', r['Nominees'], new, 'credit_text',
            'The nominee slot carried credit wording copied from the credit line (or a stray space); the credit line itself is unchanged.',
            'rule: exhaustive scan of all 18,824 nominee slots')
        r['Nominees'] = new
    if r['Name'] and 'Metro- Goldwyn-Mayer' in r['Name']:
        new = r['Name'].replace('Metro- Goldwyn-Mayer', 'Metro-Goldwyn-Mayer')
        add(i, 'Name', r['Name'], new, 'credit_text', 'Stray space after the hyphen in "Metro-Goldwyn-Mayer".', 'rule: exact string')
        r['Name'] = new

# 3. verified ID corrections
review = []
ADJ = json.load(open(f'{A}/adjudications.json')) if os.path.exists(f'{A}/adjudications.json') else {}
confirmed_changes = []                      # (key, change, verification)
for run, change_file in ROUNDS.items():
    J = f'{W}/{run}/journal.jsonl'
    if not os.path.exists(J) or not os.path.exists(change_file):
        continue
    changes = {f"{c['item_id']}|{c['imdb_id']}|{','.join(map(str, c['rows']))}": c for c in json.load(open(change_file))}
    checks = {}
    for line in open(J):
        d = json.loads(line)
        if d.get('type') == 'result':
            for c in d['result'].get('checks', []):
                checks.setdefault(c['key'], []).append(c)
    for key, ch in changes.items():
        votes = checks.get(key, [])
        confirmed = len(votes) >= 2 and all(not v['refuted'] for v in votes)
        verification = 'confirmed by two independent reviewers (source lens and ceremony-context lens)'
        adj = ADJ.get(key)
        if adj and adj['action'] == 'blank' and len(votes) >= 2:
            ch = dict(ch, correct_imdb_id=None, evidence=list(ch.get('evidence', [])) + ['Adjudication: ' + adj['note']])
            confirmed = True
            verification = 'original ID refuted by both independent reviewers; replacement unproven, so the slot is unlinked'
        if adj and adj['action'] == 'relink' and confirmed:
            ch = dict(ch, correct_imdb_id=adj['id'], evidence=list(ch.get('evidence', [])) + ['Adjudication: ' + adj['note']])
            verification = 'original ID refuted and replacement independently established by both reviewers'
        if not confirmed:
            review.append({'key': key, 'change': ch, 'votes': votes})
            continue
        confirmed_changes.append((key, ch, verification))

# One decision per (ID, rows): a proven replacement beats an unlinking; duplicates collapse.
best = {}
for key, ch, ver in confirmed_changes:
    k = (ch['imdb_id'], tuple(ch['rows']))
    if k not in best or (ch.get('correct_imdb_id') and not best[k][1].get('correct_imdb_id')):
        best[k] = (key, ch, ver)

for key, ch, verification in best.values():
    new_id = ch.get('correct_imdb_id') or '?'
    evidence = ' | '.join(ch.get('evidence', [])[:3])
    for sheet_row in ch['rows']:
        i = sheet_row - 1                       # nomination_id (1-based data row)
        r = rows[i - 1]
        field = 'FilmId' if ch['imdb_id'].startswith('tt') else 'NomineeIds'
        toks = split(r[field])
        hits = [k for k, t in enumerate(toks) if ch['imdb_id'] in t.split(',')]
        if ch['item_id'] == 'Q372':          # row 978: two slots swapped by upstream
            noms = split(r['Nominees'])
            a = next(k for k, t in enumerate(toks) if t == 'co0051159')
            b = next(k for k, n in enumerate(noms) if 'NATIONAL CARBON' in n.upper())
            before = r[field]
            toks[a], toks[b] = 'co0066582', 'co0051159'
            r[field] = '|'.join(toks)
            add(i, field, before, r[field], 'wrong_id', evidence + ' | Bausch & Lomb slot gets co0066582; the National Carbon slot gets co0051159.', verification, imdb_id=None)
            continue
        if ch['item_id'] == 'T035':          # row 8769: father's slot held the son's ID
            noms = split(r['Nominees'])
            before = r[field]
            sr = noms.index('Al Mayer Sr.'); jr = noms.index('Al Mayer Jr.')
            if toks[sr] != 'nm2353419' or toks[jr] != '?':
                review.append({'key': key, 'change': ch, 'problem': 'row 8769 no longer matches the verified state'})
                continue
            toks[sr], toks[jr] = 'nm4869190', 'nm2353419'
            r[field] = '|'.join(toks)
            add(i, field, before, r[field], 'wrong_id', evidence + ' | Al Mayer Sr. gets nm4869190; Al Mayer Jr. gets nm2353419.', verification, imdb_id='nm4869190')
            continue
        if len(hits) != 1:
            review.append({'key': key, 'change': ch, 'problem': f'row {sheet_row}: expected exactly one slot holding {ch["imdb_id"]}, found {len(hits)}'})
            continue
        before = r[field]
        parts = toks[hits[0]].split(',')
        parts = [new_id if p == ch['imdb_id'] else p for p in parts]
        toks[hits[0]] = ','.join(parts)
        r[field] = '|'.join(toks)
        add(i, field, before, r[field], 'wrong_id', evidence, verification, imdb_id=None if new_id == '?' else new_id)

os.makedirs(OUT, exist_ok=True)
json.dump(corrections, open(f'{OUT}/corrections.json', 'w'), indent=1, ensure_ascii=False)
json.dump(review, open(f'{OUT}/needs_review.json', 'w'), indent=1, ensure_ascii=False)
import collections
print('corrections', len(corrections), collections.Counter(c['reason'] for c in corrections))
print('needs review', len(review))
