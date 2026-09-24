"""Compare every (row, slot, IMDb ID, label) in the workbook with Wikidata.

Writes suspects.json: one entry per distinct (id, dataset label) pair that does
not agree with the Wikidata identity for that ID, with every row it occurs in.
"""
import json, re, unicodedata, collections

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
R = json.load(open(f'{S}/rows.json'))
WD = json.load(open(f'{S}/wd.json'))

SUFFIX = {'jr', 'sr', 'ii', 'iii', 'iv', 'asc', 'bsc', 'ace', 'cas', 'mpse', 'the', 'and', 'of', 'dr', 'sir', 'mr', 'mrs', 'ms'}
ARTICLES = {'the', 'a', 'an', 'le', 'la', 'les', 'il', 'el', 'der', 'die', 'das', 'l'}
COMPANY_NOISE = {'inc', 'corp', 'corporation', 'company', 'co', 'ltd', 'limited', 'studio', 'studios', 'pictures',
                 'picture', 'productions', 'production', 'films', 'film', 'the', 'department', 'dept', 'sound',
                 'motion', 'llc', 'of', 'and', 'international', 'entertainment', 'bros', 'brothers', 'laboratories',
                 'laboratory', 'labs', 'industries', 'division', 'group', 'camera', 'research', 'studio.'}


def ascii_fold(s):
    return unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode()


def tokens(s):
    s = ascii_fold(s).lower().replace('&', ' and ')
    s = re.sub(r"[’']", '', s)
    return [t for t in re.split(r'[^a-z0-9]+', s) if t]


def person_match(a, b):
    """True when a dataset credit and a Wikidata label plausibly name the same person."""
    ta = [t for t in tokens(a) if t not in SUFFIX]
    tb = [t for t in tokens(b) if t not in SUFFIX]
    if not ta or not tb:
        return 'unknown'
    if ta == tb or ''.join(ta) == ''.join(tb):
        return 'exact'
    sa, sb = set(ta), set(tb)
    # Same surname (last significant token) and compatible first names/initials.
    if ta[-1] == tb[-1] or ta[-1] in sb or tb[-1] in sa:
        fa, fb = ta[0], tb[0]
        if fa == fb or fa[0] == fb[0] or len(ta) == 1 or len(tb) == 1:
            return 'close'
        return 'surname_only'
    if sa <= sb or sb <= sa:
        return 'close'
    # Mononyms / stage names: any shared token of length >= 4
    if any(len(t) >= 4 and t in sb for t in sa):
        return 'partial'
    return 'none'


def company_match(a, b):
    ta = {t for t in tokens(a) if t not in COMPANY_NOISE}
    tb = {t for t in tokens(b) if t not in COMPANY_NOISE}
    if not ta or not tb:
        return 'unknown'
    if ta & tb:
        return 'close'
    # 20th Century-Fox vs Fox Film Corporation etc.
    if any(x[:4] == y[:4] for x in ta for y in tb if len(x) >= 4 and len(y) >= 4):
        return 'partial'
    return 'none'


def title_match(a, b):
    ta = [t for t in tokens(a) if t not in ARTICLES]
    tb = [t for t in tokens(b) if t not in ARTICLES]
    if not ta or not tb:
        return 'unknown'
    if ta == tb:
        return 'exact'
    sa, sb = set(ta), set(tb)
    inter = len(sa & sb)
    if inter and inter >= min(len(sa), len(sb)) * 0.6:
        return 'close'
    if inter:
        return 'partial'
    return 'none'


def ceremony_window(year_label):
    y = year_label.split('/')
    start = int(y[0])
    end = int(y[0][:2] + y[1]) if len(y) > 1 else start
    return start, end


def split(v):
    return [] if v in (None, '') else str(v).split('|')


pairs = collections.defaultdict(lambda: {'rows': [], 'roles': collections.Counter()})
for n, r in enumerate(R, start=2):
    films, fids = split(r['Film']), split(r['FilmId'])
    if len(films) == len(fids):
        for t, i in zip(films, fids):
            if i and i != '?':
                p = pairs[(i, t.strip())]
                p['rows'].append(n)
                p['roles']['film'] += 1
                p.setdefault('years', set()).add(r['Year'])
    noms, nids = split(r['Nominees']), split(r['NomineeIds'])
    if len(noms) == len(nids):
        for t, i in zip(noms, nids):
            for one in i.split(','):
                one = one.strip()
                if one and one != '?':
                    p = pairs[(one, t.strip())]
                    p['rows'].append(n)
                    p['roles']['nominee'] += 1

suspects = []
stats = collections.Counter()
for (i, label), p in pairs.items():
    wd = WD.get(i)
    if wd is None:
        stats['not_fetched'] += 1
        continue
    kind = i[:2]
    entry = {'id': i, 'label': label, 'rows': p['rows'], 'count': len(p['rows']),
             'wikidata': wd, 'kind': kind}
    if not wd:
        stats['no_wikidata_item'] += 1
        entry['verdict'] = 'no_wikidata_item'
        suspects.append(entry)
        continue
    labels = [w['label'] for w in wd if w['label']]
    if not labels:
        stats['wikidata_no_en_label'] += 1
        entry['verdict'] = 'wikidata_no_en_label'
        suspects.append(entry)
        continue
    if kind == 'nm':
        best = max((person_match(label, l) for l in labels), key=['none', 'partial', 'surname_only', 'unknown', 'close', 'exact'].index)
        # A company credit sitting on a person ID is its own signal
        if best in ('none', 'partial') and company_match(label, labels[0]) in ('close',):
            best = 'company_vs_person'
    elif kind == 'co':
        best = max((company_match(label, l) for l in labels), key=['none', 'partial', 'unknown', 'close'].index)
    else:
        best = max((title_match(label, l) for l in labels), key=['none', 'partial', 'unknown', 'close', 'exact'].index)
        wy = sorted({int(y) for w in wd for y in w['years'] if y.isdigit()})
        cys = [ceremony_window(y) for y in p.get('years', [])]
        if wy and cys:
            lo = min(c[0] for c in cys) - 3
            hi = max(c[1] for c in cys) + 2
            entry['year_ok'] = any(lo <= y <= hi for y in wy)
        else:
            entry['year_ok'] = None
        if best in ('exact', 'close') and entry['year_ok'] is False:
            best = 'title_ok_year_off'
    entry['verdict'] = best
    stats[best] += 1
    if best not in ('exact', 'close', 'unknown'):
        suspects.append(entry)

json.dump(suspects, open(f'{S}/suspects.json', 'w'), indent=1)
print('pairs', len(pairs), dict(stats))
print('suspects', len(suspects), collections.Counter(s['verdict'] for s in suspects))
