# Dossier de fin de formation

Dans **Conventions → Actions → Envoyer le dossier à l’entreprise**, puis sur l’écran de vérification : téléchargement ZIP, destinataire et confirmation avant envoi. Le bouton existe également dans la fiche convention.

L’adresse destinataire est `Entreprise.email`, sur l’entreprise directement rattachée à la convention (aucune adresse fournie par le navigateur n’est acceptée). L’envoi est proposé lorsque la session est achevée et non annulée. Le transport et l’historique existants sont utilisés ; aucun envoi automatique n’est déclenché.

Dans **Espace entreprise → Documents → Conventions → Dossier**, l’entreprise dispose du même ZIP, accessible uniquement après authentification et contrôle du rattachement entreprise/organisme.

Contenu : original figé de la convention si elle est signée (aperçu sinon), un PDF d’émargement individuel par stagiaire regroupant ses journées et les signatures des formateurs, appréciations électroniques soumises, inventaire. Les participants sont ceux des inscriptions de cette convention et ses invités actifs, jamais tous les participants de la session. Pour une session sous-traitée, seuls la convention et l’inventaire sont inclus.

Les signatures absentes restent vides et les appréciations non soumises sont signalées. Les pièces globales déposées sur une session ne sont pas jointes automatiquement : elles ne permettent pas d’identifier avec certitude les participants de la convention. Un PDF signé introuvable provoque une erreur, jamais une régénération silencieuse.

L’archive est produite à la demande, sans stockage public, et sa copie temporaire est supprimée. Taille maximale de l’archive envoyée : 18 Mo. Au-delà, le téléchargement reste possible. Le contenu téléchargé est à jour au moment de la demande ; le ZIP reçu par email constitue la copie au moment de l’envoi.

Vérification : `php vendor/bin/phpunit tests/Integration/ConventionDossierTest.php tests/Integration/IndividualAttendanceSheetsTest.php` avec base SQLite temporaire et transport mail simulé.
