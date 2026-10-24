# Boutique — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Une monnaie, les **cristaux**, et une boutique (maquette « Boutique », 2026-10-13) : gels de la
série quotidienne, aides du Puzzle Streak, erreurs pardonnées, packs ; cosmétiques affichés
« Bientôt ». Aucun argent réel. Les cristaux se gagnent en menant une session au bout (§ 4 bis,
maquette « Coffre », 2026-10-14) ; un admin peut aussi en donner.

> Les cristaux s'appelaient les **couronnes** jusqu'aux maquettes « Boutique cristaux » et « Coffre
> cristaux » (2026-10-17) : renommés à l'écran seulement (gemme `components/shop/CrystalGem.vue`,
> textes fr et en). Le code, l'API, la base et l'export gardent `crowns` (`Wallet`,
> `CrownAdjustment`, `reward.crowns`…).

Choix validés (2026-10-13) :

- **Gagner des cristaux** (2026-10-14) : à la fin d'une session terminée, un coffre (§ 4 bis) ;
  chaque jour joué, le coffre du jour, plus gros le jour d'un badge de série (§ 4 ter,
  2026-10-21) ; un admin peut aussi en donner (ou en retirer).
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
| Entités | `App\Entity\Shop\{Offer, Slide, Wallet, LedgerEntry, InventoryItem, Freeze, ItemGrant, GrantBatch}` |
| Catalogue par défaut | `App\Shop\Catalog\DefaultCatalog` (migration `Version20261019090000`, fixtures `DataFixtures\Shop\ShopFixtures`), `StoreView` |
| Solde | `App\Shop\Wallet\{Wallets, CrownAdjustment}` |
| Stock | `App\Shop\Inventory\Inventory`, `Shop\Freeze\FreezeStock`, `Shop\Aid\AidStock`, `Shop\Pardon\{PuzzlePardons, BlindfoldPardons, CoordinatesPardons}` |
| Achat | `App\Shop\Purchase\{Purchaser, PurchaseRefusal}` |
| Gains | `App\Enum\Shop\RewardTier`, `App\Entity\Shop\{RewardRule, SessionReward, DailyReward}`, `App\Shop\Reward\{Rule, RewardRules, RewardCalculator, SessionRewarder, DailyRewarder}` (implémentent `Training\Session\SessionRewardInterface` et `Gamification\Streak\StreakRewardInterface`), `App\ApiResource\Shop\{Reward, DailyReward}` |
| Dons d'un admin | `App\Shop\Gift\{TicketGrants, GiftNotices}` (§ 4 quater), `App\ApiResource\Shop\{Gift, GiftsSeenInput}` |
| API joueur | `App\ApiResource\Shop\*`, `App\State\Shop\*` |
| Admin | `App\Controller\Shop\AdminShopController`, `App\Shop\Admin\{Payload, InvalidPayload, OfferEditor, SlideEditor, AdminCatalog, PlayerWallets, ShopStats}` |
| Front | `front/src/pages/index/boutique.vue`, `components/shop/` (dont `TreasureChest.vue`, `CrystalGem.vue`, `CrystalPrice.vue`), `stores/shop.js`, `utils/shop.js`, `utils/shopReward.js`, `composables/shop/useAidPurchase.js` ; admin : `pages/index/admin/boutique.vue`, `components/admin/shop/` (dont `RewardRuleForm.vue`), `utils/admin/shop.js` |

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
| `wheel_ticket` | `wheel` | un tour de plus à la roue des cadeaux | § 4 ter |

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
- `shop_wheel_rule` : les chances de la roue (une ligne, `id` 1, absente tant qu'un admin ne les
  a pas changées : les valeurs par défaut s'appliquent) ; `shop_wheel_spin` : les tirages (§ 4 ter).
- `shop_item_grant` : les articles donnés à un joueur par un admin (§ 4 quater) : `item`, `quantity`,
  `message` (montré au joueur), `admin_id`, `batch_id` (le don à tous dont il fait partie, sinon
  null), `created_at`, `seen_at` (message vu) ; `shop_grant_batch` : le journal des dons à tous
  (`item`, `quantity`, `message`, `recipients`, `admin_id`, `created_at`).
- `shop_daily_reward` : le jour (§ 4 ter), une ligne par joueur et jour local
  (`uniq_shop_daily_reward_user_day`) : `streak` atteinte, `badge_days` (le badge du jour, sinon
  null), `daily_crowns` (le tirage), `badge_crowns` (le bonus du badge, 0 sinon), `crowns` (les
  deux), `wheel` (la roue du jour), `opened_at`.

Tout est supprimé avec le compte (cascade) et exporté (`boutique.json` : mouvements,
inventaire, gels, jours gelés, gains des sessions, coffres du jour, tirages de la roue et cadeaux
de l'équipe, sans le compte de l'admin).

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

## 4 bis. Gagner des cristaux (coffre de fin de session)

Règles validées (2026-10-14, maquette « Coffre ») :

- **Une session terminée** (tous ses modules joués ou passés, `SessionClosed` `completed`)
  rapporte des cristaux ; une session abandonnée ou expirée, rien. Une séance lancée seule, hors
  session, non plus (le puzzle rush et la reconstitution aussi : pas de cristaux propres, [PUZZLE_RUSH.md](PUZZLE_RUSH.md), [BLINDFOLD_RECALL.md](BLINDFOLD_RECALL.md)).
- **Minutes payées** : chaque séance de la session paie sa durée réelle, mais **au plus une minute
  par exercice terminé** (`RewardCalculator::SECONDS_PER_EXERCISE`) : un chrono laissé tourner ne
  rapporte rien. Un parcours ([PARCOURS.md](PARCOURS.md)) paie toujours une minute par exercice. Le **temps libre** ne compte ni dans les minutes ni dans le palier.
- **Palier** de toute la session (exercices réussis / terminés, seuils de l'écran de résultat) :
  parfait (100 %) ×1,5, presque (80 à 99 %) ×1, en dessous ×0,5.
- Cristaux = secondes payées × cristaux par heure × coefficient, arrondi au plus proche ; par
  défaut **120 par heure**, soit 180 pour une heure parfaite.
- **Plafond** : **60 minutes payées par jour local** du joueur ; au-delà, la session rapporte 0
  (`capped`) et son bilan le dit.
- Taux, coefficients et plafond se règlent dans l'admin (onglet « Gains ») et valent pour les
  sessions suivantes.
- Les cristaux sont **crédités à la clôture**, dans la transaction qui termine la session
  (`SessionManager::closeSession` → `SessionRewarder::grant`, idempotent par l'index unique ; le
  verrou du solde sérialise les gains d'un joueur, donc le plafond tient). Le coffre ne fait que les
  révéler : il s'affiche **une fois** (ouvert, ou fermé sans l'ouvrir : vu dans les deux cas,
  `opened_at`). Un gain de 0 n'a pas de coffre.

Front (`components/shop/TreasureChest.vue`, calculs dans `utils/shopReward.js`) : à la fin du
dernier module, célébration de la série, puis **coffre**, puis résultat de la séance. Trois coups
l'ouvrent ; le premier fond suit le montant rapporté au maximum du jour (moins d'un quart : bleu
nuit, jusqu'à 60 % : violet, au-delà : pourpre et halo doré), le titre final le palier (« Quel
jackpot ! », « Beau trésor ! », « Joli butin ! ») ; pluie de cristaux, solde qui monte, puis
« Voir mes résultats » (le résultat de la séance) et « Dépenser à la boutique ». Une session finie
sur son bilan (dernier module passé) montre son coffre là (« Voir le bilan »), et le bilan affiche
« +N cristaux » (ou le plafond atteint). Mouvement réduit : le coffre ouvert d'emblée. La boutique
réaffiche « Gagner des cristaux » (la règle, les minutes restantes aujourd'hui).

**Aucun coffre perdu** (2026-10-17) : le tableau de bord demande les coffres pas encore vus
(`GET /shop/rewards/unopened`, qui clôt d'abord la session active si son dernier module est fini)
et les montre l'un après l'autre, du plus ancien, après la célébration de la série ; « Voir le
bilan » mène à leur session. Cas : la page de la séance quittée avant le coffre, un autre appareil.
Un coffre n'est « vu » qu'une fois frappé ou fermé ; affiché puis quitté, il revient. L'écran du
coffre prend lui-même le focus (Échap le ferme) : le coffre n'a son contour de focus qu'au
clavier.

## 4 ter. La roue des cadeaux et le coffre de badge de série (2026-10-23)

Règles validées le 2026-10-23 (maquette « Roue des cadeaux ») ; jusque-là, un coffre du jour de 6 à
12 cristaux (2026-10-21, les lignes d'avant gardent `daily_crowns`).

- **Chaque jour joué**, le premier exercice du jour local (celui qui annonce la série,
  [GAMIFICATION.md](GAMIFICATION.md), « Annonce de la série ») propose **la roue du jour** (une
  ligne `shop_daily_reward`, `wheel` = `offered`). Les exercices suivants du jour ne proposent rien
  de plus.
- **La roue** a 8 cases : quatre « 5 », un « 30 », un « 300 », un « Cadeau », une tête de mort.
  **Le serveur tire le lot** (`Shop\Wheel\WheelSpinner`) selon les chances réglées par un admin
  (`Shop\Wheel\Odds`, `shop_wheel_rule`, onglet « Gains ») ; le front arrête la roue sur une case
  de ce lot. Par défaut : 5 cristaux 75 %, 30 cristaux 12 %, cadeau 7 %, rien 5 %, 300 cristaux
  1 % (des poids : la chance d'un lot est son poids sur la somme ; un poids 0 ne sort jamais).
- **Le cadeau** (les 3 coffres de la maquette ouvrent la même chose) : un **gel de série de 1 jour**
  (rangé avec les gels achetés, § 4) ou **un costume pour Albert**, un message seulement pour
  l'instant (rien n'est gardé) ; moitié-moitié par défaut (`freezePercent`).
- **Crédité au tirage**, dans sa transaction : les cristaux au registre `reward`, motif « Roue des
  cadeaux » (les statistiques admin les comptent dans « gagné ») ; le tirage dans `shop_wheel_spin`
  (`prize`, `gift`, `crowns`, `ticket`). Hors du plafond des sessions (§ 4 bis).
- **Une fois par jour** : la roue du jour se tourne ce jour local seulement. Refusée (« Pas
  aujourd'hui », `declined`) ou pas tournée avant minuit, elle est perdue.
- **Jamais de roue** : le réglage du profil `dailyWheel` (`PUT /profile/preferences`, faux = plus de
  roue, `wheel` = `off`), aussi proposé sous la roue (« Ne plus me proposer la roue »). Il se
  réactive dans le profil.
- **Le ticket** (`wheel_ticket`, offre `wheel_ticket` à 20 cristaux, rayon `wheel`, prix réglable
  comme toute offre) : un tour de plus, à tout moment (« Relancer » après un tirage, ou depuis la
  boutique).
- **Le jour d'un badge de série** (3, 7, 14, 30, 50, 100… jours), un **coffre de série** de
  **10 cristaux par jour**, **1 000 au plus** : 3 → 30, 7 → 70, 14 → 140, 30 → 300, 50 → 500,
  100 et au-delà → 1 000 (`DailyRewarder::badgeCrowns`), montré après la roue. Il est donné même
  si la roue est refusée ou désactivée.
- **Pas rétroactif** : les badges gagnés avant le 2026-10-21 ne rapportent rien.
- Crédité **à l'annonce de la série**, dans la transaction de l'exercice (annulé avec lui) :
  `StreakNoticeWriter` appelle `Gamification\Streak\StreakRewardInterface`, que la boutique
  implémente (`Shop\Reward\DailyRewarder`) ; l'index unique le rend idempotent par jour. Un
  exercice d'un jour passé (séance close après minuit, rattrapage) ne donne rien. Écriture au
  registre `reward`, motif « Coffre de série · 7 jours ».
- Le coffre ne fait que révéler les cristaux, une fois (`opened_at`) ; aucun n'est perdu (la liste
  des coffres pas encore vus, avec la roue du jour à tourner).

Front : `components/shop/DailyChest.vue`, dans la mise en page (après la page : au-dessus de ses
propres coffres), montre pour chaque jour pas encore vu **la roue du jour** (`wheelOpen`), puis le
**coffre de badge** (`TreasureChest` avec ses mots : « COFFRE DE SÉRIE · 7 JOURS », titre ouvert
« 7 jours de série ! », note « Le bonus du badge 7 jours ») ; un coffre d'avant la roue garde ses
mots (« COFFRE DU JOUR »). Il montre aussi la roue d'un ticket ouverte depuis la boutique
(« Tourner la roue » dans le rayon `wheel` quand le joueur a un ticket).

Les roues à tourner (2026-10-24) : le store `shop` les compte (`wheelsToSpin` : les tickets en
stock, plus 1 quand `dailyWheel` attend ; la roue du jour offerte par le premier exercice y entre
dès `showDailyChests()`, et en sort une fois tournée ou refusée). L'en-tête montre alors une petite
roue (`components/layout/WheelChip.vue`, `components/shop/WheelGlyph.vue`) avec leur nombre qui
pulse, cachée quand il n'y en a pas ; le menu, une entrée « Roue des cadeaux » toujours là, en vert
citron, avec le même nombre. Les deux ouvrent la roue sur place (`openWheel()` : celle du jour
d'abord, même fermée avec ✕, puis celles des tickets) ; sans roue, le menu mène au rayon `wheel` de
la boutique (`/boutique?section=wheel`). Les textes du ticket décrivent plutôt qu'ils n'expliquent
(« Tente de gagner 300 cristaux et de superbes cadeaux ! » ; la description de l'offre, mise à jour
par `Version20261114090000` si l'admin ne l'avait pas changée). Pas de pulsation avec les
mouvements réduits.

`components/shop/GiftWheel.vue` (maquette « Roue des cadeaux », nos cristaux `CrystalGem` à la
place des siens) : une fenêtre plein écran de Quasar, sombre dans les deux thèmes (la feuille
d'achat s'ouvre par-dessus). Le centre « Tourner » demande le tirage au serveur, puis la roue
ralentit sur une case du lot (`utils/giftWheel.js` : `SEGMENTS`, `segmentOf`, `landingRotation`,
`spinCurve`, `wheelHead`) ; les cristaux montent dans la réserve (confettis pour le 300), un cadeau
fait choisir un coffre parmi trois (3 coups) qui s'ouvre sur le gel ou sur Albert
(`albert-full`), la tête de mort ouvre la fenêtre « Perdu ». « Relancer » dépense un ticket, acheté
sur place sans ticket (`useAidPurchase('wheel_ticket')`, la roue tourne une fois l'achat fait).
Avant le tirage du jour : « Pas aujourd'hui » (`POST …/decline`) et « Ne plus me proposer la
roue » (confirmation, puis le refus et `dailyWheel` faux). ✕ la ferme sans rien perdre : le
dashboard la repropose jusqu'à minuit (pas dans le même onglet). Mouvements réduits : pas de
rotation ni de confettis, le coffre ouvert au premier coup.

Le store `shop` tient les jours (`dailyChests`, `wheelsDone`, `ticketWheel`, `showDailyChests()`,
`spinWheel()`, `declineWheel()`, `leaveWheel()`, `openDailyChest()`, `nextDailyChest()`, calculs
dans `utils/shopReward.js` : `dailyChestReward`, `dailyChestWords`, `dailyChestNote`).
`useGamificationStore().celebrateStreak()` les montre **juste après la célébration de la série**,
l'un après l'autre, et ne se résout qu'une fois le dernier fermé : tous les appelants (puzzle,
Woodpecker, fin de séance, dashboard) attendent donc la roue et le coffre avant de reprendre. Sans
annonce en attente, le dashboard et la fin de séance montrent quand même une roue ou un coffre
restés non vus ; après un exercice, non (déjà montrés ailleurs : le dashboard les rattrape). Le
profil (« Préférences ») a le réglage « Roue des cadeaux quotidienne » ; l'admin, les chances dans
l'onglet « Gains » (`WheelRuleForm`, la chance de chaque lot et les cristaux moyens d'un tour).

En test PHPUnit, `App\Tests\Double\SwitchableStreakReward` coupe le coffre du jour
(`when@test`) : ses cristaux aléatoires fausseraient les soldes exacts des autres tests ;
`DailyRewardTest` l'active. En e2e, la roue est proposée côté serveur (rien n'est crédité sans tirage), mais
`signIn()` répond « ni roue ni coffre » à `GET /shop/daily-rewards/unopened` comme « aucune
annonce » à la série, sauf avec `streak: true`. `tests/e2e/gift-wheel.spec.js` règle les chances
par l'onglet « Gains » (un seul lot possible par test, les chances par défaut remises après) : la
roue du jour après la célébration (30 cristaux), un cadeau (trois coffres, un gel) puis
« Relancer » avec un ticket acheté sur place et la tête de mort, le ticket tourné depuis la
boutique, la roue fermée reproposée au dashboard, « Ne plus me proposer » et le réglage du profil.

## 4 quater. Des tickets de roue offerts par un admin (2026-10-24)

Règles validées :

- **Un joueur ou tous** : un admin donne de 1 à 100 tickets de roue (`wheel_ticket`) à un joueur
  (sa fiche, onglet « Joueurs »), ou à **tous les comptes actifs** : ni suspendus, ni en
  suppression programmée (l'admin compris). Un compte créé ensuite ne reçoit rien.
- **Donner seulement** : un ticket donné n'est jamais repris ; il se dépense comme un ticket acheté.
- **Un message pour le joueur**, obligatoire (255 caractères) : montré à sa prochaine visite
  (« L'équipe t'offre 2 tours de roue ! », puis chaque message entre guillemets). Ce n'est pas un
  motif interne : le joueur le lit.
- **La trace** : chaque don a sa ligne pour le joueur (`shop_item_grant` : nombre, message, admin,
  « vu le »), dans sa fiche admin et son export ; un don à tous a en plus sa ligne de journal
  (`shop_grant_batch`, avec le nombre de comptes servis). Aucun cristal ne bouge : rien dans
  `shop_ledger_entry`.

`App\Shop\Gift\TicketGrants` écrit le stock (`shop_inventory_item`) et les dons dans une seule
transaction ; un don à tous sert les comptes par paquets de 1 000 (une insertion des dons et une du
stock, `ON DUPLICATE KEY UPDATE`, par paquet), puis écrit le nombre servi sur sa ligne de journal.
`App\Shop\Gift\GiftNotices` liste les dons pas encore vus (`GET /shop`, `gifts`) et les marque vus
(`POST /shop/gifts/seen` : ceux d'un autre joueur, ou déjà vus, ne changent pas).

Front : `components/shop/GiftNotice.vue`, dans le layout après `DailyChest`, s'ouvre quand l'état de
la boutique (lu par l'en-tête) porte des dons, sauf pendant une séance (`/training/…`) et derrière la
célébration de la série, la roue et les coffres du jour ou une roue ouverte (il revient ensuite :
masqué ainsi, rien n'est marqué vu). « Tourner la roue » ouvre la roue d'un ticket (`openTicketWheel`),
« Plus tard » laisse les tickets dans la roue de l'en-tête ; les deux marquent les dons vus
(`markGiftsSeen` : retirés de l'état tout de suite, un échec les ramène à la lecture suivante).
Admin : `components/admin/shop/TicketGiftAll.vue` (en haut de l'onglet « Joueurs » : nombre,
message, confirmation qui nomme le nombre de comptes actifs, journal) et, dans `ShopPlayers.vue`,
le don à un joueur et ses « Tickets de roue reçus ».

## 5. API joueur

| Endpoint | Rôle |
|---|---|
| `GET /shop` | `{balance, offers, slides, inventory, freezes, earning, dailyWheel, gifts}` : offres listées (en vente ou « bientôt »), `inventory` par article (un gel compte tant qu'il couvre un jour), gels un par un, `earning` (la règle des gains et `minutesLeftToday`), `dailyWheel` (l'id de la roue du jour encore à tourner, sinon null : § 4 ter), `gifts` (les dons d'un admin pas encore vus, du plus ancien : `{id, item, quantity, message}`, § 4 quater) |
| `POST /shop/gifts/seen` `{ids}` | les dons d'un admin vus, leur message montré (1 à 100 ids ; ceux d'un autre joueur ignorés, idempotent) → 204 ; 422 sans id valable ; limiteur `shop_write` |
| `GET /shop/rewards/unopened` | les coffres pas encore vus, du plus ancien (20 au plus), la session active d'abord réconciliée → `member` : `{id, crowns, paidMinutes, tier, capped, opened, balance, sessionId}` |
| `POST /shop/rewards/{id}/open` | le coffre d'un gain vu (idempotent) → `{id, crowns, paidMinutes, tier, capped, opened, balance}` ; 404 pour celui d'un autre ; limiteur `shop_write` |
| `GET /shop/daily-rewards/unopened` | les coffres pas encore vus (un badge, ou un jour d'avant la roue) et la roue du jour à tourner, du plus ancien (20 au plus) → `member` : `{id, localDate, streak, badgeDays, dailyCrowns, badgeCrowns, crowns, opened, wheel, wheelOpen, balance}` (`wheel` : `offered`, `spun`, `declined`, `off`, null avant la roue ; `wheelOpen` : à tourner aujourd'hui) |
| `POST /shop/daily-rewards/{id}/open` | un coffre vu (idempotent) → la même forme ; 404 pour celui d'un autre ; limiteur `shop_write` |
| `POST /shop/daily-rewards/{id}/decline` | « Pas aujourd'hui » : la roue du jour perdue → la même forme ; 404 pour celle d'un autre, 409 `no_spin` ; limiteur `shop_write` |
| `POST /shop/wheel/spins` `{dailyRewardId}` | tourne la roue du jour, ou avec un ticket (`dailyRewardId` absent) → `{id, prize, gift, crowns, ticket, balance, tickets}` (`prize` : `c5`, `c30`, `c300`, `gift`, `lose` ; `gift` : `freeze`, `costume`) ; 409 `no_spin` (tournée, refusée, d'un autre jour ou d'un autre joueur) ou `no_ticket` ; limiteur `shop_write` |
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
| `GET /players/{id}` | solde, inventaire, gels, mouvements, `grants` (les articles donnés : `{id, item, quantity, message, admin, everyone, createdAt, seenAt}`, § 4 quater) |
| `POST /players/{id}/crowns` `{delta, reason}` | donner ou retirer (motif obligatoire) ; 409 sous zéro |
| `POST /players/{id}/wheel-tickets` `{quantity, message}` | donner 1 à 100 tickets de roue, le message montré au joueur → sa fiche ; 404, 422 |
| `GET /wheel-tickets/recipients` | `{count}` : les comptes actifs qu'un don à tous servirait |
| `POST /wheel-tickets` `{quantity, message}` | donner des tickets à tous les comptes actifs → 201, la ligne de journal `{id, item, quantity, message, recipients, admin, createdAt}` ; 422 |
| `GET /grant-batches` | `{batches}` : le journal des dons à tous, les 20 derniers |
| `GET /reward-rule`, `PUT /reward-rule` `{crownsPerHour, perfectPercent, nearPercent, missedPercent, dailyMinutes}` | la règle des gains (§ 4 bis), avec la date et l'admin de la dernière modification |
| `GET /wheel-rule`, `PUT /wheel-rule` `{c5, c30, c300, gift, lose, freezePercent}` | les chances de la roue (§ 4 ter) : des poids de 0 à 10 000, un au moins au-dessus de 0, la part du gel en %, avec la date et l'admin de la dernière modification |
| `GET /stats?days=7\|30\|90` | totaux (en circulation, détenteurs, donné, retiré, gagné en session et sessions récompensées, dépensé, achats, acheteurs), ventes par offre, cristaux dépensés et gagnés par jour, articles détenus, jours de gel en stock |

Page `/admin/boutique` (onglet « Boutique » de l'admin) : Offres, Carrousel, Gains, Joueurs,
Statistiques.

## 7. Front

- Page `/boutique` (carte « Boutique » avec le solde dans le menu, solde de cristaux dans l'en-tête) : solde animé, rayons en
  onglets collants (scroll spy), carrousel, gels en cartes, aides par module, packs, cosmétiques
  « Bientôt » (affichage seul) ; feuille d'achat (en bas sur téléphone) : comment ça marche,
  quantité, prix ou ce qui manque, puis célébration.
- Le tableau de bord montre le carrousel (« En ce moment à la boutique », `ShopSpotlight`) ; une
  slide mène à `/boutique?offer=<slug>`, qui ouvre la feuille d'achat de l'offre.
- **Achat sur place** (`useAidPurchase`) : un article manquant au moment de s'en servir (aide ou
  pass du Puzzle Streak, pardon) ouvre la feuille d'achat sur la page de jeu, sur l'offre à l'unité
  la moins chère ; le chrono d'une séance continue.

## 8. Tests

`tests/Unit/Shop/{CatalogTest, RewardCalculatorTest, DailyRewarderTest}.php`, `tests/Unit/Gamification/StreakFreezeTest.php`,
`tests/Functional/Shop/{ShopTest, AdminShopTest, SessionRewardTest, DailyRewardTest, TicketGrantTest}.php`, les pardons et aides dans
`Functional/Puzzle/PardonTest.php`, `Functional/Blindfold/PuzzleRunTest.php`,
`Functional/Coordinates/CoordinatesRunTest.php`, `Functional/PuzzleStreak/StreakRunTest.php` ;
Vitest `shop.test.js`, `shop-reward.test.js` (dont le coffre du jour), `admin-shop.test.js`, `streak.test.js`, `use-blindfold-puzzle.test.js`,
`use-timeboxed-run.test.js`, `coordinates.test.js` ; Playwright `tests/e2e/shop.spec.js` (un admin
donne des cristaux, achat d'un gel ; puzzle classé raté pardonné, pardon acheté sur place ;
pardon à l'aveugle en jeu, acheté sur place, puzzle résolu ; session terminée : coffre ouvert en
trois coups, solde, « Voir mes résultats », bilan et « Gagner des cristaux ») et
`tests/e2e/streak.spec.js` (coffre du jour après la célébration du premier puzzle, coffre de
badge resté non vu au dashboard) et `tests/e2e/wheel-tickets.spec.js` (tickets donnés à un
joueur depuis sa fiche : le message à la visite suivante, « Tourner la roue », plus montré après,
« Vu le » chez l'admin ; tickets donnés à tous : confirmation, journal, « Plus tard » qui garde le
ticket dans l'en-tête). `closeRunEnd()`
ferme le coffre d'une session s'il précède le résultat. La commande e2e
`app:e2e:seed-user --crowns=N` crée un joueur déjà riche.
