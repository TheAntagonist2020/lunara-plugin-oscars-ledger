import json, time, urllib.request, urllib.parse, os, sys, threading
import concurrent.futures as cf

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
ids = json.load(open(f'{S}/ids.json'))
out_path = f'{S}/wd.json'
done = {}
todo = list(ids)
Q = '''SELECT ?id ?item ?label ?mul ?pub ?born WHERE {
 VALUES ?id { %s }
 ?item wdt:P345 ?id .
 OPTIONAL { ?item rdfs:label ?label . FILTER(LANG(?label)="en") }
 OPTIONAL { ?item rdfs:label ?mul . FILTER(LANG(?mul)="mul") }
 OPTIONAL { ?item wdt:P577 ?pub }
 OPTIONAL { ?item wdt:P569 ?born }
}'''
lock = threading.Lock()


def run(batch):
    q = Q % (' '.join('"%s"' % i for i in batch))
    for attempt in range(6):
        try:
            req = urllib.request.Request(
                'https://query.wikidata.org/sparql',
                data=urllib.parse.urlencode({'query': q}).encode(),
                headers={'Accept': 'application/sparql-results+json',
                         'User-Agent': 'LunaraLedgerAudit/1.0 (lunarafilm.com data audit)',
                         'Content-Type': 'application/x-www-form-urlencoded'})
            t = time.time()
            res = json.load(urllib.request.urlopen(req, timeout=150))
            dt = time.time() - t
            break
        except Exception as e:
            print('retry', batch[0], attempt, e, file=sys.stderr, flush=True)
            time.sleep(8 * (attempt + 1))
    else:
        return batch, None, 0
    got = {}
    for b in res['results']['bindings']:
        i = b['id']['value']
        qid = b['item']['value'].rsplit('/', 1)[1]
        e = got.setdefault(i, {}).setdefault(qid, {'qid': qid, 'label': None, 'years': set(), 'born': set()})
        if 'label' in b:
            e['label'] = b['label']['value']
        elif 'mul' in b and not e['label']:
            e['label'] = b['mul']['value']
        if 'pub' in b:
            e['years'].add(b['pub']['value'][:5].lstrip('+')[:4])
        if 'born' in b:
            e['born'].add(b['born']['value'][:5].lstrip('+')[:4])
    return batch, {i: [{'qid': v['qid'], 'label': v['label'], 'years': sorted(v['years']), 'born': sorted(v['born'])}
                       for v in d.values()] for i, d in got.items()}, dt


B = 300
batches = [todo[k:k + B] for k in range(0, len(todo), B)]
failed = []
with cf.ThreadPoolExecutor(max_workers=4) as ex:
    for batch, got, dt in ex.map(run, batches):
        with lock:
            if got is None:
                failed.append(batch)
                print('FAILED', batch[0], flush=True)
                continue
            for i in batch:
                done[i] = got.get(i, [])
            json.dump(done, open(out_path, 'w'))
            print(f'{len(done)}/{len(ids)} matched {sum(1 for v in done.values() if v)} ({dt:.1f}s)', flush=True)
json.dump(failed, open(f'{S}/wd_failed.json', 'w'))
print('DONE', len(done), 'matched', sum(1 for v in done.values() if v), 'failed batches', len(failed), flush=True)
