#!/usr/bin/env python3
"""Independent reference for the corrected Oscars sheet (plan-v5 §4.1; work unit U01).

    python3 tests/tools/corrected_tsv_reference.py [plugin_root]

Prints the sha256 of the corrected TSV, built from data/oscars.csv and the overlay in
data/ledger/ with the Python standard library only, independently of the PHP codec:
RFC parse (tab, '"' enclosure, no escape character), the cell corrections in file order
(each before-checked against the value the previous entry for that cell left), the
backslash-quote decode, then the additions typed by the same table as
AAT_Ledger_Source::addition_cell(). tests/ledger-bundle-contract.php requires the
printed hash to equal manifest.corrected_sha256. Exits 1 on any mismatch.
"""
import csv
import hashlib
import io
import json
import os
import sys

root = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def fail(message):
    sys.stderr.write('corrected_tsv_reference: ' + message + '\n')
    sys.exit(1)


def cell(header, value):
    """The addition typing table: null -> '', string -> itself, Ceremony int 1-999 ->
    digits, Winner true -> 'True' and false -> ''; anything else is refused."""
    if value is None:
        return ''
    if header == 'Winner':
        if value is True:
            return 'True'
        if value is False:
            return ''
        if isinstance(value, str) and value in ('True', ''):
            return value
        fail('addition Winner has an unsupported value %r' % (value,))
    if isinstance(value, str):
        return value
    if header == 'Ceremony' and isinstance(value, int) and not isinstance(value, bool) and 1 <= value <= 999:
        return str(value)
    fail('addition %s has an unsupported value %r' % (header, value))


with open(os.path.join(root, 'data', 'oscars.csv'), encoding='utf-8', newline='') as handle:
    records = list(csv.reader(io.StringIO(handle.read()), delimiter='\t', quotechar='"', escapechar=None, doublequote=True, strict=True))
header, rows = records[0], [list(r) for r in records[1:]]
if any(len(r) != 14 for r in rows) or len(header) != 14:
    fail('a record does not have 14 cells')

with open(os.path.join(root, 'data', 'ledger', 'corrections.json'), encoding='utf-8') as handle:
    corrections = json.load(handle)
with open(os.path.join(root, 'data', 'ledger', 'additions.json'), encoding='utf-8') as handle:
    additions = json.load(handle)

column = {name: i for i, name in enumerate(header)}
for n, entry in enumerate(corrections):
    row, field = entry['nomination_id'], entry['field']
    if not isinstance(row, int) or isinstance(row, bool) or not 1 <= row <= len(rows):
        fail('correction %d targets a row outside the upstream file' % n)
    current = rows[row - 1][column[field]]
    if current != entry['before']:
        fail('correction %d (row %d %s): before does not equal the current value' % (n, row, field))
    rows[row - 1][column[field]] = entry['after']

for r in rows:
    for i, value in enumerate(r):
        r[i] = value.replace('\\"', '"')
        if '\\' in r[i]:
            fail('a backslash remains after the decode')

for element in additions:
    if sorted(element) != ['evidence', 'reason', 'row', 'verification'] or sorted(element['row']) != sorted(header):
        fail('an addition does not have the shipped shape')
    rows.append([cell(name, element['row'][name]) for name in header])

text = '\t'.join(header) + '\n' + ''.join('\t'.join(r) + '\n' for r in rows)
print(hashlib.sha256(text.encode('utf-8')).hexdigest())
