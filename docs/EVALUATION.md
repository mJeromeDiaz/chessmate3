# Évaluation de position — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Aaron montre une position : **qui est mieux ?** Cinq réponses (+−, ±, =, ∓, −+), du point de vue des
Blancs, et, quand la position en a un, le **plan** (facultatif, en bonus). Puis la correction :
la jauge « toi / le moteur », les idées clés, le meilleur plan.

Choix validés (2026-10-07) : catalogue **écrit à la main par les admins** (page d'administration,
aucun fichier ni commande de synchronisation) ; vérification à la demande sur Lichess ; plan
facultatif ; pas de répétition espacée pour l'instant ; séance chronométrée tenue par le serveur ;
page `/evaluation` et lien de navigation « Évaluation » ; carte du Session Builder.

Changement du 2026-10-11 : une séance dure **1, 2, 5 ou 10 minutes** (2 par défaut), les positions
s'enchaînent jusqu'au bout ; **plus de nombre de positions ni de temps par position** (seule
l'horloge de la séance compte), et donc plus de bonus « rapide ».

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Evaluation\EvaluationRules` (pur) |
| Saisie admin | `App\Evaluation\Position\{PositionEditor, PositionRefusedException}` |
| Vérification Lichess | `App\Evaluation\Verification\{PositionVerifier, TablebaseClient}` (+ `CloudEvalClient` du répertoire) |
| Entités | `App\Entity\Evaluation\Position` (`evaluation_position`), `App\Entity\Evaluation\Attempt` (`evaluation_attempt`) |
| Enums | `App\Enum\Evaluation\{Plan, PositionTag, AttemptStatus}` |
| Module de séance | `App\Evaluation\Training\EvaluationModule` (`Module::Evaluation`) |
| API | `App\ApiResource\Evaluation\{Overview, AdminPosition, PositionCheck}`, `App\State\Evaluation\*` |
| Front | `front/src/pages/index/evaluation/index.vue`, `front/src/components/evaluation/EvaluationPlayer.vue`, `front/src/utils/evaluation.js` ; admin : `front/src/pages/index/admin/positions.vue`, `front/src/components/admin/PositionForm.vue`, `front/src/utils/admin/evaluation.js` |

Migrations : `Version20261013090000` (tables), `Version20261013100000` (FEN unique, plan / conseil /
type facultatifs, dates), `Version20261016090000` (durées de séance : étapes des séances
enregistrées et des séances actives, voir § 4).

## 2. Règles (`EvaluationRules`)

Catégories, du point de vue des Blancs : `2` Blancs gagnent (+−), `1` avantage Blancs (±), `0`
égalité (=), `-1` avantage Noirs (∓), `-2` Noirs gagnent (−+).

| Constante | Valeur | Rôle |
|---|---|---|
| `ADVANTAGE_CP` | 70 | à partir de 0,7 pion, un camp est mieux |
| `WINNING_CP` | 200 | à partir de 2 pions, il gagne |
| `WON_CP` | 10 000 | une position gagnée (mat, finale théorique) est saisie à ±10 000 ou plus ; affichée « +− » / « −+ » |
| `RUN_MINUTES` | 1, 2, 5, 10 | durées de séance permises (`budgets()`, `allows()`) |
| `DEFAULT_MINUTES` | 2 | durée proposée d'abord |
| `MIN_ELO` / `MAX_ELO` | 800 / 2600 | niveau demandé |
| `ELO_WINDOW` | 300 | fenêtre du tirage (puis deux fois plus large, puis tout) |
| `IDEAS` | 3 | idées clés par position, au plus (1 au moins) |
| `DEFAULT_RATING` | 1500 | niveau d'une position quand l'admin n'en donne pas |
| `BORDER_MARGIN_CP` | 20 | une évaluation à moins de 0,2 pion d'une frontière est signalée à l'admin (pile ou face) |

Verdict (`status`) : catégorie exacte → `exact` ; à un cran → `close` (« Presque ! », en jaune) ;
plus loin → `miss` ; pas de réponse (`guess: null`) → `timeout` (les séances d'avant le
2026-10-11 en ont aussi : position laissée au-delà de son temps).

## 3. Catalogue (admins)

Seuls les `ROLE_ADMIN` ([EARLY_ACCESS.md](EARLY_ACCESS.md)) saisissent les positions, onglet
« Positions » de l'administration (`/admin/positions`).

- `GET /api/admin/evaluation/positions` (20 par page, filtres `active`, `tag`), `GET`, `POST`,
  `PUT /api/admin/evaluation/positions/{id}`, `DELETE` (409 `played` si la position a déjà été
  jouée : la désactiver plutôt).
- Champs : FEN (légale, avec un coup légal pour le camp au trait ; normalisée avec les compteurs
  « 0 1 » ; unique), évaluation en centipions (point de vue des Blancs), 1 à 3
  idées clés obligatoires ; facultatifs : plan (`Plan` : attaque sur le roi, jeu à l'aile dame,
  centre et espace, simplifier en finale, défense active ; **demandé au joueur seulement s'il est
  renseigné**), conseil (bulle d'Aaron avant la réponse), type (`PositionTag` : ouverture, milieu de
  jeu, structure, finale ; affiché sur l'échiquier), niveau (1500 par défaut), source, actif.
- Refus (`PositionRefusedException`) : 422 `illegal`, `no_move` (mat ou pat) ; 409 `duplicate`,
  et `played` à la suppression.
- **Vérifier sur Lichess** : `POST /api/admin/evaluation/verification {fen, evalCp}`, rien
  n'est enregistré. 7 pièces ou moins : la tablebase Lichess (résultat exact, du point de vue du
  camp au trait, ramené à celui des Blancs) ; sinon l'évaluation cloud Lichess (un mat compte comme
  un gain). `verdict` : `ok`, `mismatch` (autre catégorie), `drift` (même catégorie, plus d'un pion
  d'écart), `unverified` (Lichess n'a rien), `unavailable` (Lichess injoignable) ; `nearBorder`
  signale une évaluation trop près d'une frontière. Tous les appels passent par `LichessGateway`.
- Fixtures : 4 positions de manuel (position initiale, Lucena, Philidor, opposition).

## 4. Déroulé (module `evaluation`)

- **Sujet** : l'utilisateur (`evaluation_player`). `config: {elo, side}`, `side` = `white`, `black`
  ou `both` (le camp au trait) ; toute autre option ou valeur : 422. **Durée** : 1, 2, 5 ou 10
  minutes (`BudgetChoicesInterface`, comme les coordonnées) ; une autre est refusée au démarrage
  (422) et à l'enregistrement d'une séance (étape invalide). Une séance lancée avant le 2026-10-11
  garde `count` et `seconds` dans sa config : ignorés à la lecture (`runConfig()`).
- **Démarrage** : la première position est servie au démarrage ; catalogue vide pour ces réglages :
  409 et pas de séance.
- **Tirage** (`PositionRepository::pick`) : positions actives du trait demandé, jamais une déjà
  servie dans la séance ; d'abord dans la fenêtre Elo, puis deux fois plus large, puis n'importe
  laquelle ; à fenêtre égale, une jamais vue par le joueur, sinon celle vue il y a le plus
  longtemps ; au hasard entre égales. Plus rien : la séance se clôt avant son temps
  (`subject_unavailable`, `reason: no_position`).
- **Élément** `evaluation_position` : la position (FEN, trait, type), le conseil, `askPlan`, les
  plans proposés, `servedAt`, `index` (numéro de la position dans la séance). **Pas de temps par
  position** : seule l'horloge de la séance compte. **Rien de la réponse** (évaluation, idées,
  plan) avant le verdict. Un rechargement rend la même position.
- **Réponse** : `POST …/submission {itemId, evaluation: {guess, plan}}`, `guess` de -2 à 2 ou
  `null` (jugé `timeout` ; le front ne l'envoie plus), `plan` une valeur de `Plan` ou `null`. À
  l'heure tant que la séance l'est (fin + 2 s de tolérance réseau du runner ; après : 409 et la
  séance se clôt). La tentative garde dans `seconds` le temps qui restait à la séance quand elle a
  été servie, plafond de `durationMs`. Le résultat porte la correction : catégorie, `evalCp`,
  `engine` (« +1,4 »), plan et son libellé, plan choisi, `planOk`, idées, source, `durationMs`.
- **Fin** : temps écoulé, arrêt ou plus de position. La position à l'écran **n'est jamais
  comptée** (supprimée à la clôture). Récapitulatif : `metrics` `exact`, `close`, `miss`,
  `timeout`, `planOk`, `activeMs`, `averageMs` (les séances d'avant le 2026-10-11 ont aussi
  `count` et `seconds`) ; réussite = `exact`. Bilan : `ok` exact, `hint` à un cran, `fail` sinon ;
  une position n'a pas de ligne à rejouer.
- Chaque position jugée est un `ExerciseCompleted` `position_evaluation` (`success` = exact,
  `metadata` `status`, `planOk`, `fast` (toujours `false` depuis le 2026-10-11 : un champ
  d'événement ne disparaît jamais), `position`, `guess`, `category`, `seconds`, `trainingRunId`),
  dans la transaction ([ACTIVITY.md](ACTIVITY.md)).
- **Migration des séances** (`Version20261016090000`, irréversible) : les étapes `evaluation` des
  séances enregistrées, et celles encore à jouer (`pending`) des séances actives, prennent la plus
  longue durée permise sans dépasser la leur (13 → 10, 3 → 2, au moins 1) et perdent `count` et
  `seconds`.

## 5. XP

15 exact, 6 à un cran, 1 raté ou sans réponse ; **+5** le bon plan (quelle que soit la
catégorie) : 20 au plus (`XpRules::EVALUATION_*`, [GAMIFICATION.md](GAMIFICATION.md)). Le bonus
« rapide » (**+3**, exact en moins de la moitié du temps de la position) n'existe plus depuis le
2026-10-11 ; `XpRules` le garde pour les événements d'avant (`fast: true`), de sorte qu'un
`app:gamification:rebuild` ne reprend pas l'XP déjà gagnée.

## 6. Pages

- `GET /api/evaluation` : les règles (`rules` : `runMinutes`, `defaultMinutes`, bornes de l'Elo,
  seuils des catégories), les positions actives par trait
  (`positions`), mes résultats (`results` : `played`, `exact`, `close`, `miss`, `timeout`,
  `planOk`). Une séance laissée derrière est d'abord close (clôture paresseuse).
- `/evaluation` : durée (1, 2, 5 ou 10 min, `RunLauncher` avec `durations`), niveau, trait (avec
  le nombre de positions disponibles) ; mes résultats.
- Séance (`front/src/pages/index/training/[id].vue` + `EvaluationPlayer`) : l'horloge de la séance
  dans l'en-tête (« Position n · x justes »), cinq réponses, plans si demandés, « Valider mon
  évaluation » ; puis la correction et la feuille de résultat (exact : victoire ; à un cran : « ?! »
  en jaune ; raté ; sans réponse : « ⏱︎ »). La correction de la dernière position reste affichée
  jusqu'à « Voir le résultat ».
- Session Builder : carte « Évaluation de position » (durée `durationChoice` lue dans
  `GET /evaluation`, Elo, trait) ;
  tableau de bord : ligne du module (positions jouées, part d'exactes).
