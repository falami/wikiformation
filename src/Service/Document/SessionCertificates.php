<?php
namespace App\Service\Document;
use App\Entity\{Attestation,Inscription};
use App\Enum\StatusSession;
final class SessionCertificates {
 public function __construct(private RealisationCertificate $certificate) {}
 public function prepare(Inscription $inscription): ?Attestation {
  $session=$inscription->getSession();
  if (!$session || $session->isSousTraitance() || $session->getStatus() !== StatusSession::DONE || $inscription->getAttestation() || !$session->getCreateur() || $inscription->getEntite() !== $session->getEntite()) return null;
  $data=$this->certificate->data($inscription);
  if (!$data['ready']) return null;
  $att=(new Attestation())->setInscription($inscription)->setEntite($session->getEntite())->setCreateur($session->getCreateur())->setDureeHeures($data['hours'])->setDateDelivrance(new \DateTimeImmutable('today'))->setReussi($inscription->isReussi());
  // A certificate of attendance does not assert an examination result.
  $inscription->setAttestation($att);
  return $att;
 }
}
