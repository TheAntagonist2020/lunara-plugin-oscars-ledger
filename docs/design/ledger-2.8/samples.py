import json, sys
sys.argv = ['x']
exec(open('reference_serializer.py').read().split('report = {}')[0])
for nid in (8165, 2):
    print(json.dumps(nomination(nid, embed=('titles','credits','links')), ensure_ascii=False, indent=1))
print(json.dumps(nomination(526, embed=('credits','corrections'), fields=('ceremony','category','winner','credit_line','corrected','credits','corrections')), ensure_ascii=False, indent=1))
print(json.dumps(nomination(12067, embed=(), fields=('ceremony','year_label','category','winner','note','group','imdb_ids')), ensure_ascii=False))
print('TOKEN', TOKEN)
