# Filtres des listes

Les listes qui utilisent `base_admin.html.twig` chargent le composant commun
`dist/js/list-filters.js` et `dist/css/list-filters.css`. Il reprend la présentation
Paiements : boutons arrondis, recherche dans les options, listes lisibles et
sélections jaunes. Les filtres catégoriels utilisent de vraies cases à cocher,
avec Tout et Aucun toujours disponibles. Les dépendances, événements TomSelect
et remises à zéro sont conservés. Les sélecteurs de mode de vue (Période) restent
à choix unique ; les années, mois et trimestres des listes sont multiples.
Les champs de dates restent pilotés par les datepickers existants.

## Tout, Aucun, sélection

Une sélection vide signifie **aucun résultat**, jamais « ignorer le filtre ».
Les factures et paiements envoient un mode explicite pour chacun des groupes
particuliers/entreprises : `all`, `none` ou `selected`. Le mode `selected` utilise
les IDs existants. Réinitialiser restaure `all` pour les deux groupes et coche
les options affichées. Une facture adressée à une entreprise reste dans le groupe
entreprises même si elle possède aussi un contact particulier.

## Ajouter une liste

Réutiliser les filtres métier existants (`.filterbar` ou `.toolbar` et champs ayant
un ID explicite). Le composant présente automatiquement les selects et synchronise
TomSelect sans supprimer son instance ni ses callbacks. Ajouter l’ID au registre
`multiIds` et à la table `endpointFilters` (URL Ajax exacte) après avoir adapté le serveur. Les tableaux de bord, calendriers et espaces OF/stagiaire conservent leurs contrôles natifs tant que leur API reste à valeur unique. Le paramètre historique vaut `all`, une
valeur simple, ou un tableau JSON sérialisé (`[]` pour Aucun). Côté Doctrine,
`ChoiceFilter::equals()` applique IN ; `ChoiceFilter::any()` combine en OU les
prédicats du callback tout en conservant le périmètre de l’organisme. Utiliser ces
mêmes fonctions dans les requêtes des indicateurs. Ne pas convertir en entier le
paramètre avant sa lecture. `AccountingPeriodFilter` centralise les périodes.
Les contrôles qui pilotent une plage continue du tableau de bord TVA sont marqués
`data-wf-single` : ils restent des sélecteurs de plage, sans fausse sélection multiple.

Pour une liste DataTables avec pagination serveur, appeler
`TableFilters::prepare()` sur la requête déjà limitée à l'entité, après le comptage
total et avant recherche, comptage filtré et pagination. Le tableau de configuration
contient uniquement des expressions DQL écrites côté serveur. Renvoyer son résultat
dans `filters` du JSON DataTables. Le navigateur envoie `wfFilters` (objet JSON) :
clé absente ou `"*"` = tout, `[]` = aucun, tableau = valeurs autorisées (OU au sein
d'un filtre, ET entre filtres). Les options proviennent de toute l'entité et ne se
réduisent pas à la page courante. Si deux requêtes servent au comptage et aux lignes,
appliquer aussi `TableFilters::apply()` à la seconde.

Sites, engins, attestations, conventions, cours e-learning et règles de charges
utilisent ce protocole. Les tableaux chargés entièrement dans le navigateur et sans
filtres existants reçoivent des filtres de colonnes dérivés de l'ensemble des lignes.
La recherche reste disponible sur les listes sans valeurs catégorielles.

## Recherche et réinitialisation

La recherche globale DataTables est déplacée dans la barre de filtres de sa liste,
avec une loupe et un champ « Rechercher… », sans libellé visible au-dessus.
Le champ original est conservé avec ses événements, sa temporisation et sa valeur.
Les recherches personnalisées déjà présentes (formateurs, QCM…) restent en place,
sans afficher le champ natif masqué en double. Chaque bouton de réinitialisation
affiche seulement une icône, avec un nom accessible et une infobulle.

Pour une disposition particulière, associer explicitement le panneau au tableau
avec `data-list-filters="idDuTableau"` et, si nécessaire, désigner le conteneur
des champs avec `data-list-filter-controls`. Le composant respecte les panneaux
indépendants des onglets et ne réutilise pas les filtres d'un tableau voisin.

## Sessions

La complétude du résumé est informative : elle ne désactive pas Enregistrer.
Les inscriptions peuvent être ajoutées ultérieurement. La validation au clic affiche
les champs à corriger, garde les erreurs visibles et place le focus sur le champ
TomSelect/datepicker concerné. La validation Symfony demeure obligatoire au serveur.
