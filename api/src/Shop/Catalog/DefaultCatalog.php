<?php

declare(strict_types=1);

namespace App\Shop\Catalog;

use Symfony\Component\Uid\Uuid;

/**
 * The store's default catalogue (docs/SHOP.md, prices validated 2026-10-13): created by the
 * migration and by the fixtures (which purge the database); the admin changes it afterwards.
 * Offers are known by their slug: installing again skips the ones already there.
 */
final class DefaultCatalog
{
    /**
     * slug, section, name, subtitle, description, contents, price, struck price, position, coming soon
     *
     * @var list<array{string, string, string, string|null, string, list<array{item: string, quantity: int}>, int, int|null, int, bool}>
     */
    public const OFFERS = [
        ['freeze_1', 'freeze', 'Gel 1 jour', 'Le dépannage', 'Un jour manqué, ta série reste intacte.', [['item' => 'streak_freeze_1', 'quantity' => 1]], 60, null, 1, false],
        ['freeze_2', 'freeze', 'Gel 2 jours', 'Le week-end', 'Jusqu’à deux jours manqués, ta série reste intacte.', [['item' => 'streak_freeze_2', 'quantity' => 1]], 110, 120, 2, false],
        ['freeze_7', 'freeze', 'Gel 1 semaine', 'Les vacances', 'Jusqu’à sept jours manqués, ta série reste intacte.', [['item' => 'streak_freeze_7', 'quantity' => 1]], 350, 420, 3, false],
        ['freeze_14', 'freeze', 'Gel 2 semaines', 'Le grand voyage', 'Jusqu’à quatorze jours manqués, ta série reste intacte.', [['item' => 'streak_freeze_14', 'quantity' => 1]], 620, 840, 4, false],
        ['puzzle_streak_pass', 'puzzle_streak', 'Pass Puzzle Streak', null, 'Montre la solution, ton streak continue, et tu enchaînes sur un puzzle équivalent.', [['item' => 'puzzle_streak_pass', 'quantity' => 1]], 80, null, 1, false],
        ['puzzle_streak_help', 'puzzle_streak', 'Aide Puzzle Streak', null, 'Surligne la pièce à jouer. Le coup, c’est toi qui le trouves.', [['item' => 'puzzle_streak_help', 'quantity' => 1]], 30, null, 2, false],
        ['puzzle_pardon', 'aids', 'Erreur pardonnée', null, 'Un puzzle classé raté sans perdre de points.', [['item' => 'puzzle_pardon', 'quantity' => 1]], 40, null, 1, false],
        ['blindfold_pardon', 'aids', 'Erreur pardonnée', null, 'Un puzzle à l’aveugle : tes erreurs effacées, ou le raté compté comme résolu.', [['item' => 'blindfold_pardon', 'quantity' => 1]], 40, null, 2, false],
        ['coordinates_pardon', 'aids', 'Erreur pardonnée', null, 'Une case ratée ne compte pas dans ta série de coordonnées.', [['item' => 'coordinates_pardon', 'quantity' => 1]], 30, null, 3, false],
        ['pack_holidays', 'packs', 'Pack Vacances', null, 'Gel 1 semaine et 3 pass Puzzle Streak. Pars tranquille.', [['item' => 'streak_freeze_7', 'quantity' => 1], ['item' => 'puzzle_streak_pass', 'quantity' => 3]], 450, 590, 1, false],
        ['pack_marathon', 'packs', 'Pack Marathon', null, 'Gel 2 semaines et 5 aides Puzzle Streak. Ta série ne craint plus rien.', [['item' => 'streak_freeze_14', 'quantity' => 1], ['item' => 'puzzle_streak_help', 'quantity' => 5]], 590, 770, 2, false],
        ['pack_safety', 'packs', 'Pack Filet de sécurité', null, '3 erreurs pardonnées et 3 aides Puzzle Streak. Tes points dorment tranquilles.', [['item' => 'puzzle_pardon', 'quantity' => 3], ['item' => 'puzzle_streak_help', 'quantity' => 3]], 140, 210, 3, false],
        ['pack_night', 'packs', 'Pack Nuit noire', null, '3 erreurs pardonnées à l’aveugle et 1 aux coordonnées.', [['item' => 'blindfold_pardon', 'quantity' => 3], ['item' => 'coordinates_pardon', 'quantity' => 1]], 130, 150, 4, false],
        ['wheel_ticket', 'wheel', 'Ticket de roue', null, 'Un tour de roue : 300 cristaux et de superbes cadeaux à gagner.', [['item' => 'wheel_ticket', 'quantity' => 1]], 20, null, 1, false],
        ['cosmetic_sounds', 'cosmetics', 'Pack de sons', null, 'Coups, captures, mats : choisis ta bande-son.', [], 0, null, 1, true],
        ['cosmetic_animations', 'cosmetics', 'Pack d’animations', null, 'Ton échec et mat ne passera plus inaperçu.', [], 0, null, 2, true],
        ['cosmetic_outfits', 'cosmetics', 'Déguisements des profs', null, 'Albert en costume ? On y travaille.', [], 0, null, 3, true],
    ];

    /** offer slug, catchline, professor's picture */
    public const SLIDES = [
        ['pack_holidays', 'OFFRE DU MOMENT', 'lizy-cool'],
        ['pack_marathon', 'POUR LES ACHARNÉS', 'aaron-wink'],
        ['pack_safety', 'ZÉRO STRESS', 'albert-v2-pointer'],
        ['pack_night', 'YEUX FERMÉS', 'albert-cool'],
    ];

    /**
     * The SQL that installs the missing default offers and their slides.
     *
     * @return list<array{string, list<mixed>}>
     */
    public static function statements(\DateTimeImmutable $now): array
    {
        $at = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $statements = [];
        $ids = [];
        foreach (self::OFFERS as [$slug, $section, $name, $subtitle, $description, $contents, $price, $was, $position, $soon]) {
            $ids[$slug] = Uuid::v7()->toBinary();
            $statements[] = [
                'INSERT IGNORE INTO shop_offer (id, slug, section, name, subtitle, description, contents, price, was_price, position, suspended, coming_soon, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)',
                [$ids[$slug], $slug, $section, $name, $subtitle, $description, json_encode($contents, \JSON_THROW_ON_ERROR), $price, $was, $position, $soon ? 1 : 0, $at, $at],
            ];
        }
        foreach (self::SLIDES as $i => [$slug, $tag, $image]) {
            // Only for an offer installed now (INSERT IGNORE kept the older one otherwise).
            $statements[] = [
                'INSERT INTO shop_slide (id, offer_id, tag, image, position, suspended) SELECT ?, id, ?, ?, ?, 0 FROM shop_offer WHERE id = ?',
                [Uuid::v7()->toBinary(), $tag, $image, $i + 1, $ids[$slug] ?? throw new \LogicException(\sprintf('No offer "%s".', $slug))],
            ];
        }

        return $statements;
    }
}
