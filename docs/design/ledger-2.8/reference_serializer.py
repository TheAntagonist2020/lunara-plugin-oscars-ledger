"""Reference serializer for lunara-ledger/v1, run against the scratch MariaDB copy.

Builds every response shape the design specifies, validates each against the
2020-12 schemas, and checks the specific mislabel guards the design promises.
"""
import collections, gzip, hashlib, json, os, subprocess, sys

from jsonschema import Draft202012Validator
from referencing import Registry, Resource

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad'
HERE = os.path.dirname(os.path.abspath(__file__))
M = ['mysql', f'--socket={S}/mariadb/run/mysqld.sock', '-uroot', '-B', '-N', '--raw', 'api_design', '-e']
BASE = 'https://lunarafilm.com/wp-json/lunara-ledger/v1/'
SITE = 'https://lunarafilm.com/oscars/'


def q(sql):
    out = subprocess.run(M + [sql], capture_output=True, text=True, check=True).stdout
    return [[None if v == 'NULL' else v for v in line.split('\t')] for line in out.split('\n') if line != '']


# ---- schema registry -------------------------------------------------------
resources = []
schemas = {}
for f in sorted(os.listdir(os.path.join(HERE, 'schemas'))):
    doc = json.load(open(os.path.join(HERE, 'schemas', f)))
    Draft202012Validator.check_schema(doc)
    schemas[f.replace('.schema.json', '')] = doc
    resources.append((doc['$id'], Resource.from_contents(doc)))
registry = Registry().with_resources(resources)


def validate(name, instance):
    v = Draft202012Validator(schemas[name], registry=registry, format_checker=Draft202012Validator.FORMAT_CHECKER)
    errs = sorted(v.iter_errors(instance), key=lambda e: e.path)
    if errs:
        e = errs[0]
        raise SystemExit(f'{name}: {len(errs)} errors; first at {list(e.absolute_path)}: {e.message[:300]}')


# ---- load --------------------------------------------------------------------
dataset = q('SELECT dataset_version, corrected_sha256, loaded_at FROM ledger_dataset')[0]
TOKEN = hashlib.sha256(('|'.join(dataset) + '|api-1.0.0|https://lunarafilm.com|oscars').encode()).hexdigest()[:16]
cer = {int(r[0]): {'number': int(r[0]), 'year_label': r[1], 'film_year_start': int(r[2]), 'film_year_end': int(r[3])} for r in q('SELECT * FROM ledger_ceremonies')}
classes = [(r[0], int(r[1])) for r in q('SELECT class_code, sort_order FROM ledger_classes ORDER BY sort_order')]
cats = {int(r[0]): {'id': int(r[0]), 'canonical': r[1], 'slug': r[2], 'class': r[3]} for r in q('SELECT * FROM ledger_categories')}
ents = {r[0]: {'kind': r[1], 'name': r[2]} for r in q('SELECT imdb_id, kind, name FROM ledger_entities')}
titles = {r[0]: {'title': r[1]} for r in q('SELECT imdb_id, title FROM ledger_titles')}
groups = {(int(r[0]), int(r[1])): {'nominations': int(r[2]), 'winners': int(r[3])} for r in q('SELECT ceremony_no, category_id, nominations, winners FROM ledger_group_stats')}
estats = {r[0]: {'nominations': int(r[2]), 'wins': int(r[3]), 'official_nominations': int(r[4]), 'first_ceremony': int(r[5]), 'last_ceremony': int(r[6]), 'categories': int(r[7])}
          for r in q('SELECT imdb_id, kind, nominations, wins, official_nominations, first_ceremony, last_ceremony, categories FROM ledger_entity_stats')}
FIELD_MAP = {'Film': 'titles.as_credited', 'FilmId': 'titles.imdb_id', 'Name': 'credit_line', 'Nominees': 'credits.as_credited',
             'NomineeIds': 'credits.identities', 'Detail': 'detail', 'Note': 'note', 'Citation': 'citation'}
corr = collections.defaultdict(list)
all_corr = []
for r in q('SELECT correction_id, nomination_id, field, before_value, after_value, reason, evidence, verification FROM ledger_corrections ORDER BY correction_id'):
    c = {'id': int(r[0]), 'nomination_id': int(r[1]) if r[1] else None, 'source_field': r[2], 'field': FIELD_MAP[r[2]],
         'before': r[3], 'after': r[4], 'reason': r[5], 'evidence': r[6], 'verification': r[7]}
    all_corr.append(c)
    corr[c['nomination_id']].append(c)

MODERN = {'ACTOR IN A LEADING ROLE': 'Best Actor', 'ACTRESS IN A LEADING ROLE': 'Best Actress',
          'ACTOR IN A SUPPORTING ROLE': 'Best Supporting Actor', 'ACTRESS IN A SUPPORTING ROLE': 'Best Supporting Actress',
          'BEST PICTURE': 'Best Picture', 'DIRECTING': 'Best Director'}


def label(canon, ceremony):  # mirrors format_category_display() at academy-awards-table.php:5614-5634
    if canon == 'ART DIRECTION' and (ceremony == 0 or ceremony >= 85):
        return 'Production Design'
    if canon == 'SOUND MIXING' and (ceremony == 0 or ceremony >= 93):
        return 'Sound'
    return MODERN.get(canon, canon)


def ordinal(n):  # mirrors ordinal() at academy-awards-table.php:5528-5534
    s = ['th', 'st', 'nd', 'rd']
    v = n % 100
    idx = (v - 20) % 10
    return f'{n}' + (s[idx] if idx < 4 and v >= 20 else (s[v] if v < 4 else s[0]))


def entity_url(i):
    return SITE + {'tt': 'title', 'nm': 'name', 'co': 'company'}[i[:2]] + '/' + i + '/'


title_slots = collections.defaultdict(list)
for r in q('SELECT nomination_id, ordinal, imdb_id, title_as_credited, detail FROM ledger_nomination_titles ORDER BY nomination_id, ordinal'):
    title_slots[int(r[0])].append((int(r[1]), r[2], r[3], r[4]))
idents = collections.defaultdict(list)
for r in q('SELECT nomination_id, ordinal, imdb_id FROM ledger_credit_identities ORDER BY nomination_id, ordinal, imdb_id'):
    idents[(int(r[0]), int(r[1]))].append(r[2])
credit_slots = collections.defaultdict(list)
for r in q('SELECT nomination_id, ordinal, name_as_credited FROM ledger_nomination_credits ORDER BY nomination_id, ordinal'):
    credit_slots[int(r[0])].append((int(r[1]), r[2]))
rows = {int(r[0]): r for r in q('SELECT nomination_id, ceremony_no, category_id, category_as_given, is_winner, is_official, credit_line, detail, note, citation FROM ledger_nominations')}


def nomination(nid, embed=('titles', 'credits'), fields=None):
    r = rows[nid]
    c, cid = int(r[1]), int(r[2])
    cat = cats[cid]
    tt = [s[1] for s in title_slots[nid] if s[1]]
    ids = [i for (o, _) in credit_slots[nid] for i in idents[(nid, o)]]
    rec = {
        'id': nid, 'source_line': nid + 1, 'ceremony': c, 'year_label': cer[c]['year_label'],
        'category': {'id': cid, 'slug': cat['slug'], 'canonical': cat['canonical'], 'as_given': r[3],
                     'label': label(cat['canonical'], c), 'label_modern': label(cat['canonical'], 0), 'class': cat['class']},
        'winner': r[4] == '1', 'official': r[5] == '1',
        'credit_line': r[6], 'detail': r[7], 'note': r[8], 'citation': r[9],
        'corrected': nid in corr,
        'group': groups[(c, cid)],
        'imdb_ids': {'titles': list(dict.fromkeys(tt)),
                     'people': list(dict.fromkeys(i for i in ids if i.startswith('nm'))),
                     'companies': list(dict.fromkeys(i for i in ids if i.startswith('co')))},
    }
    links = 'links' in embed
    if 'titles' in embed:
        rec['titles'] = []
        for (o, i, t, d) in title_slots[nid]:
            slot = {'ordinal': o, 'imdb_id': i, 'as_credited': t, 'title': titles[i]['title'] if i else None, 'detail': d}
            if links and i:
                slot['url'] = entity_url(i)
            rec['titles'].append(slot)
    if 'credits' in embed:
        rec['credits'] = []
        for (o, n) in credit_slots[nid]:
            ids_o = []
            for i in idents[(nid, o)]:
                ident = {'imdb_id': i, 'kind': ents[i]['kind'], 'name': ents[i]['name']}
                if links:
                    ident['url'] = entity_url(i)
                ids_o.append(ident)
            rec['credits'].append({'ordinal': o, 'as_credited': n, 'identities': ids_o})
    if 'corrections' in embed:
        rec['corrections'] = corr.get(nid, [])
    if fields:
        rec = {k: v for k, v in rec.items() if k == 'id' or k in fields}
    return rec


def meta(**kw):
    m = {'api': 'lunara-ledger/v1', 'token': TOKEN, 'dataset_version': dataset[0]}
    m.update(kw)
    return m


def envelope(data, path, **kw):
    return {'data': data, 'meta': meta(**kw), 'links': {'self': BASE + path}}


def sort_key(nid):
    r = rows[nid]
    return (-int(r[1]), int(r[2]), -int(r[4]), nid)


def size(obj):
    b = json.dumps(obj, ensure_ascii=False, separators=(',', ':')).encode()
    return len(b), len(gzip.compress(b, 6))


report = {}

# every nomination, every embed
for nid in rows:
    validate('nomination', nomination(nid, embed=('titles', 'credits', 'corrections', 'links')))
report['nominations validated'] = len(rows)

# list page: whole ceremony 14 (190 rows) in one page of 200
c14 = sorted([n for n in rows if rows[n][1] == '14'], key=sort_key)
page = envelope([nomination(n) for n in c14], 'nominations?ceremony=14&limit=200', total=len(c14), returned=len(c14), limit=200, offset=0,
                sort='-ceremony', next_cursor=None, query={'ceremony': '14', 'limit': '200'}, embed=['titles', 'credits'])
validate('nominations-response', page)
report['GET /nominations?ceremony=14&limit=200 (bytes, gzip)'] = size(page)

# embed=entities dictionary
page25 = [nomination(n) for n in sorted(rows, key=sort_key)[:25]]
refd = sorted({i for r in page25 for k in ('titles', 'people', 'companies') for i in r['imdb_ids'][k]})
inc = {i: dict({'imdb_id': i, 'kind': 'title' if i.startswith('tt') else ents[i]['kind'],
                'name': titles[i]['title'] if i.startswith('tt') else ents[i]['name'], 'url': entity_url(i)}, stats=estats[i]) for i in refd}
e25 = envelope(page25, 'nominations?embed=credits,entities,titles', total=len(rows), returned=25, limit=25, offset=0, sort='-ceremony',
               next_cursor='djE6' + TOKEN + 'OjI1', query={'embed': 'credits,entities,titles'}, embed=['titles', 'credits', 'entities'])
e25['included'] = {'entities': inc}
validate('nominations-response', e25)
report['GET /nominations (default page of 25 + embed=entities)'] = size(e25)

# compact: embed=none and fields
compact = envelope([nomination(n, embed=(), fields=('ceremony', 'category', 'winner', 'imdb_ids')) for n in c14], 'nominations?ceremony=14&embed=none&fields=ceremony,category,winner,imdb_ids&limit=200',
                   total=len(c14), returned=len(c14), limit=200, offset=0, sort='-ceremony', next_cursor=None, query={}, embed=[], fields=['id', 'ceremony', 'category', 'winner', 'imdb_ids'])
validate('nominations-response', compact)
report['GET /nominations?ceremony=14&embed=none&fields=... (bytes, gzip)'] = size(compact)

# ceremonies
cstats = collections.defaultdict(lambda: {'nominations': 0, 'winners': 0, 'official_nominations': 0, 'categories': 0, 'multi_winner_categories': 0})
for (c, cid), g in groups.items():
    s = cstats[c]
    s['nominations'] += g['nominations']; s['winners'] += g['winners']; s['categories'] += 1
    s['multi_winner_categories'] += 1 if g['winners'] > 1 else 0
for n, r in rows.items():
    cstats[int(r[1])]['official_nominations'] += 1 if r[5] == '1' else 0
ceremonies = [dict(cer[c], ordinal=ordinal(c), stats=cstats[c], url=SITE + f'ceremony/{c}/') for c in sorted(cer, reverse=True)]
cl = envelope(ceremonies, 'ceremonies', total=len(ceremonies), returned=len(ceremonies))
validate('ceremonies-response', cl)
report['GET /ceremonies'] = size(cl)
c98 = dict(ceremonies[0], previous=97, next=None,
           categories=[{'category': nomination(n)['category'], 'nominations': groups[(98, int(rows[n][2]))]['nominations'], 'winners': groups[(98, int(rows[n][2]))]['winners']}
                       for n in {int(rows[n][2]): n for n in sorted(rows) if rows[n][1] == '98'}.values()],
           nominations=[nomination(n) for n in sorted([n for n in rows if rows[n][1] == '98'], key=sort_key)])
cd = envelope(c98, 'ceremonies/98?embed=nominations', embed=['nominations'])
validate('ceremony-response', cd)
report['GET /ceremonies/98?embed=nominations'] = size(cd)

# categories
as_given = collections.defaultdict(lambda: collections.defaultdict(list))
for n, r in rows.items():
    as_given[int(r[2])][r[3]].append(int(r[1]))
catstats = {}
for cid in cats:
    gs = [(c, g) for (c, k), g in groups.items() if k == cid]
    catstats[cid] = {'nominations': sum(g['nominations'] for _, g in gs), 'winners': sum(g['winners'] for _, g in gs), 'ceremonies': len(gs),
                     'first_ceremony': min(c for c, _ in gs), 'last_ceremony': max(c for c, _ in gs)}
alias = {'art-direction': ['production-design'], 'sound-mixing': ['sound']}
catlist = []
for cid in sorted(cats):
    cat = cats[cid]
    item = {'id': cid, 'slug': cat['slug'], 'canonical': cat['canonical'], 'class': cat['class'], 'label_modern': label(cat['canonical'], 0),
            'names_as_given': sorted([{'name': nm, 'first_ceremony': min(cs), 'last_ceremony': max(cs), 'nominations': len(cs)} for nm, cs in as_given[cid].items()], key=lambda x: (x['first_ceremony'], x['name'])),
            'stats': catstats[cid], 'url': SITE + f"category/{cat['slug']}/"}
    if cat['slug'] in alias:
        item['aliases'] = alias[cat['slug']]
    catlist.append(item)
cat_env = envelope(catlist, 'categories', total=len(catlist), returned=len(catlist))
cat_env['included'] = {'classes': [{'code': c, 'sort_order': o, 'categories': [x['slug'] for x in catlist if x['class'] == c]} for c, o in classes]}
validate('categories-response', cat_env)
report['GET /categories'] = size(cat_env)
ad = dict(next(x for x in catlist if x['slug'] == 'art-direction'))
ad['ceremonies'] = [{'ceremony': c, 'year_label': cer[c]['year_label'], 'label': label('ART DIRECTION', c),
                     'as_given': sorted({rows[n][3] for n in rows if rows[n][1] == str(c) and rows[n][2] == '26'}),
                     'nominations': g['nominations'], 'winners': g['winners']} for (c, k), g in sorted(groups.items(), reverse=True) if k == 26]
last = ad['ceremonies'][0]['ceremony']
ad['latest'] = [nomination(n) for n in sorted([n for n in rows if rows[n][1] == str(last) and rows[n][2] == '26'], key=sort_key)]
ad_env = envelope(ad, 'categories/art-direction?embed=latest', embed=['latest'])
validate('category-response', ad_env)
report['GET /categories/art-direction?embed=latest'] = size(ad_env)

# entities
aliases = collections.defaultdict(set)
for r in q('SELECT imdb_id, label, is_canonical FROM ledger_search'):
    if r[2] == '0':
        aliases[r[0]].add(r[1])
by_entity = collections.defaultdict(set)
for (nid, o), ids in idents.items():
    for i in ids:
        by_entity[i].add(nid)
for nid, slots in title_slots.items():
    for (_, i, _, _) in slots:
        if i:
            by_entity[i].add(nid)


def entity(i):
    kind = 'title' if i.startswith('tt') else ents[i]['kind']
    name = titles[i]['title'] if kind == 'title' else ents[i]['name']
    ext = {'imdb_url': f"https://www.imdb.com/{ {'tt': 'title', 'nm': 'name', 'co': 'company'}[i[:2]] }/{i}/"}
    return {'imdb_id': i, 'kind': kind, 'name': name, 'aliases': sorted(a for a in aliases[i] if a.lower() != name.lower()),
            'stats': estats[i], 'external': ext, 'url': entity_url(i),
            'nominations': [nomination(n) for n in sorted(by_entity[i], key=sort_key)]}


for i in ('tt0120338', 'nm0001053', 'nm0380965', 'co0007143', 'tt0019553', 'nm0609771'):
    env_ = envelope(entity(i), {'tt': 'titles/', 'nm': 'people/', 'co': 'companies/'}[i[:2]] + i, embed=['titles', 'credits'])
    validate('entity-response', env_)
    report[f'GET entity {i}'] = size(env_)

# groups by person, Acting only
acting = {n for n, r in rows.items() if cats[int(r[2])]['class'] == 'Acting'}
grp = collections.defaultdict(list)
for n in acting:
    for (o, _) in credit_slots[n]:
        for i in idents[(n, o)]:
            if i.startswith('nm'):
                grp[i].append(n)
items = []
for i, ns in grp.items():
    ns = sorted(set(ns))
    items.append({'key': {'imdb_id': i, 'kind': 'person', 'name': ents[i]['name'], 'url': entity_url(i)},
                  'nominations': len(ns), 'wins': sum(rows[n][4] == '1' for n in ns), 'official_nominations': sum(rows[n][5] == '1' for n in ns),
                  'first_ceremony': min(int(rows[n][1]) for n in ns), 'last_ceremony': max(int(rows[n][1]) for n in ns),
                  'nomination_ids': ns[:100], 'nomination_ids_truncated': len(ns) > 100})
items.sort(key=lambda x: (-x['wins'], -x['nominations'], x['key']['imdb_id']))
g_env = envelope(items[:25], 'nominations/by/person?class=Acting', total=len(items), returned=25, limit=25, offset=0, sort='-wins', dimension='person', query={'class': 'Acting'})
validate('groups-response', g_env)
report['GET /nominations/by/person?class=Acting top'] = [(x['key']['name'], x['wins'], x['nominations']) for x in items[:3]]

# facets, unfiltered
def buckets(keyf, valuef, extra=lambda k: {}):
    agg = collections.defaultdict(lambda: [0, 0])
    for n, r in rows.items():
        k = keyf(r)
        agg[k][0] += 1; agg[k][1] += r[4] == '1'
    return [dict({'value': valuef(k), 'nominations': a[0], 'winners': a[1]}, **extra(k)) for k, a in sorted(agg.items())]


fac = {'total': len(rows),
       'class': buckets(lambda r: cats[int(r[2])]['class'], lambda k: k),
       'category': buckets(lambda r: int(r[2]), lambda k: cats[k]['slug'], lambda k: {'id': k, 'label_modern': label(cats[k]['canonical'], 0)}),
       'ceremony': buckets(lambda r: int(r[1]), lambda k: k, lambda k: {'year_label': cer[k]['year_label']}),
       'decade': buckets(lambda r: cer[int(r[1])]['film_year_start'] // 10 * 10, lambda k: f'{k}s'),
       'winner': {'true': sum(r[4] == '1' for r in rows.values()), 'false': sum(r[4] == '0' for r in rows.values())},
       'official': {'true': sum(r[5] == '1' for r in rows.values()), 'false': sum(r[5] == '0' for r in rows.values())}}
f_env = envelope(fac, 'facets', query={})
validate('facets-response', f_env)
report['GET /facets (unfiltered)'] = size(f_env)

# search
hits = q("SELECT imdb_id, kind, label, is_canonical FROM ledger_search WHERE folded LIKE '%jaynes%' ORDER BY nominations DESC, imdb_id LIMIT 20")
s_env = envelope([{'imdb_id': h[0], 'kind': h[1], 'name': titles[h[0]]['title'] if h[1] == 'title' else ents[h[0]]['name'],
                   'matched': h[2], 'matched_alias': h[3] == '0', 'stats': estats[h[0]], 'url': entity_url(h[0])} for h in hits],
                 'search?q=jaynes', returned=len(hits), limit=20, query={'q': 'jaynes'})
validate('search-response', s_env)
report['GET /search?q=jaynes'] = [(x['name'], x['matched']) for x in s_env['data']]

# corrections
co_env = envelope(all_corr, 'corrections', total=len(all_corr), returned=len(all_corr), limit=200, offset=0)
validate('corrections-response', co_env)
report['GET /corrections (all 157)'] = size(co_env)

# status
counts = {'nominations': len(rows), 'winners': sum(r[4] == '1' for r in rows.values()), 'official_nominations': sum(r[5] == '1' for r in rows.values()),
          'ceremonies': len(cer), 'categories': len(cats), 'classes': len(classes), 'titles': len(titles),
          'people': sum(e['kind'] == 'person' for e in ents.values()), 'companies': sum(e['kind'] == 'company' for e in ents.values()),
          'title_slots': sum(len(v) for v in title_slots.values()), 'credit_slots': sum(len(v) for v in credit_slots.values()),
          'credit_identities': sum(len(v) for v in idents.values()), 'multi_winner_groups': sum(g['winners'] > 1 for g in groups.values()),
          'nominations_without_imdb_id': sum(1 for n in rows if not any(s[1] for s in title_slots[n]) and not any(idents[(n, o)] for (o, _) in credit_slots[n]))}
st = envelope({'ready': True, 'api': {'namespace': 'lunara-ledger/v1', 'revision': '1.0.0', 'schema': BASE + 'schema'},
               'software': {'plugin_version': '2.8.0'},
               'dataset': {'version': dataset[0], 'token': TOKEN, 'loaded_at': dataset[2].replace(' ', 'T') + 'Z',
                           'source': {'name': 'Academy Awards Database via DLu/oscar_data', 'file': 'data/oscars.csv',
                                      'sha256': 'fad75163dfe626ce48139f72aca121a492982f6a7956b5350d7e51e38d7d4a95', 'rows': 12137},
                           'corrected': {'sha256': dataset[1], 'rows': 12137},
                           'corrections': {'total': len(all_corr), 'by_reason': dict(collections.Counter(c['reason'] for c in all_corr))},
                           'needs_review': 8},
               'counts': counts,
               'checks': [{'name': 'nominations_match_dataset', 'ok': True, 'expected': 12137, 'actual': counts['nominations']},
                          {'name': 'winners_match_dataset', 'ok': True, 'expected': 3515, 'actual': counts['winners']},
                          {'name': 'dataset_option_matches_table', 'ok': True}],
               'last_import': {'result': 'ok', 'finished_at': dataset[2].replace(' ', 'T') + 'Z', 'failed_check': None}}, 'status')
validate('status-response', st)
report['GET /status counts'] = counts

validate('error', {'code': 'ledger_unknown_param', 'message': 'Unknown parameter: page_size.', 'data': {'status': 400, 'params': {'page_size': 'not a lunara-ledger/v1 parameter'}}})

# ---- mislabel guards -----------------------------------------------------------
def must(cond, msg):
    if not cond:
        raise SystemExit('GUARD FAILED: ' + msg)


must(ents['nm0380965']['name'] == 'Jean Hersholt', 'nm0380965 is Jean Hersholt')
must(ents['nm0001053']['name'] == 'Ethan Coen' and 'Roderick Jaynes' in entity('nm0001053')['aliases'], 'Ethan Coen keeps his name; Jaynes is an alias')
must([i['imdb_id'] for i in nomination(8165)['credits'][0]['identities']] == ['nm0001053', 'nm0001054'], 'Jaynes credit resolves to both Coens')
n2 = nomination(2)
must([t['imdb_id'] for t in n2['titles']] == ['tt0019071', 'tt0019553'] and n2['titles'][1]['detail'] == 'August Schilling', 'Jannings: two films, aligned roles')
must(cer[6]['year_label'] == '1932/33', 'split year label verbatim')
must([i['imdb_id'] for c in nomination(526)['credits'] for i in c['identities']] == ['co0026841', 'nm0609771'], 'Moulton carries his own ID')
must(nomination(12067)['group'] == {'nominations': 5, 'winners': 2}, '98th live-action short tie keeps both winners')
must(sum(1 for r in rows.values() if r[4] == '1' and r[5] == '0') == 8, '8 unofficial winners stay winners')
must(nomination(133)['credit_line'] == 'Barney "Chick" McGill', 'decoded quotes')
must(label('ART DIRECTION', 84) == 'ART DIRECTION' and label('ART DIRECTION', 85) == 'Production Design', 'dated labels')
must(entity('tt0019553')['stats']['nominations'] >= 1, 'second-position title is an entity')
report['guards'] = 'all passed'

for k, v in report.items():
    print(f'{k}: {v}')

# optional embeds: review + poster on a title slot and on a title entity
t = entity('tt0120338')
t['review'] = {'url': 'https://lunarafilm.com/reviews/titanic/', 'title': 'Titanic'}
t['poster'] = {'url': 'https://lunarafilm.com/wp-content/uploads/2026/01/titanic.jpg', 'width': 500, 'height': 750, 'alt': 'Titanic poster'}
t['nominations'][0]['titles'][0]['poster'] = t['poster']
t['nominations'][0]['titles'][0]['review'] = t['review']
validate('entity-response', envelope(t, 'titles/tt0120338?embed=credits,media,reviews,titles', embed=['titles', 'credits', 'reviews', 'media']))
bad = dict(t); bad['poster'] = dict(t['poster'], api_key='x')
try:
    validate('entity-response', envelope(bad, 'titles/tt0120338'))
    raise SystemExit('schema accepted a key-bearing field')
except SystemExit as ex:
    assert 'errors' in str(ex), ex
print('optional embeds validated; a smuggled api_key field is rejected by additionalProperties:false')
