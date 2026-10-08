# Gamification — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La gamification a remplacé les valeurs d'« Aperçu » de la maquette Dashboard
(l'ancien `front/src/utils/dashboard/showcase.js`) : niveau et XP, grade, niveau de chaque module, série 🔥,
défi de la semaine, trophées, XP de fin de séance. Choix validés le 2026-10-05 :

- XP **par exercice selon son résultat**, plus des **bonus** ; plafond quotidien des exercices
  (anti-farming), bonus hors plafond.
- **Série** : un jour local compte dès un exercice terminé.
- **Rétroactif** : `app:gamification:rebuild` recalcule tout depuis ce qui a été joué (après le
  déploiement, et après tout changement de règle).
- **Défi de la semaine** généré chaque lundi (lot G4) ; **trophées** atteignables seulement (lot G3).

Lots : G1 XP, niveaux et niveaux de module, G2 séries, G3 trophées, G4 défi de la semaine, G5 front
(fin de `showcase.js`).

Séries mises en avant (maquette « Série », choix validés le 2026-10-07) : écran de célébration une
fois par jour (après le premier exercice en jeu libre, à la fin de la séance chronométrée), 12 badges
de série de 3 à 1 000 jours, flamme dans l'en-tête, carte « Série » au dashboard, rappel « série en
danger » le soir ([NOTIFICATIONS.md § 5](NOTIFICATIONS.md#5-rappel-série-en-danger)). Lots S1 API,
S2 rappel, S3 front, S4 documentation.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles (pures) | `App\Gamification\Xp\XpRules` |
| Écriture des gains | `App\Gamification\Xp\XpLedger` (SQL, idempotent), handlers `App\Gamification\Handler\{AwardExerciseXp, AwardBonusXp}` |
| Recalcul | `App\Gamification\Xp\XpRebuilder`, commande `app:gamification:rebuild [--user=<uuid>]` |
| Lecture | `App\Gamification\Summary\{SummaryReader, Streak}` |
| Annonce de la série | `App\Gamification\Streak\{StreakNoticeWriter, StreakNoticeReader}`, entité `App\Entity\Gamification\StreakNotice` (table `gamification_streak_notice`), API `App\ApiResource\Gamification\StreakNotice`, `App\State\Gamification\{StreakNoticeProvider, StreakNoticeProcessor}` |
| Trophées | `App\Gamification\Trophy\{TrophyProgress, TrophyEvaluator}`, enum `App\Enum\Gamification\Trophy`, entité `App\Entity\Gamification\TrophyUnlock` (table `gamification_trophy`) |
| Entité, enum | `App\Entity\Gamification\XpEntry` (table `gamification_xp_entry`), `App\Enum\Gamification\XpKind` |
| Défi de la semaine | `App\Gamification\Quest\{QuestService, QuestProgress}`, enum `App\Enum\Gamification\QuestTemplate`, entité `App\Entity\Gamification\Quest` (table `gamification_quest`) |
| Comptage commun (N-ième élément) | `App\Gamification\Progress\Counter` |
| API | `App\ApiResource\Gamification\{Summary, Trophies, WeeklyQuest}`, `App\State\Gamification\SummaryProvider` |
| Fixtures | `App\DataFixtures\Gamification\GamificationFixtures` (XP du compte démo, par le recalcul) |
| Front | `front/src/services/api.js` (`gamificationApi`), `stores/gamification.js`, `utils/gamification.js`, `utils/streak.js` ; blocs `components/dashboard/{LevelBanner, StreakCard, TrophyGrid, WeeklyQuest, ModuleProgress, ActivityHeatmap}.vue`, XP de fin de séance dans `components/training/RunEndDialog.vue` (gardé, plus affiché depuis l'écran `RunResult`) ; série : `components/gamification/{StreakCelebration, StreakChip}.vue` (célébration, flamme de l'en-tête et du menu), réglage du rappel `components/profile/StreakReminderSection.vue` |

## 2. Règles (`XpRules`)

| Exercice (`ExerciseCompleted`) | Réussi | Raté |
|---|---|---|
| Puzzle classé | 10 | 3 |
| Puzzle rejoué (non classé) | 4 | 1 |
| Puzzle Woodpecker | 8 | 2 |
| Tronçon de répertoire | 12 | 4 |
| Temps libre | 1 par minute, 60 au plus par séance | — |
| Série de coordonnées ([COORDINATES.md](COORDINATES.md)) | 20 par série d'au moins 10 réponses, validante ou non | — |
| Puzzle à l'aveugle ([BLINDFOLD.md](BLINDFOLD.md)) | 12 (6 après un coup d'œil, `metadata.status` = `helped`) | 2 |
| Évaluation de position ([EVALUATION.md](EVALUATION.md)) | 15 (6 à un cran) ; +5 bon plan, +3 exact en moins de la moitié du temps | 1 |

- **Plafond** : 500 XP d'exercices par jour local (fuseau de l'utilisateur). Un exercice gagne ce qui
  reste du plafond de son jour (deux événements traités au même instant peuvent le dépasser
  légèrement : accepté).
- **Bonus** (hors plafond) : session menée au bout (`SessionClosed` `completed`) +50 ; cycle
  Woodpecker terminé dans les temps (`CycleCompleted`) +100 ; set Woodpecker terminé
  (`SetCompleted`) +300 ; première validation de chaque orientation des coordonnées
  (`SeriesValidated`, kind `validation`, une fois pour les Blancs, une fois pour les Noirs) +100, rattachée à
  la séance qui valide ; défi de la semaine : sa récompense (G4).
- **Niveaux** : passer du niveau N à N+1 demande 250 × N XP (niveau 12 : barre de 3 000, comme la
  maquette). **Niveau d'un module** : même courbe sur l'XP du module, paliers de 100 × N.
- **Grades** : 1–4 Débutant, 5–9 Amateur, 10–14 Tacticien, 15–19 Stratège, 20–29 Expert, 30+ Maître.

Changer une valeur : modifier `XpRules`, puis `bin/console app:gamification:rebuild`.

## 3. Registre (`gamification_xp_entry`)

Une ligne par gain, jamais modifiée : `kind` (`exercise`, `session`, `cycle`, `set`, `quest`, `validation`),
`module` (valeurs de `Module`, null pour une session), `xp`, `source_type` + `source_id` (**unique** :
un événement relivré ne gagne rien de plus ; `ascii_bin`), `training_run_id` (séance où l'XP a été
gagnée : l'XP de fin de séance), `local_date`, `occurred_at`. Index `(user_id, local_date)`. Supprimée
avec le compte (cascade).

Sources : un exercice garde celle du journal d'activité (`puzzle_attempt`, `woodpecker_attempt`,
`repertoire_presentation`, `training_run`) ; une session `training_session` + son id ; un cycle
`woodpecker_cycle` + `<set>:<numéro>:<run>` (un cycle perdu se rejoue) ; un set `woodpecker_set` ; une validation `coordinates_validation` + `<user>:<orientation>` (la première
seulement, quelle que soit la série).

**Recalcul** (`XpRebuilder`, une transaction par utilisateur) : supprime les gains hors défis, puis
rejoue le journal d'activité dans l'ordre chronologique (plafond appliqué sur le `local_date` écrit
avec chaque entrée) et ajoute les sessions `completed`, cycles `completed` et sets `completed` des
tables, et la première série validante de chaque orientation (`coordinates_series`). Mêmes sources que les handlers : recalculer ne double rien.

## 4. Séries (`Streak`)

Calculées à la lecture depuis les jours distincts du journal d'activité (`idx_activity_log_entry_user_date`) :
la série en cours reste vivante tant que le dernier jour actif est hier ou aujourd'hui ; le record est la
plus longue suite de jours consécutifs. Aucune table. Le résumé donne aussi la **semaine locale**
(lundi → dimanche, jours réellement actifs) et le **prochain palier** de badge.

### Badges de série (2026-10-07)

Douze paliers : **3, 7, 14, 30, 50, 100, 200, 300, 365, 450, 500, 1000** jours. Ce sont des trophées
(§ 4 bis : même table, même évaluation à la lecture, même date d'exploit = premier exercice du jour où
la série atteint sa longueur, même recalcul), gagnés pour toujours même si la série casse ensuite ;
7 et 30 sont « En feu » et « Inarrêtable », les autres les cas `streak_<n>` de l'enum
(`Trophy::isStreak()`, `Trophy::streaks()`, `Trophy::forStreak()`). Le front les montre dans la
grille « Trophées » du dashboard (2026-10-08), après les autres trophées, du plus court au plus long :
le nombre de jours dans un disque orange, la progression en jours (meilleure série). La carte
« Série » ne garde que la série du jour, le record et la semaine.

### Annonce de la série (2026-10-07)

L'écran de célébration (`front/src/components/gamification/StreakCelebration.vue`) se montre une
fois par jour, après le premier exercice du jour (jeu libre) ou à la fin de la séance chronométrée.

- Le journal d'activité est écrit **après** la réponse (outbox) : `StreakNoticeWriter` écoute
  `SendMessageToTransportsEvent` (comme `ExerciseXp`) et, quand un `ExerciseCompleted` du jour local
  de l'utilisateur entre dans l'outbox alors que le journal n'a encore rien ce jour-là, écrit la série
  atteinte (série vivante + 1, sinon 1), celle d'avant (0 si cassée) et le badge éventuel, **dans la
  transaction de l'exercice** (annulée avec lui).
- Une ligne par utilisateur (`INSERT … AS new ON DUPLICATE KEY UPDATE`) que seul un jour plus récent
  remplace : un deuxième exercice du même jour, journalisé ou encore dans l'outbox, n'annonce rien de
  plus. Un exercice d'un jour passé (séance close paresseusement après minuit, reprise) n'annonce rien.
- Un seul mécanisme pour tous les modules et toutes les fermetures de séance (arrêt, temps écoulé,
  fermeture paresseuse) : le front lit `GET /gamification/streak/notice` et l'acquitte une fois
  montrée. L'annonce d'un jour passé n'est jamais montrée.
- Front (`useGamificationStore().celebrateStreak()`, qui se résout quand le joueur ferme l'écran) :
  au clic sur le bouton de la fiche de résultat en jeu libre (puzzle, Woodpecker), à la fin d'une
  séance chronométrée avant le bilan, et à l'ouverture du dashboard (annonce restée non vue). Après
  un exercice, « rien en attente » veut dire « déjà montrée » (autre onglet) : plus aucune question
  ce jour-là. « Continuer » acquitte, met à jour la série du résumé (flamme de l'en-tête allumée sans
  rechargement) et reprend là où le joueur était. Animation réduite : l'état final d'emblée.

## 4 bis. Trophées

Catalogue validé le 2026-10-05, limité à ce qui est atteignable avec les modules actuels (ni
« Lucena » ni « Les yeux fermés » de la maquette) ; pas d'XP de trophée.

| Clé | Trophée | Condition | Progression |
|---|---|---|---|
| `on_fire` | En feu | série de 7 jours | meilleure série |
| `woodpecker` | Pivert | un cycle Woodpecker terminé dans les temps | cycles terminés |
| `golden_fork` | Fourchette d'or | 100 puzzles classés « fork » résolus sans aide | puzzles |
| `iron_memory` | Mémoire d'acier | 95 % de réussite sur au moins 50 tests de répertoire en 30 jours (fenêtre glissante) | tests des 30 derniers jours (+ `ratio`, leur réussite) |
| `first_step` | Premier pas | un exercice terminé | exercices |
| `unstoppable` | Inarrêtable | série de 30 jours | meilleure série |
| `steel_woodpecker` | Pic d'acier | un set Woodpecker terminé | sets terminés |
| `centurion` | Centurion | 1 000 puzzles résolus (classés, rejoués, Woodpecker ; aide comprise) | puzzles |
| `conductor` | Chef d'orchestre | 10 sessions menées au bout | sessions |
| `marathon` | Marathonien | 50 h d'entraînement (durées du journal d'activité) | heures |

- **Évalués à la lecture** (`TrophyEvaluator`, comme la clôture paresseuse des séances) : un trophée
  atteint est enregistré une fois (`gamification_trophy`, unique par utilisateur et trophée) ; un
  trophée déjà gagné n'est plus calculé.
- **Date de l'exploit**, pas de sa découverte (`TrophyProgress`) : l'instant du N-ième élément (le
  100e puzzle de fourchette, la 10e session…), le premier exercice du jour où la série atteint sa
  longueur, l'exercice qui fait passer les 50 h, le test après lequel la fenêtre de 30 jours remplit
  la condition.
- Le recalcul (`app:gamification:rebuild`) les oublie et les réévalue : mêmes trophées, mêmes dates.
- Le front garde le nom, l'icône et les couleurs de chaque trophée ; l'API donne la clé, le seuil,
  la progression et la date.

## 4 ter. Défi de la semaine

Modèles validés le 2026-10-05 :

| Modèle | Défi | N | Proposé si | Récompense |
|---|---|---|---|---|
| `rated_puzzles` | Résous N puzzles classés | 15–150 (par 5) | toujours | 150 XP |
| `weak_theme` | Résous N puzzles classés de ton thème faible sans aide | 5–20 | un thème faible sur 30 jours ([DASHBOARD.md](DASHBOARD.md)) | 200 XP |
| `sessions` | Termine N sessions | 2–5 | une session déjà menée au bout | 150 XP |
| `woodpecker` | Joue N puzzles Woodpecker | 20–200 (par 5) | un set actif | 150 XP |
| `repertoire` | Réussis N tronçons de répertoire | 10–60 (par 5) | un répertoire | 150 XP |
| `active_days` | Entraîne-toi N jours cette semaine | 3–6 | toujours (seul défi d'un nouveau joueur) | 150 XP |

- **Tirage à la lecture** (`QuestService`), au premier affichage de la semaine locale (du lundi) : parmi
  les modèles proposés, ceux dont l'activité des 4 semaines précédentes n'est pas nulle (plus
  `active_days`, toujours possible), sans reprendre celui de la semaine d'avant s'il y a le choix ;
  choix déterministe par utilisateur et semaine (`crc32`), un défi par semaine (unique `(user_id,
  week_start)`, deux premières lectures simultanées n'en gardent qu'un).
- **Objectif** : moyenne hebdomadaire des 4 semaines précédentes + 10 %, arrondie (par 5 pour les
  grands nombres), dans les bornes du modèle.
- **Progression** : du lundi 00:00 local au lundi suivant (heure d'été comprise). Atteint : le défi
  est terminé à l'instant de l'exploit (le N-ième puzzle, le premier exercice du N-ième jour…) et sa
  récompense gagnée une fois en XP (`kind` `quest`, source `gamification_quest` + id, module du défi).
- Un défi passé atteint mais plus jamais affiché est **soldé à la lecture suivante** (4 semaines au
  plus). Le recalcul de l'XP garde les récompenses des défis.
- Le prof affiché est celui du module du défi (`module`, null pour `sessions` et `active_days`).

## 5. API

| Endpoint | Contenu |
|---|---|
| `GET /api/gamification/summary` | `xp`, `level`, `xpInLevel`, `xpForNext`, `rank`, `nextRank` (`{rank, level}` ou null), `modules.{woodpecker, repertoire, puzzles, free}` (`xp`, `level`, `xpInLevel`, `xpForNext`), `streak` (`current`, `best`, `playedToday`, `week` : 7 booléens du lundi au dimanche, `nextMilestone` ou null), `today` (`date` : jour local, `exerciseXp`, `cap`) |
| `GET /api/gamification/streak/notice` | L'annonce de série du jour pas encore montrée : `pending` (false : aucune, autres champs null), `streak`, `previousStreak` (0 : cassée), `badge` (clé de trophée) ou null, `week` (aujourd'hui compris), `nextMilestone`, `localDate` |
| `POST /api/gamification/streak/notice/acknowledgement` | 204 : l'annonce du jour est montrée (idempotent) |
| `GET /api/gamification/quest` | Le défi de la semaine : `id`, `template`, `theme`, `module`, `goal`, `current`, `reward`, `completed`, `completedAt`, `weekStart`, `weekEnd` (dimanche) ; le tire au premier affichage, le termine et verse sa récompense |
| `GET /api/gamification/trophies` | `trophies[]` dans l'ordre du catalogue : `key`, `goal`, `current`, `unlocked`, `unlockedAt` (date de l'exploit) ou null, `ratio` (Mémoire d'acier) ; enregistre ceux qui viennent d'être atteints |
| `GET /api/training/runs/{id}/review` | Porte aussi `xp` : l'XP gagnée dans la séance (somme du registre pour ce `run`), 0 tant que le worker ne l'a pas écrite |
| `POST /api/puzzles/attempts/{id}/submission`, `POST /api/woodpecker/attempts/{id}/submission`, `POST /api/training/runs/{id}/submission` | Portent `xp` : l'XP de l'exercice que la soumission termine, plafond du jour compris (voir ci-dessous) ; null quand aucun ne se termine (coup de répertoire au milieu d'une unité) et sur les autres opérations |

**XP d'un exercice dans la réponse** (animation de fin d'exercice, `components/feedback/`) : le
registre est écrit plus tard par le handler de l'outbox ; `App\Gamification\Xp\ExerciseXp` écoute
`SendMessageToTransportsEvent` et, pour chaque `ExerciseCompleted` qui entre dans l'outbox pendant la
requête, calcule `XpLedger::preview()` : la règle de `XpRules`, bornée par ce qui reste du plafond du
jour (registre + exercices déjà prévus dans la même requête). Les domaines qui publient n'en savent
rien. Une ligne de répertoire, journalisée tronçon par tronçon, additionne ses tronçons. Approximation
acceptée : un exercice pas encore écrit par le worker (file en retard) ne compte pas dans le plafond
annoncé ; le registre, lui, applique le plafond exact. Les processeurs remettent le compteur à zéro
après la fermeture paresseuse d'une séance expirée, qui ne concerne pas l'exercice soumis.

Utilisateur connecté, ses seules données ; budget de lecture du dashboard (`dashboard_read`). Mesurés
avec le test de performance du dashboard (un an d'un joueur très assidu, XP recalculée,
[DASHBOARD.md § 6](DASHBOARD.md#6-lot-b-en-cours)) le 2026-10-05 : `summary` 55 ms, `trophies` 51 ms
(médianes, requête HTTP complète, environnement `test`).

## 6. Tests

- PHPUnit : `tests/Unit/Gamification/XpRulesTest.php` (barème, courbe, grades, séries),
  `tests/Functional/Gamification/XpTest.php` (handlers idempotents, plafond et jour local, bonus,
  résumé, recalcul identique aux handlers), `TrophyTest.php` (progression, date de l'exploit, fenêtre
  de 30 jours, enregistrement unique, recalcul identique), `QuestTest.php` (nouveau joueur, semaine
  passée soldée plus tard, objectif sur 4 semaines, pas deux fois le même modèle, récompense unique),
  `StreakNoticeTest.php` (annonce au premier exercice du jour, une seule fois, badge, série cassée,
  annulée avec l'exercice, jamais pour un jour passé).
  `tests/Functional/Training/RunReviewTest.php` vérifie l'`xp` de la revue avant et après l'outbox.
- Vitest : `tests/unit/gamification.test.js` (barre de niveau, niveau d'un module du catalogue,
  cartes de trophées, phrase et prof du défi), `run-end.test.js` (XP et niveau de fin de séance),
  `streak.test.js` (badges, paroles d'Albert, semaine, jour local, célébration une fois par jour).
- Playwright : `tests/e2e/dashboard.spec.js` (réponses de gamification simulées : niveau, série,
  carte « Série » et badges, record, niveau de module, trophées, défi ; plus aucune étiquette
  « Aperçu »), `streak.spec.js` (célébration réelle après le premier puzzle du jour puis plus rien,
  annonce restée non vue au dashboard avec son badge, réglage du rappel). Les autres specs reçoivent
  « aucune annonce » (`signIn()`, sauf `{streak: true}`).
