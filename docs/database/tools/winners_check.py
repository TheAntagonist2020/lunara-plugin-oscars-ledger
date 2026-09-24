"""Cross-check winner flags against Wikidata's Academy Award statements.

For each Wikidata "award received" (P166) statement whose recipient or work
carries an IMDb ID in the dataset, find the dataset rows for that ID in the
mapped category within the ceremony's year window and compare the winner
flag. Also report dataset winners in the core categories that Wikidata has
no win for (coverage there is close to complete).
"""
import json, re, collections

S = '/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad/audit'
R = json.load(open(f'{S}/rows.json'))
A = json.load(open(f'{S}/wd_awards.json'))

# Wikidata award label -> dataset canonical categories (a Wikidata award can
# cover several historical canonical names).
MAP = {
    'Academy Award for Best Picture': ['BEST PICTURE'],
    'Academy Award for Best Unique and Artistic Production': ['UNIQUE AND ARTISTIC PICTURE'],
    'Academy Award for Best Director': ['DIRECTING', 'DIRECTING (Comedy Picture)', 'DIRECTING (Dramatic Picture)'],
    'Academy Award for Best Actor': ['ACTOR IN A LEADING ROLE'],
    'Academy Award for Best Actress': ['ACTRESS IN A LEADING ROLE'],
    'Academy Award for Best Supporting Actor': ['ACTOR IN A SUPPORTING ROLE'],
    'Academy Award for Best Supporting Actress': ['ACTRESS IN A SUPPORTING ROLE'],
    'Academy Award for Best Original Screenplay': ['WRITING (Original Screenplay)'],
    'Academy Award for Best Story': ['WRITING (Original Story)'],
    'Academy Award for Best Adapted Screenplay': ['WRITING (Adapted Screenplay)'],
    'Academy Award for Best Cinematography': ['CINEMATOGRAPHY', 'CINEMATOGRAPHY (Black-and-White)', 'CINEMATOGRAPHY (Color)'],
    'Academy Award for Best Film Editing': ['FILM EDITING'],
    'Academy Award for Best Production Design': ['ART DIRECTION', 'ART DIRECTION (Black-and-White)', 'ART DIRECTION (Color)'],
    'Academy Award for Best Costume Design': ['COSTUME DESIGN', 'COSTUME DESIGN (Black-and-White)', 'COSTUME DESIGN (Color)'],
    'Academy Award for Best Makeup and Hairstyling': ['MAKEUP AND HAIRSTYLING'],
    'Academy Award for Best Original Score': ['MUSIC (Original Score)', 'MUSIC (Original Song Score or Adaptation Score)'],
    'Academy Award for Best Original Song': ['MUSIC (Original Song)'],
    'Academy Award for Best Visual Effects': ['VISUAL EFFECTS', 'SPECIAL ACHIEVEMENT AWARD (Visual Effects)'],
    'Academy Award for Best Sound': ['SOUND MIXING', 'SOUND RECORDING'],
    'Academy Award for Best Sound Mixing': ['SOUND MIXING', 'SOUND RECORDING'],
    'Academy Award for Best Sound Editing': ['SOUND EDITING', 'SPECIAL ACHIEVEMENT AWARD (Sound Effects Editing)', 'SPECIAL ACHIEVEMENT AWARD (Sound Effects)', 'SPECIAL ACHIEVEMENT AWARD (Sound Editing)'],
    'Academy Award for Best Animated Feature': ['ANIMATED FEATURE FILM'],
    'Academy Award for Best International Feature Film': ['INTERNATIONAL FEATURE FILM', 'SPECIAL FOREIGN LANGUAGE FILM AWARD'],
    'Academy Award for Best Documentary Feature Film': ['DOCUMENTARY (Feature)'],
    'Academy Award for Best Documentary Short Film': ['DOCUMENTARY (Short Subject)'],
    'Academy Award for Best Animated Short Film': ['SHORT FILM (Animated)'],
    'Academy Award for Best Live Action Short Film': ['SHORT FILM (Live Action)', 'SHORT SUBJECT (One-reel)', 'SHORT SUBJECT (Two-reel)', 'SHORT SUBJECT (Color)', 'SHORT SUBJECT (Comedy)', 'SHORT SUBJECT (Novelty)'],
    'Academy Award for Best Casting': ['CASTING'],
    'Academy Award for Best Dance Direction': ['DANCE DIRECTION'],
    'Academy Award for Best Assistant Director': ['ASSISTANT DIRECTOR'],
    'Academy Award for Best Title Writing': ['WRITING (Title Writing)'],
    'Academy Honorary Award': ['HONORARY AWARD'],
}
MAP.update({
    'Academy Award for Best Writing, Original Screenplay': ['WRITING (Original Screenplay)'],
    'Academy Award for Best Writing, Adapted Screenplay': ['WRITING (Adapted Screenplay)'],
    'Academy Award for Best Writing': ['WRITING (Original Screenplay)', 'WRITING (Adapted Screenplay)', 'WRITING (Original Story)'],
    'Academy Award for Best Documentary (Short Subject)': ['DOCUMENTARY (Short Subject)'],
    'Academy Award for Best Live Action Short Film, Comedy': ['SHORT SUBJECT (Comedy)'],
    'Academy Award for Best Original Musical Score': ['MUSIC (Original Score)', 'MUSIC (Original Song Score or Adaptation Score)'],
    'Academy Award for Best Original Dramatic or Comedy Score': ['MUSIC (Original Score)'],
    'Academy Award for Best Original Musical or Comedy Score': ['MUSIC (Original Score)', 'MUSIC (Original Song Score or Adaptation Score)'],
    'Academy Award for Best Original Dramatic Score': ['MUSIC (Original Score)'],
    'Academy Award for Best Original Song Score': ['MUSIC (Original Song Score or Adaptation Score)'],
    'Academy Award for Best Original Score, no Musical': ['MUSIC (Original Score)'],
    'Academy Award for Best Score, Adaptation or Treatment': ['MUSIC (Original Song Score or Adaptation Score)'],
    'Academy Award for Best Cinematography, Black-and-White': ['CINEMATOGRAPHY (Black-and-White)'],
    'Academy Award for Best Cinematography, Color': ['CINEMATOGRAPHY (Color)'],
    'Academy Award for Best Special Effects': ['VISUAL EFFECTS', 'SPECIAL ACHIEVEMENT AWARD (Visual Effects)'],
    'Academy Award for Best Engineering Effects': ['VISUAL EFFECTS'],
    'Academy Award for Achievement in Casting': ['CASTING'],
    'Academy Award for Best Unique and Artistic Picture': ['UNIQUE AND ARTISTIC PICTURE'],
    'Academy Award for Unique and Artistic Production': ['UNIQUE AND ARTISTIC PICTURE'],
    'Academy Award of Commendation': ['AWARD OF COMMENDATION'],
    'Academy Award for Best Director (Dramatic Picture)': ['DIRECTING (Dramatic Picture)'],
    'Academy Award for Best Director (Comedy Picture)': ['DIRECTING (Comedy Picture)'],
    'Academy Award for Best Costume Design, Color': ['COSTUME DESIGN (Color)'],
    'Academy Award for Best Costume Design, Black-and-White': ['COSTUME DESIGN (Black-and-White)'],
    'Academy Award for Best Art Direction, Color': ['ART DIRECTION (Color)'],
    'Academy Award for Best Art Direction, Black and White': ['ART DIRECTION (Black-and-White)'],
    'Academy Award of Merit': ['SCIENTIFIC AND TECHNICAL AWARD (Academy Award of Merit)', 'SCIENTIFIC OR TECHNICAL AWARD (Class I)'],
    'Academy Award for Technical Achievement': ['SCIENTIFIC AND TECHNICAL AWARD (Technical Achievement Award)', 'SCIENTIFIC OR TECHNICAL AWARD (Class III)'],
})

CORE = {'BEST PICTURE', 'DIRECTING', 'ACTOR IN A LEADING ROLE', 'ACTRESS IN A LEADING ROLE',
        'ACTOR IN A SUPPORTING ROLE', 'ACTRESS IN A SUPPORTING ROLE'}

canon = {r['CanonicalCategory'] for r in R}
award_to_cats = {}
unmapped_awards = []
for qid, label in A['categories'].items():
    if label in MAP:
        award_to_cats[qid] = [c for c in MAP[label] if c in canon]
    else:
        unmapped_awards.append(label)
missing_canon = sorted({c for v in MAP.values() for c in v} - canon)


def split(v):
    return [] if v in (None, '') else str(v).split('|')


def window(y):
    a = y.split('/')
    s = int(a[0])
    e = int(a[0][:2] + a[1]) if len(a) > 1 else s
    return s, e + 1          # a ceremony is held the year after its eligibility year


index = collections.defaultdict(list)   # (imdb_id, canonical category) -> rows
for n, r in enumerate(R, start=2):
    ids = set(split(r['FilmId'])) | {x for i in split(r['NomineeIds']) for x in i.split(',')}
    for i in ids:
        if i and i != '?':
            index[(i, r['CanonicalCategory'])].append(n)

flags = []
seen_wins = set()
stats = collections.Counter()
for st in A['statements']:
    if st['prop'] != 'won' or st['award'] not in award_to_cats:
        continue
    ids = [x for x in (st['xid'], st['workid']) if x]
    if not ids or not st['year']:
        stats['won_without_id_or_year'] += 1
        continue
    y = int(st['year'])
    cands = []
    for i in ids:
        for c in award_to_cats[st['award']]:
            for n in index.get((i, c), []):
                s, e = window(R[n - 2]['Year'])
                if s <= y <= e:
                    cands.append(n)
    cands = sorted(set(cands))
    if not cands:
        stats['won_no_matching_row'] += 1
        continue
    # If a work is named, require it in the row's films when possible.
    if st['workid']:
        narrowed = [n for n in cands if st['workid'] in split(R[n - 2]['FilmId'])]
        cands = narrowed or cands
    won_rows = [n for n in cands if R[n - 2]['Winner'] == 1]
    for n in won_rows:
        seen_wins.add((n, st['xid'] or st['workid']))
    if won_rows:
        stats['agree_won'] += 1
    else:
        stats['wikidata_won_dataset_not'] += 1
        flags.append({'type': 'wikidata_says_won', 'award': st['award_label'], 'year': st['year'],
                      'recipient': st['xid'], 'work': st['workid'], 'rows': cands,
                      'row_context': [{k: R[n - 2][k] for k in ('Ceremony', 'Year', 'CanonicalCategory', 'Film', 'Name', 'Winner')} for n in cands]})

# Dataset winners in core categories that no Wikidata win statement covers.
wd_wins = collections.defaultdict(set)   # imdb id -> set of (canonical category, year)
for st in A['statements']:
    if st['prop'] == 'won' and st['award'] in award_to_cats and st['year']:
        for i in (st['xid'], st['workid']):
            if i:
                for c in award_to_cats[st['award']]:
                    wd_wins[i].add((c, int(st['year'])))
for n, r in enumerate(R, start=2):
    if r['Winner'] != 1 or r['CanonicalCategory'] not in CORE:
        continue
    s, e = window(r['Year'])
    ids = [x for i in split(r['NomineeIds']) for x in i.split(',') if x != '?'] + [x for x in split(r['FilmId']) if x != '?']
    hit = any(c == r['CanonicalCategory'] and s <= y <= e for i in ids for (c, y) in wd_wins.get(i, ()))
    if hit:
        stats['core_winner_confirmed'] += 1
    else:
        stats['core_winner_unconfirmed'] += 1
        flags.append({'type': 'dataset_core_winner_not_in_wikidata', 'rows': [n],
                      'row_context': [{k: r[k] for k in ('Ceremony', 'Year', 'CanonicalCategory', 'Film', 'FilmId', 'Name', 'NomineeIds')}]})

json.dump({'flags': flags, 'stats': stats, 'unmapped_awards': unmapped_awards, 'missing_canon': missing_canon},
          open(f'{S}/winner_flags.json', 'w'), indent=1, ensure_ascii=False)
print(dict(stats))
print('unmapped Wikidata awards:', unmapped_awards)
print('mapped names not in dataset:', missing_canon)
print('flags by type:', collections.Counter(f['type'] for f in flags))
