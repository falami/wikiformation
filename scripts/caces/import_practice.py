"""Import practical rubrics from the supplied 2026 recommendation PDFs.

Requires PyMuPDF. Uses table geometry, not reading order, to keep category columns
and non-applicable cells distinct. Fails closed on totals; retains source pages.
"""
import argparse, hashlib, json, re
from pathlib import Path
import pymupdf as pdf

ROOT=Path(__file__).resolve().parents[2]
PAGES={'482':list(range(38,52)),'483':[28],'484':[27,28],'485':[28,29],'486':list(range(28,34)),'487':[28],'489':list(range(34,42)),'490':[26]}

def clean(s):
    s=s.replace('\x07','').replace('\u00ad','').replace('ﬁ','fi').replace('ﬀ','ff')
    s=re.sub(r'(?<=\w)-\s*\n(?=\w)','',s)
    for a,b in [('vérifi er','vérifier'),('vérifi cation','vérification'),('Vérifi er','Vérifier'),('justifi er','justifier'),('défi nis','définis'),('fi n','fin'),('Certifi cat','Certificat')]: s=s.replace(a,b)
    return re.sub(r'\s+',' ',s).strip()

def lines(page,rect):
    result=[]
    for b in page.get_text('dict',clip=pdf.Rect(rect))['blocks']:
        for l in b.get('lines',[]):
            text=clean(''.join(x['text'] for x in l['spans']))
            if text: result.append((l['bbox'][1],text))
    return sorted(result)

def extract(path,n):
    doc=pdf.open(path);page=doc[n-1]
    tables=page.find_tables().tables
    # Page 33 R486 also contains an optional test: only the category continuation.
    table=next(t for t in tables if 'SAVOIR' in page.get_text(clip=pdf.Rect(t.bbox)))
    rows=table.rows
    header=next(row for row in rows if any(c and 'Barème' in page.get_text(clip=pdf.Rect(c)) for c in row.cells))
    bar=next(c for c in header.cells if c and 'Barème' in page.get_text(clip=pdf.Rect(c)))
    bx=bar[0]; output=[];theme=None
    for row in rows:
        candidates=[(i,c,clean(page.get_text(clip=pdf.Rect(c)))) for i,c in enumerate(row.cells) if c and c[2]<=bx+1]
        number=next(((i,c,int(t)) for i,c,t in candidates if re.fullmatch(r'\d{1,2}',t) and c[0]>table.bbox[0]+30),None)
        if not number: continue
        idx,cell,group=number;y0,y1=cell[1],cell[3]
        left=clean(' '.join(page.get_text(clip=pdf.Rect(c)) for c in row.cells[:idx] if c))
        if left and '(' in left: theme=re.sub(r'\s*\([^)]*(?:pts|suite)[^)]*\)','',left).strip()
        if not theme and output:theme=output[-1]['theme']
        rect=(cell[2],y0,bx,y1)
        content=lines(page,rect)
        full=clean(page.get_text(clip=pdf.Rect(rect)))
        # R485 contains overprinted corrections in the publisher's PDF.
        full=full.replace('Vérifer la prése','').replace('Prendre déplac','')
        scores=[]
        for c in row.cells:
            if c and c[0]>=bx-1:
                marks=[(y,int(t)) for y,t in lines(page,c) if re.fullmatch(r'\d{1,2}',t)]
                if marks:scores.append((c,marks))
        output.append({'theme':theme,'group':group,'text':full,'lines':content,'scores':scores,'page':n})
    return output,table.bbox,bx

def column(rows,col,columns,assessment=None):
    themes=[]
    for row in rows:
        # col is a geometric category column (shared merged cells apply to each).
        bx=row['bx'];right=row['right'];x=bx+(right-bx)*(col+.5)/columns
        matches=[marks for rect,marks in row['scores'] if rect[0]<=x<=rect[2]]
        if col==-1:matches=[marks for rect,marks in row['scores']]
        if not matches:continue
        marks=sorted([m for ms in matches for m in ms]);content=row['lines']
        if not themes or themes[-1]['title']!=row['theme'].replace('déchargemt','déchargement').replace('porteengins','porte-engins'):
            t={'title':row['theme'].replace('déchargemt','déchargement').replace('porteengins','porte-engins'),'questions':[]}
            if assessment:
                t['assessment']=assessment
                t['assessmentLabel']={'pelle':'Pelle hydraulique compacte','second-engin':'Second engin compact','chenilles':'Engin à chenilles','pneumatiques-cylindres':'Engin sur pneumatiques ou à cylindres','chariot-1':'Chariot n°1 · catégories 1A / 1B','chariot-2':'Chariot n°2 · catégories 3 / 4 / 5 / 6'}[assessment]
            themes.append(t)
        # Match each scoring baseline to its corresponding criterion; the full
        # evaluation point is retained separately for context and audit.
        starts=[]
        for y,v in marks:
            start=min(range(len(content)),key=lambda j:abs(content[j][0]-y))
            while start>0 and not re.match(r'^(?:[A-ZÀ-ÝÉÈ]|•|x\s)',content[start][1]):start-=1
            starts.append(start)
        for i,((y,points),start) in enumerate(zip(marks,starts)):
            end=starts[i+1] if i+1<len(starts) else len(content)
            label=clean(' '.join(t for yy,t in content[start:end]))
            if len(marks)==1:label=row['text']
            label=re.sub(r'^[•x]\s*','',label)
            if not label:raise ValueError(('Empty criterion',row))
            themes[-1]['questions'].append({'id':f'p{row["page"]}g{row["group"]}c{i+1}'+(f'-{assessment}' if assessment else ''),'label':label,'points':points,'evaluationPoint':str(row['group']), 'instructions':row['text'],'referencePage':row['page'],'sourcePage':row['page']})
    return themes

def main():
    ap=argparse.ArgumentParser();ap.add_argument('source',type=Path);args=ap.parse_args()
    extracted={};sources={}
    for rec,pages in PAGES.items():
        path=next(args.source.glob('R'+rec+'*.pdf'));sources[rec]=path
        for n in pages:
            rows,box,bx=extract(path,n)
            for row in rows:row.update(bx=bx,right=box[2])
            extracted[rec,n]=rows
    plans=[]
    def add(rec,cat,pages,col=0,cols=1,variant='',components=None,note=''):
        parts=[]
        if components:
            for ac,ps,co,cs in components:parts+=column(sum([extracted[rec,p] for p in ps],[]),co,cs,ac)
        else:parts=column(sum([extracted[rec,p] for p in pages],[]),col,cols)
        totals={}
        for t in parts:
            key=t.get('assessment','main');totals[key]=totals.get(key,0)+sum(q['points'] for q in t['questions'])
        if any(v!=100 for v in totals.values()) or not totals:raise ValueError((rec,cat,variant,totals,[(t['title'],sum(q['points'] for q in t['questions'])) for t in parts]))
        key=f'r{rec}-cat{cat.lower()}-pratique-2026'+('09' if rec=='482' else '07')+(('-'+variant) if variant else '')
        if rec=='489' and cat=='3':key+='-referentiel'
        source=sources[rec]
        plans.append({'key':key,'title':f'R.{rec} · Catégorie {cat} · Pratique'+(' · '+variant if variant else ''),'recommendation':rec,'category':cat,'kind':'practice','versionLabel':source.stem.replace('-',' · '),'schema':{'durationMinutes':60,'themes':parts,'reference':{'file':source.name,'sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'pages':pages,'note':note},'source':{'key':key}}})
    for col,variant in [(1,'pelle-motobasculeur'),(2,'pelle-chargeuse'),(3,'pelle-compacteur')]:add('482','A',[38,39],variant=variant,components=[('pelle',[38,39],0,4),('second-engin',[38,39],col,4)],note='Deux engins successifs : pelle hydraulique compacte et second engin désigné. Chaque engin est évalué sur 100 points.')
    add('482','B1',[40]);add('482','B2',[41,42],0,2,'conducteur-accompagnant');add('482','B2',[41,42],1,2,'conducteur-porte');add('482','B3',[43]);add('482','C1',[44],0,2,'chargeuse');add('482','C1',[44],1,2,'chargeuse-pelleteuse')
    for cat,p in [('C2',45),('C3',46),('D',47),('E',48),('F',49)]:add('482',cat,[p])
    add('482','G',[50,51],components=[('chenilles',[50,51],0,1),('pneumatiques-cylindres',[50,51],0,1)],note='Évaluer successivement un engin à chenilles et un engin sur pneumatiques ou à cylindre(s).')
    for col,cat in enumerate(['A','B']):add('483',cat,[28],col,2)
    for cat,p in [('1',27),('2',28)]:add('484',cat,[p])
    for col,cat in enumerate(['1','2']):add('485',cat,[28,29],col,2,note='Palettier : hauteur minimale '+('2,10 m' if cat=='1' else '3,30 m')+'.')
    for cat,ps in [('A',[28,29]),('B',[30,31]),('C',[32,33])]:add('486',cat,ps,-1,2,note='Épreuve avec les deux types de PEMP prévus par la grille ; les critères communs sont évalués une seule fois. Hauteur minimale 4 m plancher pour les déplacements en position haute.')
    for cat,col in [('1',0),('2',0),('3',1)]:add('487',cat,[28],col,2)
    for cat,ps,col,cols in [('1A',[34],0,2),('1B',[34],1,2),('2A',[35],0,2),('2B',[35],1,2),('3',[36,37],0,1),('4',[38],0,1),('5',[39],0,1),('6',[40],0,1)]:add('489',cat,ps,col,cols)
    add('489','7',[41],components=[('chariot-1',[41],0,2),('chariot-2',[41],1,2)],note='Deux chariots successifs : n°1 représentatif de 1A/1B et n°2 représentatif de 3/4/5/6. Chaque chariot est évalué sur 100 points.')
    add('490','Unique',[26])
    for rec,pages in PAGES.items():
        folder=ROOT/'resources/caces'/('reference-'+sources[rec].stem);folder.mkdir(exist_ok=True)
        doc=pdf.open(sources[rec])
        for page in pages:
            doc[page-1].get_pixmap(matrix=pdf.Matrix(1.6,1.6),alpha=False).save(folder/f'page-{page}.png')
    out=ROOT/'resources/caces/practice-2026.json';out.write_text(json.dumps(plans,ensure_ascii=False,indent=2)+'\n')
    print(f'{len(plans)} grilles écrites dans {out}')
    for t in plans:print(t['key'],len(t['schema']['themes']),sum(len(th['questions']) for th in t['schema']['themes']))
if __name__=='__main__':main()
