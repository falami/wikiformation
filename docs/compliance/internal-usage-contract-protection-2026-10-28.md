# Protection du contrat interne et du portail Stripe

Les routes d’estimation et d’application d’un changement d’offre refusent le
contrat interne avant tout appel Stripe. La protection utilise l’identité
I.T. For You ou l’existence de sa réservation persistante : une modification
ultérieure du SIRET ne permet pas de convertir la base de 0 € en offre payante.
La page Abonnement retire les offres, la modale de changement et la proposition
de résiliation du contrat interne.

Les sessions de portail internes demandent une configuration dédiée, distincte
de la configuration par défaut. Seules la consultation des factures et la mise
à jour du moyen de paiement sont activées. Les modifications du client, du plan
et l’annulation de l’abonnement y sont désactivées. La configuration est relue
depuis Stripe et contrôlée avant la création d’une session ; une configuration
incohérente bloque son ouverture. Les erreurs reviennent à l’espace facturation
avec un message sûr. Les abonnements ordinaires conservent leur portail actuel.

La clé d’idempotence de création est stable. Après expiration de sa rétention
par Stripe, une ouverture ultérieure peut créer une nouvelle configuration
équivalente : cela ne crée ni abonnement ni paiement, et ne modifie pas les
configurations déjà présentes. Aucun identifiant de configuration n’est conservé
localement pour ce premier raccordement. Les anciennes sessions de portail déjà
émises ne sont pas révoquées par ce code ; une action réalisée depuis une ancienne
session ou directement dans Stripe reste possible et sera rejetée par le contrôle
de cohérence du webhook si elle change le prix interne. Une vérification des accès
Stripe et des anciennes sessions reste nécessaire à la mise en service.

Tests : refus des mutations avant Stripe, maintien des droits/identifiants,
protection après changement d’identité, configuration dédiée, refus d’une
configuration modifiée, maintien du portail public, retour sûr en cas d’erreur.
Aucune requête réelle Stripe n’a été effectuée.

Sources officielles vérifiées : [création d’une configuration de portail](https://docs.stripe.com/api/customer_portal/configurations/create)
et [configuration explicite d’une session](https://docs.stripe.com/api/customer_portal/sessions/create).
