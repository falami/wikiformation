# Historique des conventions

Depuis la fiche de convention :
- **Rattacher les comptes stagiaires** conserve les signatures et le PDF original. Seuls des comptes correspondant exactement aux noms déjà présents peuvent être rattachés. La casse, les espaces et l’ordre prénom/nom sont normalisés. Un nom ambigu ou une personne supplémentaire exige une nouvelle version.
- **Créer une nouvelle version à signer** conserve une copie du PDF signé dans l’historique, puis ouvre un brouillon sans signatures. Les modifications contractuelles se font sur ce brouillon.
- **Consulter le PDF conservé** ouvre l’original archivé. L’historique mentionne l’auteur, la date et le motif. Il ne réapplique jamais une ancienne signature à un document modifié.

Les PDF sont conservés en base, avec les données de la version, sous contrôle d’accès de l’organisme. Inclure cette table dans les sauvegardes. Une convention avec historique ne peut pas être supprimée. Un PDF signé manquant bloque les modifications : son original doit être restauré, pas reconstruit à partir des données actuelles.

Déploiement : appliquer la migration `DoctrineMigrations\Version20261003160000`, puis vider le cache Symfony. Cette migration a été appliquée à la base locale de développement, pas à la production. Elle ne reconstitue pas les versions déjà perdues avant son installation.

## Édition des conventions et entreprises multiples (4 octobre 2026)

La migration `Version20261004030000` ajoute les entreprises complémentaires du
stagiaire et le montant HT personnalisé des conventions. L’entreprise principale
reste compatible avec les données existantes ; les liens supplémentaires ne
donnent aucun rôle de représentant d’entreprise.

Une convention non signée permet de modifier la session, le destinataire,
le devis rattaché, l’intitulé, la durée, les participants, l’effectif, le montant HT,
la TVA et les conditions financières. Le montant personnalisé est prioritaire
pour son PDF et pour le total HT des conventions de la session, sans changer
le devis. Sans montant personnalisé, le devis ou l’estimation tarif × effectif
reste utilisé. Les signatures restent protégées : créer une nouvelle version
pour modifier les éléments contractuels d’un document signé.

Dans la session, la sélection d’un stagiaire propose son entreprise principale
(ou une entreprise associée du même organisme), qui reste modifiable. La case
« Créer et rattacher une nouvelle convention » regroupe les lignes cochées
par entreprise, ou crée une convention individuelle pour un particulier.
Aucun envoi ni signature n’est déclenché par cette case.
