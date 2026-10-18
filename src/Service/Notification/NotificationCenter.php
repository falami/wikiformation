<?php
declare(strict_types=1);
namespace App\Service\Notification;
use App\Entity\{Entite, Utilisateur, Session, Formateur, Inscription, ContratFormateur, Facture, NotificationEvent, FormateurSatisfactionAssignment, RapportFormateur};
use App\Enum\{StatusSession, StatusInscription, ContratFormateurStatus, FactureStatus, PieceType};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class NotificationCenter
{
    public const SPACES = ['admin'=>'Administration','formateur'=>'Formateur','stagiaire'=>'Stagiaire','entreprise'=>'Entreprise','of'=>'Organisme de formation'];
    public function __construct(private EntityManagerInterface $em, private Security $security, private UrlGeneratorInterface $router) {}
    public function spaces(Entite $tenant): array
    {
        $permissions = ['admin'=>TenantPermission::ADMIN,'formateur'=>TenantPermission::FORMATEUR,'stagiaire'=>TenantPermission::STAGIAIRE,'entreprise'=>TenantPermission::ENTREPRISE,'of'=>TenantPermission::OF];
        return array_filter(self::SPACES, fn($label, $key) => $this->security->isGranted($permissions[$key], $tenant), ARRAY_FILTER_USE_BOTH);
    }
    public function items(Entite $tenant, Utilisateur $user, string $space): array
    {
        if (!isset($this->spaces($tenant)[$space])) throw new AccessDeniedException();
        $items = []; $company = $user->getEntreprise();
        $qb = $this->em->getRepository(Session::class)->createQueryBuilder('s')->where('s.entite = :tenant')->setParameter('tenant', $tenant);
        if ($space === 'formateur') {
            $trainer = $this->em->getRepository(Formateur::class)->findOneBy(['entite'=>$tenant,'utilisateur'=>$user]);
            if ($trainer) $qb = $this->em->getRepository(Session::class)->createForFormateurQueryBuilder($tenant, $trainer);
            else $qb->andWhere('1 = 0');
        } elseif ($space === 'stagiaire') {
            $qb->andWhere('EXISTS (SELECT own.id FROM App\Entity\Inscription own WHERE own.session = s AND own.entite = :tenant AND own.stagiaire = :user)')->setParameter('user', $user);
        } elseif ($space === 'entreprise') {
            if (!$company) $qb->andWhere('1 = 0');
            else $qb->andWhere('EXISTS (SELECT own.id FROM App\Entity\Inscription own WHERE own.session = s AND own.entite = :tenant AND own.entreprise = :company)')->setParameter('company', $company);
        } elseif ($space === 'of') {
            if (!$company) $qb->andWhere('1 = 0');
            else $qb->andWhere('s.organismeFormation = :company')->setParameter('company', $company);
        }
        $sessions = $qb->orderBy('s.id','DESC')->getQuery()->getResult();
        $sessionIds = []; $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        foreach ($sessions as $s) {
            $sessionIds[] = $s->getId();
            $url = $this->sessionUrl($tenant, $s, $space);
            $end = $s->getDateFin(); $ended = $end && $end < $now;
            $active = !in_array($s->getStatus(), [StatusSession::CANCELED, StatusSession::DONE], true);
            $context = $s->getCode().' · '.($s->getFormation()?->getTitre() ?: $s->getFormationIntituleLibre());
            $this->add($items, 'session:'.$s->getId().':'.$s->getStatus()->value, 'progress', 'info', 'Session · '.$s->getStatus()->label(), $context, $url, null, $s->getId());
            foreach ($s->getInscriptions() as $i) {
                if ($i->getEntite()?->getId() !== $tenant->getId()) continue;
                if ($space === 'stagiaire' && $i->getStagiaire()?->getId() !== $user->getId()) continue;
                if ($space === 'entreprise' && $i->getEntreprise()?->getId() !== $company?->getId()) continue;
                if (in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) continue;
                $learner = $i->getStagiaire(); if (!$learner) continue;
                $name = trim($learner->getPrenom().' '.$learner->getNom());
                $dossierUrl = match ($space) {
                    'admin' => $this->url('app_administrateur_dossier_edit',$tenant,['id'=>$i->getId()]),
                    'stagiaire' => $this->url('app_stagiaire_dossier_edit',$tenant,['id'=>$i->getId()]),
                    default => $url,
                };
                $this->add($items,'inscription:'.$i->getId().':'.$i->getStatus()->value,'progress','info','Dossier · '.$i->getStatus()->label(),$name.' · '.$context,$dossierUrl,null,$s->getId());
                $dossier = $i->getDossier();
                $pieces = $dossier && $dossier->getEntite()?->getId() === $tenant->getId() ? $dossier->getPieces() : [];
                $validated = []; $deposited = [];
                foreach ($pieces as $piece) {
                    if ($piece->getEntite()?->getId() !== $tenant->getId()) continue;
                    $deposited[] = $piece->getType()->value;
                    if ($piece->isValide()) $validated[] = $piece->getType()->value;
                    $this->add($items,'piece:'.$piece->getId().':'.(int)$piece->isValide(),'documents','info',($piece->isValide()?'Pièce validée':'Pièce déposée · à valider').' · '.$piece->getType()->label(),$name.' · '.$context,in_array($space,['admin','stagiaire'],true)?$this->url($space==='admin'?'app_administrateur_dossier_piece_download':'app_stagiaire_dossier_piece_download',$tenant,['piece'=>$piece->getId()]):$dossierUrl,$piece->getUploadedAt(),$s->getId());
                }
                $missing = array_diff($s->getPiecesObligatoires(),$validated);
                if ($active && $missing) {
                    $labels = array_map(fn($v)=>PieceType::tryFrom($v)?->label() ?? $v,$missing);
                    $awaiting = !array_diff($missing,$deposited);
                    $this->add($items,'dossier:'.$i->getId().':'.hash('sha256',implode(',',$missing)), 'documents','action', $awaiting ? 'Dossier en attente de validation' : 'Documents à compléter', $name.' · '.implode(', ',$labels).' · '.$context,$dossierUrl,null,$s->getId());
                }
                if ($active && $ended && !$s->isSousTraitance()) {
                    foreach (self::missingAttendance($s,$i,$learner,$now) as $day=>$periods) {
                        $this->add($items,'attendance:'.$i->getId().':'.$day.':'.implode('-',$periods),'attendance','action','Émargement à compléter · '.(new \DateTimeImmutable($day))->format('d/m/Y'),$name.' · '.implode(' / ',$periods).' · '.$context,$url,null,$s->getId());
                    }
                }
                if ($ended && $s->getStatus() !== StatusSession::CANCELED && !$s->isSousTraitance()) {
                    $submitted = false;
                    foreach ($s->getSatisfactionAssignments() as $a) if ($a->getEntite()?->getId()===$tenant->getId() && $a->getStagiaire()?->getId()===$learner->getId() && $a->getAttempt()?->isSubmitted()) {
                        $submitted = true;
                        $this->add($items,'feedback:'.$a->getId(),'feedback','info','Appréciation stagiaire complétée',$name.' · '.$context,$url,$a->getAttempt()->getSubmittedAt(),$s->getId());
                    }
                    if (!$submitted) $this->add($items,'feedback-pending:'.$i->getId(),'feedback','action','Appréciation stagiaire attendue',$name.' · '.$context,$space==='stagiaire'?$this->url('app_stagiaire_satisfaction_fill',$tenant,['inscription'=>$i->getId()]):$url,null,$s->getId());
                }
            }
            foreach ($s->getEmargements() as $e) {
                if ($e->getEntite()?->getId() !== $tenant->getId() || !$e->getSignedAt()) continue;
                if (!$this->personVisible($space,$user,$s,$e->getUtilisateur(),$tenant)) continue;
                $name = trim($e->getUtilisateur()?->getPrenom().' '.$e->getUtilisateur()?->getNom()) ?: 'Participant';
                $this->add($items,'signed:'.$e->getId(),'attendance','info','Émargement signé · '.$e->getDateJour()?->format('d/m/Y').' · '.$e->getPeriode()->value,$name.' · '.$context,$url,$e->getSignedAt(),$s->getId());
            }
            if (in_array($space,['admin','formateur','of'],true)) {
                foreach ($s->getPieces() as $p) if ($p->getEntite()?->getId()===$tenant->getId()) $this->add($items,'session-piece:'.$p->getId(),'documents','info','Document de session · '.$p->getType()?->label(),$context,$url,$p->getUploadedAt(),$s->getId());
            }
            if (in_array($space,['admin','formateur'],true)) {
                foreach ($s->getFormateursEffectifs() as $f) {
                    if ($f->getEntite()?->getId()!==$tenant->getId() || ($space==='formateur' && $f->getUtilisateur()?->getId()!==$user->getId())) continue;
                    $reportFound=false;
                    foreach ($s->getRapportFormateurs() as $report) {
                        if ($report->getEntite()?->getId()!==$tenant->getId() || $report->getFormateur()?->getId()!==$f->getId()) continue;
                        $reportFound=true;
                        $this->add($items,'report:'.$report->getId(),'feedback','info','Compte rendu formateur déposé',$context,$this->url($space==='admin'?'app_administrateur_rapport_edit':'app_formateur_rapport_show',$tenant,['id'=>$report->getId()]),$report->getSubmittedAt(),$s->getId());
                    }
                    foreach ($s->getPieces() as $piece) if ($piece->getEntite()?->getId()===$tenant->getId() && $piece->getType()===\App\Enum\SessionPieceType::COMPTE_RENDU_FORMATEUR) $reportFound=true;
                    if (!$reportFound && $active && $ended && !$s->isSousTraitance()) $this->add($items,'report-pending:'.$s->getId().':'.$f->getId(),'feedback','action','Compte rendu formateur attendu',$context,$space==='formateur'?$this->url('app_formateur_rapport_new',$tenant,['session'=>$s->getId()]):$url,null,$s->getId());
                    $assigned=false;
                    foreach ($s->getFormateurSatisfactionAssignments() as $a) if ($a->getEntite()?->getId()===$tenant->getId() && $a->getFormateur()?->getId()===$f->getUtilisateur()?->getId()) $assigned=true;
                    if ($space==='admin' && !$assigned && $active && $ended && !$s->isSousTraitance()) $this->add($items,'trainer-feedback-pending:'.$s->getId().':'.$f->getId(),'feedback','action','Questionnaire formateur à affecter',$context,$space==='formateur'?$this->url('app_formateur_satisfaction_fill',$tenant,['session'=>$s->getId()]):$url,null,$s->getId());
                }
            }
            if ($space==='formateur' && $active && $ended && !$s->isSousTraitance()) {
                foreach (self::missingAttendance($s,null,$user,$now,true) as $day=>$periods) $this->add($items,'trainer-attendance:'.$s->getId().':'.$day.':'.implode('-',$periods),'attendance','action','Votre émargement est attendu', (new \DateTimeImmutable($day))->format('d/m/Y').' · '.implode(' / ',$periods).' · '.$context,$url,null,$s->getId());
            }
        }
        if (in_array($space,['admin','formateur'],true)) {
            $contracts = $this->em->getRepository(ContratFormateur::class)->findBy(['entite'=>$tenant],['id'=>'DESC']);
            foreach ($contracts as $c) {
                if ($c->getFormateur()?->getEntite()?->getId()!==$tenant->getId()) continue;
                if ($space==='formateur' && $c->getFormateur()?->getUtilisateur()?->getId()!==$user->getId()) continue;
                if ($space==='formateur' && $c->getStatus()===ContratFormateurStatus::BROUILLON) continue;
                $signed = (bool)$c->getSignatureAt();
                $route = $space==='admin'?'app_administrateur_formateurs_contrats_show':'app_formateur_contrat_sign';
                $this->add($items,'contract:'.$c->getId().':'.$c->getVersionNumero().':'.$c->getStatus()->value,'contracts',$c->getStatus()===ContratFormateurStatus::ENVOYE && !$signed?'action':'info', 'Contrat formateur · '.$c->getStatus()->label(),$c->getNumero().' · '.trim($c->getFormateur()?->getUtilisateur()?->getPrenom().' '.$c->getFormateur()?->getUtilisateur()?->getNom()),$this->url($route,$tenant,[$space==='admin'?'id':'contrat'=>$c->getId()]),$c->getSignatureAt() ?? $c->getDateCreation(),$c->getSession()?->getId());
            }
            if ($sessionIds) {
                foreach ($this->em->getRepository(FormateurSatisfactionAssignment::class)->findBy(['entite'=>$tenant,'session'=>$sessionIds]) as $a) {
                    if ($space==='formateur' && $a->getFormateur()?->getId()!==$user->getId()) continue;
                    $s=$a->getSession(); if (!$s || $s->isSousTraitance() || $s->getStatus()===StatusSession::CANCELED || !$s->getDateFin() || $s->getDateFin()>=$now) continue;
                    $done=$a->getAttempt()?->isSubmitted();
                    $this->add($items,'trainer-feedback:'.$a->getId().':'.(int)$done,'feedback',$done?'info':'action',$done?'Appréciation formateur complétée':'Appréciation formateur attendue',$s->getCode(),$space==='formateur'?$this->url('app_formateur_satisfaction_fill',$tenant,['session'=>$s->getId()]):$this->sessionUrl($tenant,$s,$space),$done?$a->getAttempt()->getSubmittedAt():null,$s->getId());
                }
            }
        }
        if (in_array($space,['admin','entreprise','of'],true)) {
            $criteria=['entite'=>$tenant];
            if ($space!=='admin') { if (!$company) $criteria['id']=0; else $criteria['entrepriseDestinataire']=$company; }
            foreach ($this->em->getRepository(Facture::class)->findBy($criteria,['id'=>'DESC']) as $f) {
                $pending=in_array($f->getStatus(),[FactureStatus::DUE,FactureStatus::PARTIALLY_PAID],true);
                $this->add($items,'invoice:'.$f->getId().':'.$f->getStatus()->value,'invoices',$pending?'action':'info','Facture '.$f->getNumero().' · '.$f->getStatus()->label(),$f->getDestinataireLabel(),$space==='admin'?$this->url('app_administrateur_facture_show',$tenant,['id'=>$f->getId()]):($space==='entreprise'?$this->url('app_entreprise_documents_facture_pdf',$tenant,['id'=>$f->getId()]):$this->url('app_'.$space.'_dashboard',$tenant)),$f->getDateEmission());
            }
        }
        // The durable journal captures changes from installation onward. Recheck current access at every read.
        $eventQuery=$this->em->getRepository(NotificationEvent::class)->createQueryBuilder('n')->where('n.entite = :tenant')->setParameter('tenant',$tenant);
        if ($space!=='admin') {
            $scope='n.session IN (:sessions) AND n.category NOT IN (:privateCategories)';
            $eventQuery->setParameter('sessions',$sessionIds ?: [0])->setParameter('privateCategories',['contracts','invoices']);
            if ($space==='stagiaire') {
                $scope.=' AND (n.subject = :viewer OR n.sourceType = :sessionType)';
                $eventQuery->setParameter('viewer',$user)->setParameter('sessionType','Session');
            } elseif ($space==='entreprise') {
                $scope.=' AND (n.sourceType = :sessionType OR EXISTS (SELECT ni.id FROM App\Entity\Inscription ni WHERE ni.session = n.session AND ni.entite = :tenant AND ni.stagiaire = n.subject AND ni.entreprise = :company))';
                $eventQuery->setParameter('sessionType','Session')->setParameter('company',$company?->getId() ?? 0);
            }
            if ($space==='formateur') {
                $scope='('.$scope.') OR (n.category = :contracts AND n.subject = :viewer)';
                $eventQuery->setParameter('contracts','contracts')->setParameter('viewer',$user);
            } elseif (in_array($space,['entreprise','of'],true)) {
                $scope='('.$scope.') OR (n.category = :invoices AND n.company = :company)';
                $eventQuery->setParameter('invoices','invoices')->setParameter('company',$company?->getId() ?? 0);
            }
            $eventQuery->andWhere('('.$scope.')');
        }
        $events=$eventQuery->orderBy('n.id','DESC')->setMaxResults(1000)->getQuery()->getResult();
        foreach ($events as $event) {
            $s=$event->session;
            if ($space!=='admin') {
                if ($event->category==='contracts' && ($space!=='formateur' || $event->subject?->getId()!==$user->getId())) continue;
                if ($event->category==='invoices') {
                    if (!in_array($space,['entreprise','of'],true) || !$company || $event->company?->getId()!==$company->getId()) continue;
                } elseif ($event->category!=='contracts' && (!$s || !in_array($s->getId(),$sessionIds,true) || ($event->sourceType!=='Session' && !$this->personVisible($space,$user,$s,$event->subject,$tenant)))) continue;
            }
            $url=$s?$this->sessionUrl($tenant,$s,$space):$this->url('app_notifications_index',$tenant,['space'=>$space]);
            if ($event->sourceType==='TrainerInquiry' && $space==='admin' && $event->subject) $url=$this->url('app_administrateur_utilisateur_show',$tenant,['id'=>$event->subject->getId()]);
            if ($space==='admin' && $event->sourceId) {
                if ($event->sourceType==='TrainerDiploma') $url=$this->url('app_admin_trainer_availability',$tenant,['trainers'=>[$event->sourceId]]);
                if ($event->sourceType==='TrainerCharterSignature') $url=$this->url('app_charter_tracking',$tenant,['id'=>$event->sourceId]);
            }
            $this->add($items,'event:'.$event->id,$event->category,'info',$event->title,trim(($s?->getCode() ? $s->getCode().' · ' : '').($event->subject ? trim($event->subject->getPrenom().' '.$event->subject->getNom()) : ''), ' ·'),$url,$event->occurredAt,$s?->getId());
            $items['event:'.$event->id]['actor'] = $event->actor !== '' && $event->actor !== 'Traitement automatique ou accès personnel' ? $event->actor : null;
        }
        $receipts=$this->em->getConnection()->fetchAllAssociative('SELECT item_key, read_at, archived FROM notification_receipt WHERE entite_id = ? AND utilisateur_id = ?',[$tenant->getId(),$user->getId()]);
        foreach ($receipts as $r) if (isset($items[$r['item_key']])) { $items[$r['item_key']]['read']=(bool)$r['read_at']; $items[$r['item_key']]['archived']=(bool)$r['archived']; }
        usort($items,static fn($a,$b)=> ($a['state']==='action'?0:1)<=>($b['state']==='action'?0:1) ?: strcmp($b['date']??'', $a['date']??''));
        return $items;
    }
    public static function missingAttendance(Session $s, ?Inscription $i, Utilisateur $user, \DateTimeImmutable $now, bool $trainer=false): array
    {
        if (!$s->isEmargementEnAttenteRequis()) return [];
        $missing=[];
        foreach ($s->getJours() as $j) {
            $start=$j->getDateDebut(); $end=$j->getDateFin();
            if (!$start || !$end || $end>$now || $end<=$start) continue;
            foreach (['AM'=>$start->format('H:i')<'13:00','PM'=>$end->format('H:i')>'13:00'] as $half=>$needed) if ($needed && (!$trainer || $s->isFormateurUtilisateurSurPeriode($user,$start,$half))) $missing[$start->format('Y-m-d')][$half]=true;
        }
        foreach ($s->getEmargements() as $e) if ($e->getEntite()?->getId()===$s->getEntite()?->getId() && $e->getUtilisateur()?->getId()===$user->getId() && $e->getSignedAt() && ($e->getSignaturePath() || $e->getSignatureDataUrl()) && ($trainer ? in_array($e->getRole(),['trainer','formateur'],true) : $e->getRole()==='stagiaire')) unset($missing[$e->getDateJour()?->format('Y-m-d')][$e->getPeriode()->value]);
        foreach ($i?->getPresencesManuelles() ?? [] as $key=>$record) if (in_array($record['status']??'', ['paper','absent'],true)) { [$day,$half]=explode(':',$key,2)+[null,null]; unset($missing[$day][$half]); }
        return array_map('array_keys',array_filter($missing));
    }
    private function personVisible(string $space, Utilisateur $user, Session $s, ?Utilisateur $subject, Entite $tenant): bool
    {
        if ($space==='stagiaire') return $subject ? $subject->getId()===$user->getId() : false;
        if ($space==='entreprise') {
            if (!$subject) return false;
            foreach ($s->getInscriptions() as $i) if ($i->getEntite()?->getId()===$tenant->getId() && $i->getEntreprise()?->getId()===$user->getEntreprise()?->getId() && $i->getStagiaire()?->getId()===$subject->getId()) return true;
            return false;
        }
        return true;
    }
    private function add(array &$items,string $key,string $category,string $state,string $title,string $detail,string $url,?\DateTimeInterface $date=null,?int $session=null): void
    {
        $items[$key]=['key'=>$key,'category'=>$category,'state'=>$state,'title'=>$title,'detail'=>$detail,'url'=>$url,'date'=>$date?->format(DATE_ATOM),'session'=>$session,'read'=>false,'archived'=>false];
    }
    private function url(string $route,Entite $tenant,array $params=[]): string { return $this->router->generate($route,['entite'=>$tenant->getId()]+$params); }
    private function sessionUrl(Entite $tenant,Session $s,string $space): string
    {
        return match($space) {
            'admin'=>$this->url('app_administrateur_session_show',$tenant,['id'=>$s->getId()]),
            'formateur','stagiaire'=>$this->url('app_'.$space.'_session_show',$tenant,['id'=>$s->getId()]),
            default=>$this->url('app_'.$space.'_dashboard',$tenant,['session'=>$s->getId()]),
        };
    }
}
