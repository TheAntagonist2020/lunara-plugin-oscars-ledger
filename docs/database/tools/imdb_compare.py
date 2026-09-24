"""Compare every (ID, credited text) pair with IMDb's own label, and flag
likely namesake misattributions by career era.

Outputs imdb_flags.json with:
  label_mismatch   IMDb's name/title for the ID shares nothing with the credit
                   (and Wikidata did not already confirm the pair)
  era_mismatch     person whose IMDb known-for year is 25+ years after every
                   award year they are credited with (the IMDb-namesake pattern)
  not_found        IMDb returns nothing for the ID
"""
import json, re, collections, difflib, unicodedata

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
R = json.load(open(f'{S}/rows.json'))
IM = json.load(open(f'{S}/imdb_suggest.json'))
SUS = {(s['id'], s['label']) for s in json.load(open(f'{S}/suspects_triaged.json'))}


def split(v):
    return [] if v in (None, '') else str(v).split('|')


def fold(s):
    return re.sub(r'[^a-z0-9 ]', '', unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()).strip()


def similar(a, b):
    fa, fb = fold(a), fold(b)
    if not fa or not fb:
        return 0
    if fa == fb:
        return 1
    ta, tb = set(fa.split()), set(fb.split())
    if ta & tb and (ta <= tb or tb <= ta or len(ta & tb) >= 2):
        return 0.9
    return difflib.SequenceMatcher(None, fa, fb).ratio()


pairs = collections.defaultdict(list)
award_years = collections.defaultdict(list)
classes = collections.defaultdict(set)
for n, r in enumerate(R, start=2):
    y = int(str(r['Year'])[:4])
    for t, i in zip(split(r['Film']), split(r['FilmId'])):
        if i != '?':
            pairs[(i, t)].append(n)
    for t, i in zip(split(r['Nominees']), split(r['NomineeIds'])):
        for one in i.split(','):
            if one != '?':
                pairs[(one, t)].append(n)
                award_years[one].append(y)
                classes[one].add(r['Class'])

flags = collections.defaultdict(list)
for (i, label), rows in pairs.items():
    im = IM.get(i)
    if im is None or 'error' in (im or {}):
        flags['fetch_error'].append({'id': i, 'label': label, 'rows': rows})
        continue
    if not im:
        flags['not_found'].append({'id': i, 'label': label, 'rows': rows})
        continue
    sim = similar(label, im.get('l'))
    if sim < 0.6 and (i, label) not in SUS:
        flags['label_mismatch'].append({'id': i, 'label': label, 'imdb': im, 'similarity': round(sim, 2), 'rows': rows})

for i, ys in award_years.items():
    im = IM.get(i) or {}
    m = re.search(r'\((\d{4})', im.get('s') or '')
    if not m:
        continue
    known = int(m.group(1))
    if known - max(ys) >= 25:
        flags['era_mismatch'].append({'id': i, 'imdb': im, 'award_years': sorted(set(ys)), 'classes': sorted(classes[i]),
                                      'labels': sorted({l for (x, l) in pairs if x == i})})

json.dump(flags, open(f'{S}/imdb_flags.json', 'w'), indent=1, ensure_ascii=False)
for k, v in flags.items():
    print(k, len(v))
