#!/bin/bash
# Rebuild the corrected dataset from the source rows plus every verified decision,
# prove it in a strict MariaDB load, and copy it into the plugin's docs/database.
#   bash release_dataset.sh <dataset_version>
set -euo pipefail
VER=${1:?dataset version, e.g. 2026.09.24-2}
S=/tmp/claude-0/-home-user/cffd3175-765f-5476-bc55-babd0243164e/scratchpad
A=$S/audit
D=/home/user/lunara-plugin-oscars-ledger/docs/database
OUT=$A/final-$VER
M="mariadb --socket=$S/mariadb/run/mysqld.sock -uroot"

rm -rf "$OUT"
python3 $A/assemble_corrections.py "$OUT"
( cd $A/db && DATASET_VERSION=$VER python3 build.py ../rows.json "$OUT/corrections.json" ../wd.json "$OUT/db" "$OUT/additions.json" )

# needs-review: the shipped list minus every item a verified decision settled
python3 - "$OUT" "$D" "$A" <<'EOF'
import json, sys
out, d, a = sys.argv[1:4]
settled = set()
for x in json.load(open(f'{a}/editor_decisions.json')):
    settled.update(x.get('items', []))
keys = {r['key'].split('|')[0]: r for r in json.load(open(f'{out}/needs_review.json'))}
shipped = json.load(open(f'{a}/final/review_for_workbook.json'))
# map shipped entries (no key) to item ids through the assembler's own review list
still = []
for entry in shipped:
    match = [k for k, r in keys.items() if r['change'].get('imdb_id') == entry['imdb_id']]
    if match:
        still.append(entry)
json.dump(still, open(f'{out}/review_for_workbook.json', 'w'), indent=1, ensure_ascii=False)
print('needs review after decisions:', len(still), [e['imdb_id'] for e in still])
EOF

$M -e "DROP DATABASE IF EXISTS ledger_release; CREATE DATABASE ledger_release CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
$M ledger_release < $A/db/schema.sql
$M ledger_release < "$OUT/db/data.sql"
$M -t ledger_release < $A/db/integrity.sql > "$OUT/integrity-result.txt"
cat "$OUT/integrity-result.txt"

$S/venv/bin/python $A/db/make_workbook.py "$OUT/db/oscars-corrected.tsv" "$OUT/db/corrections-applied.json" "$OUT/review_for_workbook.json" "$OUT/oscars-corrected.xlsx" "$OUT/additions.json"

# the full Academy reconciliation must be clean on the release TSV
python3 $A/reconcile.py "$OUT/db/oscars-corrected.tsv" $S/ampas "$OUT/reconciliation.json" > "$OUT/reconciliation-summary.txt"
cat "$OUT/reconciliation-summary.txt"
echo "built $OUT"
