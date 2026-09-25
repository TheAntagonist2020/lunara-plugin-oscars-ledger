"""Build the Lunara Oscars Ledger SQL load from the source sheet plus the
verified corrections ledger.

    python build.py <rows.json> <corrections.json> <wikidata.json> <out_dir> [additions.json]

Writes:
  data.sql                  INSERTs for every table (schema.sql creates them)
  oscars-corrected.tsv      the corrected sheet, same 14 columns as the source
  corrections-applied.json  every change actually applied, with evidence
The build refuses to write anything if a correction does not match the
value it expects to replace, or if any slot count disagrees.
"""
import json, re, sys, hashlib, collections, datetime, unicodedata, os

ROWS, CORR, WDP, OUT = sys.argv[1:5]
ADDS = sys.argv[5] if len(sys.argv) > 5 else None
R = json.load(open(ROWS))
CORRECTIONS = json.load(open(CORR)) if os.path.exists(CORR) else []
WD = json.load(open(WDP))
COLS = ['Ceremony', 'Year', 'Class', 'CanonicalCategory', 'Category', 'Film', 'FilmId', 'Name', 'Nominees',
        'NomineeIds', 'Winner', 'Detail', 'Note', 'Citation']
CLASS_ORDER = ['Acting', 'Directing', 'Writing', 'Title', 'Production', 'Music', 'SciTech', 'Special']


def fail(msg):
    raise SystemExit('BUILD REFUSED: ' + msg)


def text(v):
    # Values pass through untouched: every change to the source is an explicit,
    # evidenced entry in the corrections ledger, never a silent normalization.
    if v is None or v == '':
        return None
    return str(v)


def split(v):
    return [] if v in (None, '') else str(v).split('|')


rows = []
for i, r in enumerate(R, start=1):
    row = {c: r.get(c) for c in COLS}
    for c in COLS:
        if c not in ('Ceremony', 'Winner'):
            row[c] = text(row[c])
    row['_id'] = i                    # nomination_id: 1-based data row = spreadsheet row - 1
    rows.append(row)

applied = []
for c in CORRECTIONS:
    row = rows[c['nomination_id'] - 1]
    before = row[c['field']]
    if (before or '') != (c['before'] or ''):
        fail(f"row {c['nomination_id']} {c['field']}: expected {c['before']!r}, found {before!r}")
    row[c['field']] = c['after']
    applied.append(c)

# Rows the upstream data lacks are appended after its last row, so every existing
# nomination_id stays stable. Each addition carries its own evidence.
ADDITIONS = json.load(open(ADDS)) if ADDS and os.path.exists(ADDS) else []
for a in ADDITIONS:
    row = {c: a['row'].get(c) for c in COLS}
    for c in COLS:
        if c not in ('Ceremony', 'Winner'):
            row[c] = text(row[c])
    row['_id'] = len(rows) + 1
    rows.append(row)

# ---- validate slot alignment after corrections --------------------------------
for r in rows:
    f, fi = split(r['Film']), split(r['FilmId'])
    if fi and len(f) != len(fi):
        fail(f"row {r['_id']}: {len(f)} titles vs {len(fi)} title ids")
    n, ni = split(r['Nominees']), split(r['NomineeIds'])
    if ni and len(n) != len(ni):
        fail(f"row {r['_id']}: {len(n)} nominees vs {len(ni)} nominee ids")
    for t in fi:
        if t != '?' and not re.fullmatch(r'tt\d{7,8}', t):
            fail(f"row {r['_id']}: bad title id {t}")
    for t in ni:
        for one in t.split(','):
            if one != '?' and not re.fullmatch(r'(nm|co)\d{7,8}', one):
                fail(f"row {r['_id']}: bad nominee id {one}")


def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int,)):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"


def slugify(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'-{2,}', '-', re.sub(r'[^a-z0-9]+', '-', s)).strip('-')


out = []
emit = out.append

# ceremonies
cer = {}
for r in rows:
    cer.setdefault(r['Ceremony'], r['Year'])
for c, y in sorted(cer.items()):
    a = y.split('/')
    start = int(a[0])
    end = int(a[0][:2] + a[1]) if len(a) > 1 else start
    emit(f"INSERT INTO ledger_ceremonies VALUES ({c},{q(y)},{start},{end});")

for k, cls in enumerate(CLASS_ORDER, start=1):
    emit(f"INSERT INTO ledger_classes VALUES ({q(cls)},{k});")

cats = {}
for r in rows:
    cats.setdefault(r['CanonicalCategory'], r['Class'])
cat_id = {}
slugs = set()
for k, (name, cls) in enumerate(sorted(cats.items(), key=lambda x: (CLASS_ORDER.index(x[1]), x[0])), start=1):
    s = slugify(name)
    if s in slugs:
        fail('slug collision ' + s)
    slugs.add(s)
    cat_id[name] = k
    emit(f"INSERT INTO ledger_categories VALUES ({k},{q(name)},{q(s)},{q(cls)});")

# titles and entities
title_labels = collections.defaultdict(collections.Counter)
entity_labels = collections.defaultdict(collections.Counter)
for r in rows:
    for t, i in zip(split(r['Film']), split(r['FilmId'])):
        if i != '?':
            title_labels[i][t] += 1
    for n, i in zip(split(r['Nominees']), split(r['NomineeIds'])):
        for one in i.split(','):
            if one != '?':
                entity_labels[one][n] += 1


def wd_first(i):
    for w in WD.get(i) or []:
        return w
    return None


def year_of(w, key):
    ys = [int(y) for y in (w or {}).get(key, []) if y.isdigit()]
    return min(ys) if ys else None


# Ceremony years each entity was credited in, to reject Wikidata century-precision
# birth dates (stored as 1901 or 2000) that cannot be a real birth year.
entity_years = collections.defaultdict(list)
for r in rows:
    for i in split(r['NomineeIds']):
        for one in i.split(','):
            entity_years[one].append(int(str(r['Year'])[:4]))


def plausible_birth(i, w):
    ys = [int(y) for y in (w or {}).get('born', []) if y.isdigit()]
    if len(ys) != 1 or not entity_years.get(i):
        return None
    b = ys[0]
    return b if all(5 <= y - b <= 100 for y in entity_years[i]) else None


OVERRIDE = {c['imdb_id']: c['canonical_name'] for c in CORRECTIONS if c.get('canonical_name') and c.get('imdb_id')}
for i, labels in sorted(title_labels.items()):
    w = wd_first(i)
    emit(f"INSERT INTO ledger_titles VALUES ({q(i)},{q(labels.most_common(1)[0][0])},{q(year_of(w, 'years'))},{q((w or {}).get('qid'))});")
for i, labels in sorted(entity_labels.items()):
    w = wd_first(i)
    kind = 'person' if i.startswith('nm') else 'company'
    credited = labels.most_common(1)[0][0]
    if i in OVERRIDE:
        name = OVERRIDE[i]
    elif kind == 'person' and w and w.get('label'):
        name = w['label']
    else:
        name = credited
    emit(f"INSERT INTO ledger_entities VALUES ({q(i)},{q(kind)},{q(name)},{q(plausible_birth(i, w) if kind == 'person' else None)},{q((w or {}).get('qid'))});")

# nominations and their slots
winners = 0
for r in rows:
    nid = r['_id']
    official = 0 if re.search(r'NOT AN OFFICIAL NOMINATION', r['Note'] or '', re.I) else 1
    win = 1 if r['Winner'] in (1, True, '1', 'True') else 0
    winners += win
    emit(f"INSERT INTO ledger_nominations VALUES ({nid},{r['Ceremony']},{cat_id[r['CanonicalCategory']]},{q(r['Category'])},{win},{official},{q(r['Name'])},{q(r['Detail'])},{q(r['Note'])},{q(r['Citation'])});")
    films, fids = split(r['Film']), split(r['FilmId'])
    details = split(r['Detail'])
    align_detail = r['Class'] in ('Acting', 'Music') and len(details) == len(films) and films
    for k, t in enumerate(films, start=1):
        i = fids[k - 1] if fids else '?'
        emit(f"INSERT INTO ledger_nomination_titles VALUES ({nid},{k},{q(None if i == '?' else i)},{q(t)},{q(details[k - 1] if align_detail else None)});")
    noms, nids = split(r['Nominees']), split(r['NomineeIds'])
    for k, n in enumerate(noms, start=1):
        emit(f"INSERT INTO ledger_nomination_credits VALUES ({nid},{k},{q(n)});")
        i = nids[k - 1] if nids else '?'
        for one in i.split(','):
            if one != '?':
                emit(f"INSERT INTO ledger_credit_identities VALUES ({nid},{k},{q(one)});")

# corrected sheet
os.makedirs(OUT, exist_ok=True)
tsv = os.path.join(OUT, 'oscars-corrected.tsv')
with open(tsv, 'w', encoding='utf-8', newline='') as f:
    f.write('\t'.join(COLS) + '\n')
    for r in rows:
        vals = []
        for c in COLS:
            v = r[c]
            if c == 'Winner':
                v = 'True' if r['Winner'] in (1, True, '1', 'True') else ''
            vals.append('' if v is None else str(v))
        if any('\t' in v or '\n' in v for v in vals):
            fail(f"row {r['_id']}: tab or newline inside a cell")
        f.write('\t'.join(vals) + '\n')
source_sha = hashlib.sha256(open(ROWS, 'rb').read()).hexdigest()
corrected_sha = hashlib.sha256(open(tsv, 'rb').read()).hexdigest()
version = os.environ.get('DATASET_VERSION') or datetime.date.today().strftime('%Y.%m.%d') + '-1'
emit(f"INSERT INTO ledger_dataset VALUES ({q(version)},'Academy Awards Database via DLu/oscar_data',{q(source_sha)},{q(corrected_sha)},{len(rows)},{winners},NOW());")
for a, r in zip(ADDITIONS, rows[len(rows) - len(ADDITIONS):]):
    emit(f"INSERT INTO ledger_corrections (nomination_id, field, before_value, after_value, reason, evidence, verification) VALUES ({r['_id']},'(row)',NULL,{q(json.dumps(a['row'], ensure_ascii=False))},{q(a['reason'])},{q(a['evidence'])},{q(a['verification'])});")
for c in applied:
    emit(f"INSERT INTO ledger_corrections (nomination_id, field, before_value, after_value, reason, evidence, verification) VALUES ({q(c['nomination_id'])},{q(c['field'])},{q(c['before'])},{q(c['after'])},{q(c['reason'])},{q(c['evidence'])},{q(c['verification'])});")

with open(os.path.join(OUT, 'data.sql'), 'w', encoding='utf-8') as f:
    f.write('SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=1;\nSTART TRANSACTION;\n')
    f.write('\n'.join(out))
    f.write('\nCOMMIT;\n')
json.dump(applied, open(os.path.join(OUT, 'corrections-applied.json'), 'w'), indent=1, ensure_ascii=False)
print(f"rows {len(rows)} winners {winners} titles {len(title_labels)} entities {len(entity_labels)} corrections {len(applied)} additions {len(ADDITIONS)} statements {len(out)}")
