"""Reconcile every dataset row with the Academy Awards Database.

    python reconcile.py <corrected.tsv> <ampas_dir> <out.json> [ceremony ...]

For each ceremony, pairs each dataset row with the Academy's record of the same
nomination, then reports every field on which they disagree:
  winner, category, films, statement (the credit line, dataset `Name`), detail
  (acting character, song title or Sci-Tech area), citation, and rows present
  on only one side.
Pairing is strict first (identical category, films and credit text), then
relaxed (case, spacing and punctuation ignored), then by similarity within the
ceremony. Every relaxed or similarity pairing is reported, so nothing is
silently treated as equal.
"""
import csv, json, os, re, sys, collections, unicodedata
from difflib import SequenceMatcher

TSV, AMPAS, OUT = sys.argv[1:4]
ONLY = set(int(x) for x in sys.argv[4:])
rows = list(csv.reader(open(TSV, encoding='utf-8', newline=''), delimiter='\t', quoting=csv.QUOTE_NONE))
H = rows[0]
D = [dict(zip(H, r), nomination_id=i) for i, r in enumerate(rows[1:], start=1)]


def split(v):
    return [] if not v else v.split('|')


def norm(s):
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()
    s = s.replace('&', ' and ')
    return re.sub(r'[^a-z0-9]+', ' ', s).strip()


def sim(a, b):
    return SequenceMatcher(None, norm(a), norm(b)).ratio()


def academy_detail(r):
    if r['kind'] == 'song':
        m = re.match(r'^"(.*?)"\s+from\s', r['text'] or '')
        return m.group(1) if m else None
    if r['kind'] == 'scitech':
        m = re.search(r'\[([^\]]+)\]\s*$', r['text'] or '')
        return m.group(1) if m else None
    chars = re.findall(r'\{"(.+?)"\}', r['text'] or '')
    if chars:
        return '|'.join(chars)
    return None


def ours(d):
    text = d['Name'] or d['Citation'] or ''
    return {'category': d['Category'], 'films': split(d['Film']), 'text': text, 'winner': d['Winner'] == 'True',
            'detail': d['Detail'] or None, 'citation': d['Citation'] or None, 'note': d['Note'] or None}


def theirs(r):
    text = r['statement'] if r['statement'] is not None else (r['citation'] or r['description'] or '')
    films = [re.sub(r'\s*;\s*$', '', f).strip() for f in r['films']]
    return {'category': r['category'], 'films': films, 'text': text, 'winner': r['winner'], 'kind': r['kind'],
            'detail': academy_detail(r), 'citation': r['citation'] or None, 'raw': r['text']}


def key(x, strict):
    f = tuple(x['films']) if strict else tuple(norm(t) for t in x['films'])
    c = x['category'] if strict else norm(x['category'])
    t = x['text'] if strict else norm(x['text'])
    return (c, f, t)


report = {'ceremonies': {}, 'differences': [], 'unmatched_dataset': [], 'unmatched_academy': []}
by_cer = collections.defaultdict(list)
for d in D:
    by_cer[int(d['Ceremony'])].append(d)

for c in sorted(by_cer):
    if ONLY and c not in ONLY:
        continue
    p = os.path.join(AMPAS, f'c{c}.json')
    if not os.path.exists(p):
        report['ceremonies'][c] = 'not fetched'
        continue
    A = json.load(open(p))['results']
    left = [(d, ours(d)) for d in by_cer[c]]
    right = [theirs(r) for r in A]
    pairs = []
    for strict in (True, False):
        idx = collections.defaultdict(list)
        for j, x in enumerate(right):
            if x is not None:
                idx[key(x, strict)].append(j)
        rest = []
        for d, o in left:
            js = idx.get(key(o, strict))
            if js:
                j = js.pop(0)
                pairs.append((d, o, right[j], 'strict' if strict else 'relaxed'))
                right[j] = None
            else:
                rest.append((d, o))
        left = rest
    # similarity pass: best remaining Academy record in the same ceremony
    cand = []
    for li, (d, o) in enumerate(left):
        for j, x in enumerate(right):
            if x is None:
                continue
            s_cat = 1.0 if norm(o['category']) == norm(x['category']) else sim(o['category'], x['category'])
            s_film = sim(' '.join(o['films']), ' '.join(x['films'])) if (o['films'] or x['films']) else 1.0
            s_text = sim(o['text'], x['text'])
            score = 0.25 * s_cat + 0.35 * s_film + 0.4 * s_text
            if score >= 0.6:
                cand.append((score, li, j))
    used_l, used_r = set(), set()
    for score, li, j in sorted(cand, reverse=True):
        if li in used_l or j in used_r:
            continue
        used_l.add(li); used_r.add(j)
        d, o = left[li]
        pairs.append((d, o, right[j], f'similar {score:.2f}'))
    for li, (d, o) in enumerate(left):
        if li not in used_l:
            report['unmatched_dataset'].append({'nomination_id': d['nomination_id'], 'ceremony': c, **o})
    for j, x in enumerate(right):
        if x is not None and j not in used_r:
            report['unmatched_academy'].append({'ceremony': c, **x})
    n_diff = 0
    for d, o, x, how in pairs:
        diffs = {}
        if o['winner'] != x['winner']:
            diffs['winner'] = [o['winner'], x['winner']]
        if o['category'] != x['category']:
            diffs['category'] = [o['category'], x['category']]
        if o['films'] != x['films'] and not (x['kind'] in ('honorary', 'scitech') and not x['films']):
            diffs['films'] = [o['films'], x['films']]
        if o['text'] != x['text']:
            diffs['text'] = [o['text'], x['text']]
        if x['detail'] is not None and (o['detail'] or '') != x['detail']:
            diffs['detail'] = [o['detail'], x['detail']]
        if x['citation'] and o['citation'] and o['citation'] != x['citation']:
            diffs['citation'] = [o['citation'], x['citation']]
        if diffs:
            n_diff += 1
            report['differences'].append({'nomination_id': d['nomination_id'], 'ceremony': c, 'pairing': how, 'diffs': diffs, 'academy_text': x['raw']})
    report['ceremonies'][c] = {'dataset': len(by_cer[c]), 'academy': len(A), 'paired': len(pairs), 'rows_with_differences': n_diff,
                               'dataset_winners': sum(d['Winner'] == 'True' for d in by_cer[c]), 'academy_winners': sum(r['winner'] for r in A)}

kinds = collections.Counter()
for x in report['differences']:
    for k in x['diffs']:
        kinds[k] += 1
report['summary'] = {
    'ceremonies_checked': sum(1 for v in report['ceremonies'].values() if isinstance(v, dict)),
    'rows_with_differences': len(report['differences']),
    'difference_kinds': dict(kinds),
    'unmatched_dataset': len(report['unmatched_dataset']),
    'unmatched_academy': len(report['unmatched_academy']),
}
json.dump(report, open(OUT, 'w'), indent=1, ensure_ascii=False)
print(json.dumps(report['summary'], indent=1))
