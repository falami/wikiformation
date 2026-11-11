"""Reproducible import of supplied INRS grids. Requires pymupdf; no OCR guesses."""
import pymupdf as fitz, re, json, hashlib, sys
from pathlib import Path
from source_statements import statement_cells
root=Path(sys.argv[1] if len(sys.argv)>1 else '/Users/jeroen/Desktop/CACES/GRILLES')
out=Path('resources/caces'); out.mkdir(exist_ok=True,parents=True)
catalog=[]
for rec in ['482','483','484','485','486','487','489','490']:
 answerfile=root/('R'+rec)/f'R.{rec} REPONSES - V1 - 12-2024.pdf'
 answersdoc=fitz.open(answerfile)
 for variant in range(1,6):
  ap=answersdoc[variant-1]; answers={}; theme=None
  for left,right in [(0,ap.rect.width/2),(ap.rect.width/2,ap.rect.width)]:
   text=ap.get_text(clip=fitz.Rect(left,30,right,ap.rect.height),sort=True)
   for line in text.splitlines():
    m=re.search(r'TH[ÈE]ME\s+(\d+)',line)
    if m:theme=int(m[1]);answers.setdefault(theme,{})
    for m in re.finditer(r'Question\s+(\d+)\s+(VRAI|FAUX)',line):
     assert theme is not None
     answers[theme][int(m[1])]='A' if m[2]=='VRAI' else 'B'
  assert sum(map(len,answers.values()))==100,(rec,variant,answers)
  source=root/('R'+rec)/f'R.{rec} Grille {variant} - V1 - 12-2024.pdf';doc=fitz.open(source)
  key=f'r{rec}-v1-202412-g{variant}';folder=out/key;folder.mkdir(exist_ok=True)
  themes={}; current=None
  for pi,page in enumerate(doc):
   text=page.get_text(sort=True)
   m=re.search(r'TH[ÈE]ME\s+(\d+)\s*:\s*(.+?)(?:\s+\d+\s+POINTS|\n)',text)
   if m:
    current=int(m[1]);themes.setdefault(current,{'title':m[2].strip(),'questions':[]})
   cells=statement_cells(page,rec,variant,pi+1)
   if not cells:continue
   if not (folder/f'page-{pi+1}.png').exists():page.get_pixmap(matrix=fitz.Matrix(1.65,1.65)).save(str(folder/f'page-{pi+1}.png'))
   # Preserve the complete original page: diagrams can span several statements.
   for printed,label in cells:
    n=len(themes[current]['questions'])+1
    assert n in answers[current],(rec,variant,current,n)
    themes[current]['questions'].append({'id':f't{current}q{n}','label':label,'points':1,'correct':answers[current][n],'sourcePage':pi+1,'sourceNumber':n,'printedNumber':printed})
  assert sum(len(t['questions']) for t in themes.values())==100,(rec,variant,'questions',[(t,[q['sourceNumber'] for q in v['questions']]) for t,v in themes.items()])
  for t,theme in themes.items():assert [q['sourceNumber'] for q in theme['questions']]==list(range(1,len(answers[t])+1)),(rec,variant,t)
  item={'key':key,'title':f'R.{rec} · Grille {variant}','recommendation':rec,'kind':'theory','category':'','versionLabel':'V1 · 12/2024','schema':{'durationMinutes':60,'themes':list(themes.values()),'source':{'key':key,'filename':source.name,'sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'answerSha256':hashlib.sha256(answerfile.read_bytes()).hexdigest()}}}
  catalog.append(item)
  print(key, [len(t['questions']) for t in themes.values()],flush=True)
# Practical grids are maintained separately; never erase them on a theory import.
if (out/'catalog.json').exists():
 catalog.extend(item for item in json.loads((out/'catalog.json').read_text()) if item['kind']!='theory')
(out/'catalog.json').write_text(json.dumps(catalog,ensure_ascii=False,indent=2))
