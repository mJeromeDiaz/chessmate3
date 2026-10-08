<?php

declare(strict_types=1);

namespace App\DataFixtures\Evaluation;

use App\Entity\Evaluation\Position;
use App\Enum\Evaluation\Plan;
use App\Enum\Evaluation\PositionTag;
use App\Enum\Repertoire\Color;
use App\Evaluation\EvaluationRules;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * A few positions to evaluate (docs/EVALUATION.md) so that the module can be played in dev and in
 * e2e: textbook ones whose result is known. The real catalogue is entered by admins.
 */
final class PositionFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();
        foreach ([
            ['rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1', Color::White, 20, Plan::CenterSpace, ['Rien n’est encore joué : le trait n’est qu’un petit avantage.', 'Les deux camps se disputeront le centre.'], 'Compte le matériel, puis regarde qui a le trait.', PositionTag::Opening, 900, 'Position initiale'],
            ['1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1', Color::White, EvaluationRules::WON_CP, Plan::Simplify, ['Le roi noir est coupé de la colonne c.', 'La tour blanche fera le pont pour abriter son roi.', 'Le pion b7 ira à dame.'], 'Le roi noir peut-il revenir devant le pion ?', PositionTag::Endgame, 1500, 'Position de Lucena'],
            ['4k3/8/r7/4PK2/8/8/8/7R b - - 0 1', Color::Black, 0, Plan::ActiveDefense, ['La tour noire tient la 6e rangée : le roi blanc ne peut pas avancer.', 'Si le pion avance en e6, la tour passe derrière et donne échec.'], 'Le roi blanc peut-il entrer sans son pion ?', PositionTag::Endgame, 1600, 'Défense de Philidor'],
            ['8/8/8/4k3/8/4K3/4P3/8 w - - 0 1', Color::White, 0, Plan::ActiveDefense, ['Rois face à face : avec le trait, les Blancs perdent l’opposition.', 'Le pion e2 est bloqué par son roi : aucun coup d’attente.'], 'Qui a l’opposition ?', PositionTag::Endgame, 1200, 'Opposition'],
        ] as [$fen, $turn, $evalCp, $plan, $ideas, $tip, $tag, $rating, $source]) {
            $manager->persist(new Position($fen, $turn, $evalCp, $plan, $ideas, $tip, $tag, $rating, $source, $now));
        }
        $manager->flush();
    }
}
