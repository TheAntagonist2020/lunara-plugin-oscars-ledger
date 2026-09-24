"""Pull Academy Award wins (P166) and nominations (P1411) from Wikidata.

Categories are the award items conferred by the Academy (P1027 = Q212329) or
whose English label starts with "Academy Award for". Each statement is kept
with its year (P585), the work it was for (P1686, with that work's IMDb ID)
and the recipient's IMDb ID.
"""
import json, time, urllib.request, urllib.parse, sys

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
UA = 'LunaraLedgerAudit/1.0 (lunarafilm.com data audit)'


def sparql(q):
    for attempt in range(6):
        try:
            req = urllib.request.Request('https://query.wikidata.org/sparql',
                                         data=urllib.parse.urlencode({'query': q}).encode(),
                                         headers={'Accept': 'application/sparql-results+json', 'User-Agent': UA,
                                                  'Content-Type': 'application/x-www-form-urlencoded'})
            return json.load(urllib.request.urlopen(req, timeout=170))['results']['bindings']
        except Exception as e:
            print('retry', attempt, e, file=sys.stderr, flush=True)
            time.sleep(10 * (attempt + 1))
    raise SystemExit('query failed')


cats = sparql('''SELECT DISTINCT ?a ?label WHERE {
  { ?a wdt:P1027 wd:Q212329 . } UNION { ?a wdt:P31 wd:Q19020 . }
  ?a rdfs:label ?label . FILTER(LANG(?label)="en") FILTER(STRSTARTS(?label, "Academy Award"))
}''')
cats = {b['a']['value'].rsplit('/', 1)[1]: b['label']['value'] for b in cats}
print('categories', len(cats), flush=True)
out = []
ids = list(cats)
for prop in ('P166', 'P1411'):
    for k in range(0, len(ids), 6):
        vals = ' '.join('wd:' + i for i in ids[k:k + 6])
        rows = sparql('''SELECT ?x ?xid ?a ?time ?work ?workid WHERE {
  VALUES ?a { %s }
  ?x p:%s ?st . ?st ps:%s ?a .
  OPTIONAL { ?x wdt:P345 ?xid }
  OPTIONAL { ?st pq:P585 ?time }
  OPTIONAL { ?st pq:P1686 ?work . OPTIONAL { ?work wdt:P345 ?workid } }
}''' % (vals, prop, prop))
        for b in rows:
            out.append({'prop': 'won' if prop == 'P166' else 'nominated',
                        'award': b['a']['value'].rsplit('/', 1)[1],
                        'award_label': cats.get(b['a']['value'].rsplit('/', 1)[1]),
                        'x': b['x']['value'].rsplit('/', 1)[1], 'xid': b.get('xid', {}).get('value'),
                        'year': b.get('time', {}).get('value', '')[:5].lstrip('+')[:4] or None,
                        'work': b.get('work', {}).get('value', '').rsplit('/', 1)[-1] or None,
                        'workid': b.get('workid', {}).get('value')})
        print(prop, min(k + 6, len(ids)), '/', len(ids), 'statements', len(out), flush=True)
        time.sleep(1.5)
json.dump({'categories': cats, 'statements': out}, open(f'{S}/wd_awards.json', 'w'))
print('DONE statements', len(out), flush=True)
