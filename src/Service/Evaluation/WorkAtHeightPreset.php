<?php

declare(strict_types=1);

namespace App\Service\Evaluation;

/** Editable transcription of the organisation's supplied documents, never an active model by default. */
final class WorkAtHeightPreset
{
    public function schema(): array
    {
        $question = static function (int $number, string $label, string $type, int $points, array $options, array $correct): array {
            return ['id' => 'q'.$number, 'label' => $label, 'type' => $type, 'points' => $points,
                'options' => array_map(static fn (int $i, string $text): array => ['id' => 'o'.($i + 1), 'label' => $text], array_keys($options), $options),
                'correct' => array_map(static fn (int $i): string => 'o'.$i, $correct)];
        };
        $theory = [
            $question(1, 'Le travail en hauteur est-il autorisé aux moins de 18 ans ?', 'single', 1, ['Oui', 'Non'], [2]),
            $question(2, 'En cas d’accident du travail, qui peut être reconnu responsable pénalement ?', 'multiple', 2, ['Le chef de chantier', 'L’ouvrier', 'L’employeur', 'Le coordonnateur de sécurité'], [1, 2, 3, 4]),
            $question(3, 'Puis-je travailler seul en hauteur ?', 'single', 1, ['Oui', 'Non'], [2]),
            $question(4, 'En l’absence de protections collectives, j’utilise des EPI dès que :', 'single', 1, ['Il y a un risque de chute', 'Il y a un risque de chute de plus de 2 m', 'Il y a un risque de chute de plus de 3 m'], [1]),
            $question(5, 'La vérification périodique réglementaire des EPI est obligatoire tous les :', 'single', 1, ['6 mois', '12 mois', '18 mois'], [2]),
            $question(6, 'La vérification périodique réglementaire des équipements de travail est effectuée par :', 'multiple', 2, ['L’utilisateur', 'Une personne compétente désignée par le chef d’entreprise', 'Un organisme de contrôle'], [2, 3]),
            $question(7, 'La hauteur minimum d’un garde-corps est de :', 'single', 1, ['0,90 m', '1,00 m', '1,10 m'], [2]),
            $question(8, 'Je risque une chute de facteur 1 avec une longe antichute de 1 m quand :', 'single', 1, ['Je suis attaché au-dessus de la tête', 'Je suis attaché à la hauteur de ma poitrine', 'Je suis attaché au niveau de mes pieds'], [2]),
            $question(9, 'Quand je travaille en hauteur, j’enlève mon casque lorsqu’il fait chaud ou qu’il me gêne.', 'single', 1, ['Oui', 'Non'], [2]),
            $question(10, 'En cas d’accident, remettre les actions dans l’ordre :', 'order', 1, ['Faire alerter les secours', 'Secourir', 'Protéger', 'Examiner'], [3, 4, 1, 2]),
            $question(11, 'Le secours d’une victime en suspension peut être effectué par :', 'multiple', 2, ['Toute personne présente', 'Les secours spécialisés', 'Une personne spécialement formée'], [2, 3]),
            $question(12, 'Pour décrocher un collègue inconscient dans son harnais, j’ai environ 1 heure avant qu’il risque d’avoir des lésions irréversibles.', 'single', 1, ['Oui', 'Non'], [2]),
            $question(13, 'Un garde-corps est constitué de :', 'multiple', 2, ['Lisse', 'Plinthe', 'Plancher', 'Sous-lisse', 'Pieds'], [1, 2, 4]),
            $question(14, 'Qu’est-ce qu’une ligne de vie ?', 'multiple', 1, ['Un dispositif de sécurité utilisé lors des travaux en hauteur, permettant à l’utilisateur de s’y attacher afin de se sécuriser contre les risques de chute.', 'Un sillon de la main prévoyant la longueur de la vie de son propriétaire.', 'Une technique employée chez les pompiers, qui permet d’avancer ou de s’engager dans un milieu enfumé sans prendre trop de risques.'], [1, 3]),
            $question(15, 'Les principaux EPI du travailleur en hauteur sont :', 'multiple', 2, ['Les lunettes de soleil', 'Les gants', 'Le masque respiratoire', 'Le gilet fluorescent', 'Les lunettes de protection', 'Les chaussures de sécurité', 'Le casque', 'Le casque antibruit', 'Le parachute', 'Le harnais avec un système antichute'], [2, 6, 7, 10]),
        ];
        $practice = [];
        foreach ([
            ['Vérification du harnais avant utilisation', 2],
            ['Vérification générale avant mise en situation (harnais, nœud, mousqueton…)', 4],
            ['Port du harnais suivant les instructions du fabricant', 2],
            ['Port du casque', 2],
            ['Réalisation du double huit (nœud)', 2],
            ['Mise en place du bloqueur sur corde (sens d’utilisation, test de fonctionnement)', 4],
            ['Déplacement vertical en sécurité (double mousquetonnage, rupture d’assurance)', 2],
            ['Stockage du matériel et compte rendu', 2],
        ] as $i => [$label, $points]) {
            $practice[] = ['id' => 'p'.($i + 1), 'label' => $label, 'points' => $points, 'critical' => false, 'allowNa' => true];
        }

        return ['theory' => $theory, 'practice' => $practice, 'theoryThreshold' => null, 'practiceThreshold' => null];
    }
}
