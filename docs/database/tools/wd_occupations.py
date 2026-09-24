import json, time, urllib.request, urllib.parse, sys
import concurrent.futures as cf

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
WD = json.load(open(f'{S}/wd.json'))
qids = sorted({w['qid'] for i, ws in WD.items() if i.startswith('nm') for w in ws})
Q = '''SELECT ?item ?occ ?occLabel WHERE {
 VALUES ?item { %s }
 ?item wdt:P106 ?occ .
 ?occ rdfs:label ?occLabel . FILTER(LANG(?occLabel)="en")
}'''


def run(batch):
    q = Q % ' '.join('wd:' + x for x in batch)
    for attempt in range(8):
        try:
            req = urllib.request.Request('https://query.wikidata.org/sparql', data=urllib.parse.urlencode({'query': q}).encode(),
                                         headers={'Accept': 'application/sparql-results+json', 'User-Agent': 'LunaraLedgerAudit/1.0 (lunarafilm.com data audit)',
                                                  'Content-Type': 'application/x-www-form-urlencoded'})
            res = json.load(urllib.request.urlopen(req, timeout=150))['results']['bindings']
            out = {}
            for b in res:
                out.setdefault(b['item']['value'].rsplit('/', 1)[1], []).append(b['occLabel']['value'])
            return batch, out
        except Exception as e:
            print('retry', batch[0], attempt, e, file=sys.stderr, flush=True)
            time.sleep(10 * (attempt + 1))
    return batch, None


done, failed = {}, []
batches = [qids[k:k + 400] for k in range(0, len(qids), 400)]
with cf.ThreadPoolExecutor(max_workers=2) as ex:
    for batch, got in ex.map(run, batches):
        if got is None:
            failed.append(batch)
            print('FAILED', batch[0], flush=True)
            continue
        for x in batch:
            done[x] = got.get(x, [])
        print(f'{len(done)}/{len(qids)}', flush=True)
json.dump(done, open(f'{S}/wd_occupations.json', 'w'))
print('DONE', len(done), 'failed', len(failed), flush=True)
