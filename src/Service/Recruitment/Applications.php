<?php
declare(strict_types=1);
namespace App\Service\Recruitment;
use App\Entity\TrainerApplication;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class Applications
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $root) {}
    public function directory(): string { return $this->root.'/var/storage/recruitment'; }
    public function subjects(): array { return json_decode(file_get_contents($this->root.'/public/dist/recruitment/subjects.json'),true,512,JSON_THROW_ON_ERROR); }
    public function departments(): array { return json_decode(file_get_contents($this->root.'/public/dist/recruitment/departments.json'),true,512,JSON_THROW_ON_ERROR); }
    public function parse(Request $request): TrainerApplication
    {
        $a = new TrainerApplication();
        $get = static function(string $key,int $max,bool $required=true) use($request): string {
            $v=trim($request->request->getString($key));
            if (($required && $v==='') || mb_strlen($v)>$max) throw new \InvalidArgumentException('Veuillez vérifier le champ « '.$key.' ».');
            return $v;
        };
        $a->firstName=$get('prenom',100); $a->lastName=$get('nom',100);
        $a->email=mb_strtolower($get('email',180));
        if (!filter_var($a->email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('L’adresse email est invalide.');
        $a->phone=$get('telephone',30);
        if(!preg_match('/^[+0-9(). \-]{6,30}$/D',$a->phone)) throw new \InvalidArgumentException('Le téléphone est invalide.');
        $a->details=['company'=>$get('societe',100),'address'=>$get('adresse',255),'postalCode'=>$get('codePostal',5),'city'=>$get('ville',100),'presentation'=>$get('presentation',4000,false)];
        if(!preg_match('/^\d{5}$/D',$a->details['postalCode'])) throw new \InvalidArgumentException('Le code postal doit comporter 5 chiffres.');
        $siret=preg_replace('/\s/','',$get('siret',30));
        if(!preg_match('/^\d{14}$/D',$siret)) throw new \InvalidArgumentException('Le SIRET doit comporter 14 chiffres.');
        $a->details['siret']=$siret; $a->details['vat']=$request->request->getBoolean('tva');
        $a->details['vatNumber']=strtoupper(str_replace(' ','',$get('numeroTva',30,false)));
        if($a->details['vat'] && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,28}$/D',$a->details['vatNumber'])) throw new \InvalidArgumentException('Renseignez votre numéro de TVA intracommunautaire.');
        foreach(['hourlyRate'=>'tarifHoraire','dailyRate'=>'tarifJournalier'] as $property=>$field) {
            $v=str_replace(',','.',$get($field,12,false));
            if($v!=='') {
                if(!preg_match('/^\d{1,6}(\.\d{1,2})?$/D',$v) || (float)$v<=0) throw new \InvalidArgumentException('Indiquez un tarif positif, avec deux décimales maximum.');
                $a->$property=(int)round((float)$v*100);
            }
        }
        if(!$a->hourlyRate && !$a->dailyRate) throw new \InvalidArgumentException('Renseignez au moins un tarif horaire ou journalier.');
        $choices=array_column($this->subjects(),'text','value');
        $subjects=$request->request->all('subjects');
        if(!$subjects || count($subjects)>40) throw new \InvalidArgumentException('Choisissez entre 1 et 40 spécialités.');
        foreach($subjects as $v) {
            if(!is_string($v) || mb_strlen($v)>180 || trim($v)==='') throw new \InvalidArgumentException('Spécialité invalide.');
            $a->subjects[]=isset($choices[$v]) ? $choices[$v] : trim($v);
        }
        $a->subjects=array_values(array_unique($a->subjects));
        $a->nationwide=$request->request->getBoolean('nationwide');
        $a->departments=$request->request->all('departments');
        foreach($a->departments as $code) if(!is_string($code) || !in_array($code,array_column($this->departments(),'value'),true)) throw new \InvalidArgumentException('Département invalide.');
        $a->departments=array_values(array_unique($a->departments));
        if(!$a->nationwide && !$a->departments && !$request->request->getBoolean('remoteTraining')) throw new \InvalidArgumentException('Choisissez vos départements ou toute la France.');
        $a->details['remoteTraining']=$request->request->getBoolean('remoteTraining');
        $a->details['hasNda']=$request->request->getBoolean('hasNda');
        $a->details['nda']=$a->details['hasNda'] ? preg_replace('/\s/', '', $get('nda',30)) : '';
        if($a->details['hasNda'] && !preg_match('/^[0-9]{11}$/D',$a->details['nda'])) throw new \InvalidArgumentException('Le numéro de déclaration d’activité doit comporter 11 chiffres.');
        $a->details['qualiopiStatus']=$get('qualiopiStatus',10,false) ?: 'no';
        if(!in_array($a->details['qualiopiStatus'],['no','yes','pending'],true)) throw new \InvalidArgumentException('Choisissez votre situation Qualiopi.');
        $a->details['qualiopiNumber']=$a->details['qualiopiStatus']==='yes' ? $get('qualiopiNumber',100,false) : '';
        if(!$request->request->getBoolean('accuracy')) throw new \InvalidArgumentException('Veuillez certifier l’exactitude des informations communiquées.');
        $a->details['commitments']=['version'=>1,'accuracy'=>true,'profileReview'=>true,'competenceEvidence'=>$request->request->getBoolean('competenceEvidence'),'qualityDiscussion'=>$request->request->getBoolean('qualityDiscussion'),'acceptedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
        if(!$request->request->getBoolean('consent')) throw new \InvalidArgumentException('Votre accord est nécessaire pour étudier et transmettre votre candidature.');
        return $a;
    }
    public function upload(TrainerApplication $a, Request $r): void
    {
        $cv=$r->files->get('cv'); $photo=$r->files->get('photo');
        if(!$cv instanceof UploadedFile || !$cv->isValid() || $cv->getSize()>10000000 || $cv->getMimeType()!=='application/pdf' || !str_starts_with(file_get_contents($cv->getPathname(),false,null,0,5),'%PDF-')) throw new \InvalidArgumentException('Le CV doit être un PDF de 10 Mo maximum.');
        if($photo && (!$photo instanceof UploadedFile || !$photo->isValid() || $photo->getSize()>5000000 || !in_array($photo->getMimeType(),['image/jpeg','image/png'],true))) throw new \InvalidArgumentException('La photo doit être une image JPG ou PNG de 5 Mo maximum.');
        $documents=[];
        foreach(['rcPro'=>'RC Pro','qualiopi'=>'Qualiopi'] as $key=>$label) {
            $file=$r->files->get($key);
            if($key==='qualiopi' && ($a->details['qualiopiStatus'] ?? 'no')!=='yes') continue;
            if(!$file) continue;
            if(!$file instanceof UploadedFile || !$file->isValid() || $file->getSize()>10000000 || $file->getMimeType()!=='application/pdf' || !str_starts_with(file_get_contents($file->getPathname(),false,null,0,5),'%PDF-')) throw new \InvalidArgumentException('Le justificatif '.$label.' doit être un PDF de 10 Mo maximum.');
            $documents[$key]=$file;
        }
        if(!is_dir($this->directory())) mkdir($this->directory(),0700,true);
        $a->cvFilename=bin2hex(random_bytes(24)).'.pdf';
        $a->cvOriginalName=mb_substr(basename($cv->getClientOriginalName()),0,255);
        try {
            $cv->move($this->directory(),$a->cvFilename);
            foreach($documents as $key=>$file) {
                $name=bin2hex(random_bytes(24)).'.pdf';
                $a->details['documents'][$key]=['filename'=>$name,'originalName'=>mb_substr(basename($file->getClientOriginalName()),0,255)];
                $file->move($this->directory(),$name);
            }
            if($photo) {
                $info=getimagesize($photo->getPathname());
                if(!$info || $info[0]*$info[1]>20000000) throw new \InvalidArgumentException('La photo est trop grande (20 mégapixels maximum).');
                $image=@imagecreatefromstring(file_get_contents($photo->getPathname()));
                if(!$image) throw new \InvalidArgumentException('La photo ne peut pas être lue.');
                $a->photoFilename=bin2hex(random_bytes(24)).'.jpg';
                imagejpeg($image,$this->directory().'/'.$a->photoFilename,88); imagedestroy($image);
            }
        } catch(\Throwable $e) { $this->cleanup($a); throw $e; }
    }
    public function cleanup(TrainerApplication $a): void
    {
        foreach([$a->cvFilename,$a->photoFilename,...array_column($a->details['documents'] ?? [],'filename')] as $f) if($f && is_file($this->directory().'/'.basename($f))) unlink($this->directory().'/'.basename($f));
    }
}
