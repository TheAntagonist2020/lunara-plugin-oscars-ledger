"""Write the corrected workbook in the same layout as the source.

    python make_workbook.py <corrected.tsv> <corrections-applied.json> <review.json> <out.xlsx> [additions.json]

Sheets: full_data, Ceremony_1 … Ceremony_98 (unchanged layout), Corrections
(every change with evidence), Needs review (items no source could settle),
About (what was checked). Changed cells are highlighted and carry a comment.
"""
import csv, json, sys, collections
from openpyxl import Workbook
from openpyxl.comments import Comment
from openpyxl.styles import PatternFill, Font, Alignment

TSV, CORR, REVIEW, OUT = sys.argv[1:5]
ADDS = json.load(open(sys.argv[5])) if len(sys.argv) > 5 else []
with open(TSV, encoding='utf-8', newline='') as f:
    rows = list(csv.reader(f, delimiter='\t', quoting=csv.QUOTE_NONE))
header, data = rows[0], rows[1:]
corrections = json.load(open(CORR))
review = json.load(open(REVIEW)) if REVIEW != '-' else []

FILL = PatternFill('solid', fgColor='FFF2CC')
BOLD = Font(bold=True)
changed = collections.defaultdict(list)          # (nomination_id, field) -> corrections
for c in corrections:
    changed[(c['nomination_id'], c['field'])].append(c)


def typed(col, v):
    if v == '':
        return None
    if col == 'Ceremony':
        return int(v)
    if col == 'Winner':
        return 1 if v == 'True' else None
    return v


wb = Workbook()
ws = wb.active
ws.title = 'full_data'


first_added = len(data) - len(ADDS) + 1        # added rows sit after the source's last row


def write_sheet(sheet, subset):
    sheet.append(header)
    for c in sheet[1]:
        c.font = BOLD
    for nid, r in subset:
        sheet.append([typed(h, v) for h, v in zip(header, r)])
        if ADDS and nid >= first_added:
            for k in range(1, len(header) + 1):
                sheet.cell(row=sheet.max_row, column=k).fill = FILL
            sheet.cell(row=sheet.max_row, column=1).comment = Comment('Added: ' + ADDS[nid - first_added]['evidence'][:1400], 'Lunara audit')
        for k, h in enumerate(header, start=1):
            hits = changed.get((nid, h))
            if hits:
                cell = sheet.cell(row=sheet.max_row, column=k)
                cell.fill = FILL
                cell.comment = Comment('\n'.join(f"Was: {x['before']}\nWhy: {x['reason']}" for x in hits)[:1500], 'Lunara audit')
    sheet.freeze_panes = 'A2'


indexed = list(enumerate(data, start=1))
write_sheet(ws, indexed)
by_cer = collections.defaultdict(list)
for nid, r in indexed:
    by_cer[int(r[0])].append((nid, r))
for c in sorted(by_cer):
    write_sheet(wb.create_sheet(f'Ceremony_{c}'), by_cer[c])

cs = wb.create_sheet('Corrections', 0)
cs.append(['Row', 'Ceremony', 'Category', 'Field', 'Before', 'After', 'Reason', 'Evidence', 'How it was confirmed'])
for c in cs[1]:
    c.font = BOLD
for c in sorted(corrections, key=lambda x: (x['nomination_id'], x['field'])):
    r = data[c['nomination_id'] - 1]
    cs.append([c['nomination_id'] + 1, int(r[0]), r[3], c['field'], c['before'], c['after'], c['reason'], c['evidence'], c['verification']])
for k, a in enumerate(ADDS):
    r = data[first_added - 1 + k]
    cs.append([first_added + k + 1, int(r[0]), r[3], '(row added)', None, r[13] or r[7], a['reason'], a['evidence'], a['verification']])
for col, w in zip('ABCDEFGHI', (7, 9, 30, 12, 40, 40, 18, 70, 30)):
    cs.column_dimensions[col].width = w
for row in cs.iter_rows(min_row=2):
    for cell in row:
        cell.alignment = Alignment(wrap_text=True, vertical='top')
cs.freeze_panes = 'A2'

if review:
    rs = wb.create_sheet('Needs review', 1)
    rs.append(['Row(s)', 'IMDb ID', 'Credited as', 'Question', 'What was tried'])
    for c in rs[1]:
        c.font = BOLD
    for x in review:
        rs.append([', '.join(str(n + 1) for n in x['rows']), x['imdb_id'], x.get('label', ''), x.get('question', ''), x.get('tried', '')])
    for col, w in zip('ABCDE', (14, 13, 30, 60, 70)):
        rs.column_dimensions[col].width = w

wb.save(OUT)
print('wrote', OUT, 'rows', len(data), 'corrections', len(corrections), 'review', len(review))
