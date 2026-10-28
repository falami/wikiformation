<?php
declare(strict_types=1);
namespace App\Service\Convention;

use App\Entity\{Inscription, HabilitationDossier, HabilitationRevision};
use App\Service\Document\RealisationCertificate;
use App\Service\Habilitation\HabilitationDocument;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/** Callers authorize the inscription; shared documents remain scoped to its company. */
final class TrainingExitDocuments
{
    public function __construct(private EntityManagerInterface $em, private RealisationCertificate $certificates,
        private HabilitationDocument $electrical, private PdfManager $pdf, private Environment $twig) {}

    public function habilitations(Inscription $i): array
    {
        return array_values(array_filter($this->em->getRepository(HabilitationDossier::class)->findBy([
            'inscription'=>$i, 'entite'=>$i->getEntite(), 'entreprise'=>$i->getEntreprise(),
        ]), static fn(HabilitationDossier $d): bool => $d->getActive() && !$d->getDeleted()
            && $d->getAvisToken() && in_array($d->getState(), ['awaiting_company', 'published'], true)));
    }

    public function build(Inscription $i): array
    {
        $files = [];
        if ($this->certificates->available($i)) {
            $files['Attestation-fin-formation.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/attestation.html.twig', [
                'entite'=>$i->getEntite(), 'inscription'=>$i, 'session'=>$i->getSession(),
                'stagiaire'=>$i->getStagiaire(), 'formation'=>$i->getSession()->getFormation(),
                'entreprise'=>$i->getEntreprise(), 'attestation'=>$i->getAttestation(),
            ]));
        }
        foreach ($this->habilitations($i) as $d) {
            foreach (['avis'=>$d->getAvisToken(), 'titre'=>$d->getTitreToken()] as $kind=>$token) {
                if (!$token) continue;
                $r = $this->em->getRepository(HabilitationRevision::class)->findOneBy(['dossier'=>$d, 'token'=>$token, 'kind'=>$kind]);
                $identity = $r?->getSnapshot()['identity'] ?? [];
                if (!$r || ($identity['traineeId'] ?? null) !== $i->getStagiaire()?->getId()
                    || ($identity['companyId'] ?? null) !== $i->getEntreprise()?->getId()) continue;
                $files[($kind === 'avis' ? 'Avis-post-formation-' : 'Titre-habilitation-').$d->getId().'.pdf'] = $this->electrical->bytes($r, $kind === 'avis');
            }
        }
        foreach ($i->getFactures() as $invoice) {
            if (!$i->getEntreprise() || $invoice->getEntite()?->getId() !== $i->getEntite()?->getId()
                || $invoice->getEntrepriseDestinataire()?->getId() !== $i->getEntreprise()->getId()) continue;
            $files['Facture-'.$invoice->getId().'.pdf'] = $this->pdf->outputPortrait($this->twig->render('pdf/facture.html.twig', ['entite'=>$i->getEntite(), 'facture'=>$invoice]));
        }
        return $files;
    }
}
