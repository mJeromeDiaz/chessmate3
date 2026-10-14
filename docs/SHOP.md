# Boutique — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Une monnaie, les **couronnes**, et une boutique (maquette « Boutique », 2026-10-13) : gels de la
série quotidienne, aides du Puzzle Streak, erreurs pardonnées, packs ; cosmétiques affichés
« Bientôt ». Aucun argent réel. Les couronnes se gagnent en menant une session au bout (§ 4 bis,
maquette « Coffre », 2026-10-14) ; un admin peut aussi en donner.

Choix validés (2026-10-13) :

- **Gagner des couronnes** (2026-10-14) : à la fin d'une session terminée, un coffre (§ 4 bis) ;
  un admin peut aussi en donner (ou en retirer).
- **Gel automatique** : au retour, les gels en stock couvrent les jours manqués ; la série ne
  bouge pas (un jour gelé ne compte pas) ; boule de glace à la place de la flamme.
- **Aides gratuites inchangées + pardon payant** (puzzles notés, aveugle, coordonnées).
- **Les offres combinent des effets codés** : un admin crée des offres (un article ou un pack),
  jamais un effet nouveau.
- Prix par défaut : gels 60 / 110 / 350 / 620, pass PS 80, aide PS 30, pardons 40 / 40 / 30, packs
  Vacances 450, Filet 140, Marathon 590, Nuit noire 130.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Articles, rayons, mouvements | `App\Enum\Shop\{ItemType, Section, LedgerKind}` |
| Entités | `App\Entity\Shop\{Offer, Slide, Wallet, LedgerEntry, InventoryItem, Freeze}` |
| Catalogue par défaut | `App\Shop\Catalog\DefaultCatalog` (migration `Version20261019090000`, fixtures `DataFixtures\Shop\ShopFixtures`), `StoreView` |
| Solde | `App\Shop\Wallet\{Wallets, CrownAdjustment}` |
| Stock | `App\Shop\Inventory\Inventory`, `Shop\Freeze\FreezeStock`, `Shop\Aid\AidStock`, `Shop\Pardon\{PuzzlePardons, BlindfoldPardons, CoordinatesPardons}` |
| Achat | `App\Shop\Purchase\{Purchaser, PurchaseRefusal}` |
| Gains | `App\Enum\Shop\RewardTier`, `App\Entity\Shop\{RewardRule, SessionReward}`, `App\Shop\Reward\{Rule, RewardRules, RewardCalculator, SessionRewarder}` (implémente `Training\Session\SessionRewardInterface`) |
| API joueur | `App\ApiResource\Shop\*`, `App\State\Shop\*` |
| Admin | `App\Controller\Shop\AdminShopController`, `App\Shop\Admin\{Payload, InvalidPayload, OfferEditor, SlideEditor, AdminCatalog, PlayerWallets, ShopStats}` |
| Front | `front/src/pages/index/boutique.vue`, `components/shop/` (dont `TreasureChest.vue`), `stores/shop.js`, `utils/shop.js`, `utils/shopReward.js`, `composables/shop/useAidPurchase.js` ; admin : `pages/index/admin/boutique.vue`, `components/admin/shop/` (dont `RewardRuleForm.vue`), `utils/admin/shop.js` |

Chaque domaine qui consomme un article possède son interface, implémentée par la boutique et
reliée dans `config/services.yaml` : `Gamification\Streak\FreezeStockInterface`,
`PuzzleStreak\Aid\AidStockInterface`, `Puzzle\Pardon\PardonStockInterface`,
`Blindfold\Pardon\PardonStockInterface`, `Coordinates\Pardon\PardonStockInterface` ; de même pour
les gains, `Training\Session\SessionRewardInterface`. Aucun domaine ne dépend de la boutique.

## 2. Articles (`ItemType`)

| Article | Rayon | Effet | Doc |
|---|---|---|---|
| `streak_freeze_1`, `_2`, `_7`, `_14` | `freeze` | jours manqués couverts (§ 4) | [GAMIFICATION.md](GAMIFICATION.md) |
| `puzzle_streak_pass` | `puzzle_streak` | avant le premier coup : solution montrée, streak gardé, puzzle équivalent ensuite | [PUZZLE_STREAK.md § 3 bis](PUZZLE_STREAK.md) |
| `puzzle_streak_help` | `puzzle_streak` | la pièce à jouer s'allume | idem |
| `puzzle_pardon` | `aids` (puzzles) | puzzle classé raté : classement rendu | [PUZZLES.md](PUZZLES.md), « Erreur pardonnée » |
| `blindfold_pardon` | `aids` (aveugle) | tout le puzzle : erreurs effacées en jeu, ou raté compté résolu | [BLINDFOLD.md § 3 bis](BLINDFOLD.md) |
| `coordinates_pardon` | `aids` (coordonnées) | la première case ratée d'une série ne compte pas | [COORDINATES.md](COORDINATES.md) |

Ajouter un article : un cas de l'enum (jamais renommé), son effet codé, son aspect côté front
(`ITEMS` de `utils/shop.js` : glyphe, couleurs, « Comment ça marche »).

## 3. Modèle de données

- `shop_offer` : `slug` (unique, immuable), `section`, `name`, `subtitle`, `description`,
  `contents` (JSON `{article: quantité}`), `price`, `was_price` (prix barré), `position`,
  `starts_at` / `ends_at` (fenêtre de vente), `suspended`, `coming_soon`. Jamais supprimée :
  suspendue. **En vente** = listée (non suspendue, dans sa fenêtre), pas « bientôt », au moins un
  article.
- `shop_slide` : le carrousel (offre, accroche, image, ordre).
- `shop_wallet` (solde, une ligne par joueur créée au premier mouvement) et `shop_ledger_entry`
  (`kind` `purchase`, `grant` ou `revoke` ; `delta`, `balance_after` ; offre, nom et quantité d'un
  achat ; motif et admin d'un don ou d'un retrait ; « Session « titre » » d'un gain). Le solde
  n'est jamais négatif.
- `shop_inventory_item` : les articles à la quantité (`user_id`, `item`, `quantity`).
- `shop_freeze` : les gels un par un (`days`, `days_left`, `acquired_at`).
- `gamification_frozen_day` : les jours de série couverts par un gel (`Gamification\FrozenDay`).
- `shop_reward_rule` : la règle des gains réglée par un admin (une ligne, `id` 1, absente tant
  qu'elle n'a pas été modifiée : les valeurs par défaut de `Rule` s'appliquent ; elle survit donc à
  une purge des fixtures).
- `shop_session_reward` : un gain par session (`session_id` unique, sans clé étrangère : la
  boutique ne touche pas aux tables de Training), `local_date` (jour local du joueur, pour le
  plafond), exercices et réussites comptés, `paid_seconds` (après plafond), `capped`, `tier`,
  `crowns`, `opened_at` (coffre vu).

Tout est supprimé avec le compte (cascade) et exporté (`boutique.json` : mouvements,
inventaire, gels, jours gelés et gains des sessions).

## 4. Gels de la série

`Shop\Freeze\FreezeStock` (`FreezeStockInterface`) et `Gamification\Streak\StreakDays` :

- Tant que le joueur est absent, si les gels en stock couvrent les jours manqués, la série est
  **protégée** (rien n'est dépensé ; le résumé le dit : `protected`, `freezeDays`) ; le rappel de
  série en danger n'est pas envoyé.
- Au premier exercice du retour (`StreakNoticeWriter`), le trou est gelé : le **plus petit gel
  suffisant** ; sinon les plus courts ensemble (deux gels de 1 jour couvrent 2 jours) ; trop court,
  rien n'est dépensé et la série casse. Les jours non utilisés d'un gel restent (un gel d'une
  semaine qui couvre 5 jours en garde 2).
- Un jour gelé ne compte pas dans la longueur de la série, mais la garde. La semaine affichée
  (carte « Série », célébration) montre une boule de glace ❄ à sa place (`weekRow`).

## 4 bis. Gagner des couronnes (coffre de fin de session)

Règles validées (2026-10-14, maquette « Coffre ») :

- **Une session terminée** (tous ses modules joués ou passés, `SessionClosed` `completed`)
  rapporte des couronnes ; une session abandonnée ou expirée, rien. Une séance lancée seule, hors
  session, non plus.
- **Minutes payées** : chaque séance de la session paie sa durée réelle, mais **au plus une minute
  par exercice terminé** (`RewardCalculator::SECONDS_PER_EXERCISE`) : un chrono laissé tourner ne
  rapporte rien. Le **temps libre** ne compte ni dans les minutes ni dans le palier.
- **Palier** de toute la session (exercices réussis / terminés, seuils de l'écran de résultat) :
  parfait (100 %) ×1,5, presque (80 à 99 %) ×1, en dessous ×0,5.
- Couronnes = secondes payées × couronnes par heure × coefficient, arrondi au plus proche ; par
  défaut **120 par heure**, soit 180 pour une heure parfaite.
- **Plafond** : **60 minutes payées par jour local** du joueur ; au-delà, la session rapporte 0
  (`capped`) et son bilan le dit.
- Taux, coefficients et plafond se règlent dans l'admin (onglet « Gains ») et valent pour les
  sessions suivantes.
- Les couronnes sont **créditées à la clôture**, dans la transaction qui termine la session
  (`SessionManager::closeSession` → `SessionRewarder::grant`, idempotent par l'index unique ; le
  verrou du solde sérialise les gains d'un joueur, donc le plafond tient). Le coffre ne fait que les
  révéler : il s'affiche **une fois** (ouvert, ou fermé sans l'ouvrir : vu dans les deux cas,
  `opened_at`). Un gain de 0 n'a pas de coffre.

Front (`components/shop/TreasureChest.vue`, calculs dans `utils/shopReward.js`) : à la fin du
dernier module, célébration de la série, puis **coffre**, puis résultat de la séance. Trois coups
l'ouvrent ; le premier fond suit le montant rapporté au maximum du jour (moins d'un quart : bleu
nuit, jusqu'à 60 % : violet, au-delà : pourpre et halo doré), le titre final le palier (« Quel
jackpot ! », « Beau trésor ! », « Joli butin ! ») ; pluie de couronnes, solde qui monte, puis
« Voir mes résultats » (le résultat de la séance) et « Dépenser à la boutique ». Une session finie
sur son bilan (dernier module passé) montre son coffre là (« Voir le bilan »), et le bilan affiche
« +N couronnes » (ou le plafond atteint). Mouvement réduit : le coffre ouvert d'emblée. La boutique
réaffiche « Gagner des couronnes » (la règle, les minutes restantes aujourd'hui).

## 5. API joueur

| Endpoint | Rôle |
|---|---|
| `GET /shop` | `{balance, offers, slides, inventory, freezes, earning}` : offres listées (en vente ou « bientôt »), `inventory` par article (un gel compte tant qu'il couvre un jour), gels un par un, `earning` (la règle des gains et `minutesLeftToday`) |
| `POST /shop/rewards/{id}/open` | le coffre d'un gain vu (idempotent) → `{id, crowns, paidMinutes, tier, capped, opened, balance}` ; 404 pour celui d'un autre ; limiteur `shop_write` |
| `POST /shop/purchases` `{offerId, quantity}` | 1 à 9 ; payé d'un coup sur le solde verrouillé, articles en stock, même transaction → l'état de la boutique ; 404 offre inconnue, 409 `detail` `insufficient` ou `not_on_sale`, 422 quantité ; limiteur `shop_write` (60 / 10 min) |

La vue d'une session (`GET /training/sessions/{id}`, la liste, etc.) porte `reward` :
`{id, crowns, paidMinutes, tier, capped, opened}` ou `null`.

Les articles se dépensent là où ils servent : `POST /training/runs/{id}/help` et `pass` à la
soumission (Puzzle Streak), `POST /puzzles/attempts/{id}/pardon`, `POST /training/runs/{id}/pardon`
(aveugle), `config.pardon` d'une série de coordonnées.

## 6. Admin (`/api/admin/shop`, `ROLE_ADMIN`)

Contrôleur simple, réponses JSON ; écritures sous le limiteur `admin_write` (30 / 10 min) ; 422
`{violations}` pour une saisie invalide.

| Endpoint | Rôle |
|---|---|
| `GET /catalog` | toutes les offres (suspendues comprises) et le carrousel |
| `POST /offers`, `PUT /offers/{id}` | créer, modifier (slug immuable ; pas de suppression : suspendre) |
| `POST /slides`, `PUT /slides/{id}`, `DELETE /slides/{id}` | le carrousel |
| `GET /players?search=` | joueurs (email, pseudo, nom ; sans recherche : les plus riches) : solde, dépensé |
| `GET /players/{id}` | solde, inventaire, gels, mouvements |
| `POST /players/{id}/crowns` `{delta, reason}` | donner ou retirer (motif obligatoire) ; 409 sous zéro |
| `GET /reward-rule`, `PUT /reward-rule` `{crownsPerHour, perfectPercent, nearPercent, missedPercent, dailyMinutes}` | la règle des gains (§ 4 bis), avec la date et l'admin de la dernière modification |
| `GET /stats?days=7\|30\|90` | totaux (en circulation, détenteurs, donné, retiré, gagné en session et sessions récompensées, dépensé, achats, acheteurs), ventes par offre, couronnes dépensées et gagnées par jour, articles détenus, jours de gel en stock |

Page `/admin/boutique` (onglet « Boutique » de l'admin) : Offres, Carrousel, Gains, Joueurs,
Statistiques.

## 7. Front

- Page `/boutique` (lien « Boutique » et solde dans le menu et le tiroir) : solde animé, rayons en
  onglets collants (scroll spy), carrousel, gels en cartes, aides par module, packs, cosmétiques
  « Bientôt » (affichage seul) ; feuille d'achat (en bas sur téléphone) : comment ça marche,
  quantité, prix ou ce qui manque, puis célébration.
- **Achat sur place** (`useAidPurchase`) : un article manquant au moment de s'en servir (aide ou
  pass du Puzzle Streak, pardon) ouvre la feuille d'achat sur la page de jeu, sur l'offre à l'unité
  la moins chère ; le chrono d'une séance continue.

## 8. Tests

`tests/Unit/Shop/{CatalogTest, RewardCalculatorTest}.php`, `tests/Unit/Gamification/StreakFreezeTest.php`,
`tests/Functional/Shop/{ShopTest, AdminShopTest, SessionRewardTest}.php`, les pardons et aides dans
`Functional/Puzzle/PardonTest.php`, `Functional/Blindfold/PuzzleRunTest.php`,
`Functional/Coordinates/CoordinatesRunTest.php`, `Functional/PuzzleStreak/StreakRunTest.php` ;
Vitest `shop.test.js`, `shop-reward.test.js`, `admin-shop.test.js`, `streak.test.js`, `use-blindfold-puzzle.test.js`,
`use-timeboxed-run.test.js`, `coordinates.test.js` ; Playwright `tests/e2e/shop.spec.js` (un admin
donne des couronnes, achat d'un gel ; puzzle classé raté pardonné, pardon acheté sur place ;
pardon à l'aveugle en jeu, acheté sur place, puzzle résolu ; session terminée : coffre ouvert en
trois coups, solde, « Voir mes résultats », bilan et « Gagner des couronnes »). `closeRunEnd()`
ferme le coffre d'une session s'il précède le résultat. La commande e2e
`app:e2e:seed-user --crowns=N` crée un joueur déjà riche.
