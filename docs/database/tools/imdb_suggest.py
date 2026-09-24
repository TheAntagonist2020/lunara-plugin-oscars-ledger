"""Fetch IMDb's own label for every ID via the public suggestion endpoint.

For nm/co: l = name, s = known-for summary ("Profession, Title (Year)").
For tt: l = title, y = year, q = type (feature, TV series, short, ...).
"""
import json, os, sys, time, urllib.request
import concurrent.futures as cf

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
ids = json.load(open(f'{S}/ids.json'))
out_path = f'{S}/imdb_suggest.json'
done = json.load(open(out_path)) if os.path.exists(out_path) else {}
todo = [i for i in ids if i not in done]


def get(i):
    url = f'https://v3.sg.media-imdb.com/suggestion/x/{i}.json'
    for attempt in range(5):
        try:
            d = json.load(urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'}), timeout=30))
            hit = next((x for x in d.get('d', []) if x.get('id') == i), None)
            return i, ({'l': hit.get('l'), 's': hit.get('s'), 'y': hit.get('y'), 'q': hit.get('q'), 'qid': hit.get('qid')} if hit else {})
        except Exception as e:
            time.sleep(2 * (attempt + 1))
            err = str(e)
    return i, {'error': err}


n = 0
with cf.ThreadPoolExecutor(max_workers=8) as ex:
    for i, v in ex.map(get, todo):
        done[i] = v
        n += 1
        if n % 500 == 0:
            json.dump(done, open(out_path, 'w'))
            print(f'{len(done)}/{len(ids)}', flush=True)
json.dump(done, open(out_path, 'w'))
print('DONE', len(done), 'errors', sum(1 for v in done.values() if 'error' in v), 'empty', sum(1 for v in done.values() if not v), flush=True)
