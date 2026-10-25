# Carte mentale — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

La **carte mentale** (page `/cerveau`, titrée « Entraînement ludique » comme son entrée du menu
depuis le 2026-10-21) montre six capacités du joueur d'échecs
sur un cerveau. Chaque capacité aura un **parcours** à la Duolingo : des chapitres, des sections,
des exercices à valider plusieurs fois, des mini-boss, des boss et des coffres. Le premier écrit
est celui de la **visualisation** (contenu de l'utilisateur :
[mindMap/Parcours Visualisation version 2.md](mindMap/Parcours%20Visualisation%20version%202.md)),
puis celui du **calcul** (§ 5 bis).

Choix validés (2026-10-20) :

- **Le parcours est écrit dans le code** (catalogue PHP), les montants dans le tableau du § 5 ;
  pas d'écran d'admin pour l'instant. Les textes sont des messages du front.
- **Un exercice se valide 2 à 4 fois** (ses passages) ; un passage est une leçon courte de son
  module, réussie à **80 %** au moins, avec un nombre minimum d'éléments.
- **Un boss se bat avec 3 vies** : 3 erreurs et il gagne, on recommence. On ne saute jamais un boss.
- **Distractions** (validé 2026-10-20) : jamais dans les exercices ; à chaque boss et mini-boss,
  plus fortes de chapitre en chapitre (`Enum\Training\Distraction`, option `distraction` de
  l'étape, rendue par `components/mindMap/BoardDistractions.vue`) : chapitres 1 et 2 `light`
  (l'échiquier vacille, deux pièces le traversent ; le Jongleur, une question sur trois), chapitre 3
  `medium` (plus souvent, trois pièces, un son de coup parasite si les sons sont activés ; le
  rappel du Jongleur, deux questions sur trois), la Gargouille `strong`. Rien ne bouge quand le
  système demande moins d'animations.
- **Sons des boss** (si les sons sont activés, `utils/sounds.js` `playBossVerdict`) : chaque
  réponse d'un boss ou d'un mini-boss (une étape à vies) sonne `ding.mp3` (juste) ou `off.mp3`
  (fausse, en retard) ; la Gargouille garde son loup (et son rire à la dernière vie), le Métronome
  a son thème et son tic-tac ([CALCULATION.md](CALCULATION.md), § 6). Les thèmes du Jongleur et du
  Cyclope (`jongleur-theme.mp3`, `cyclope-theme.mp3`) jouent sur leur fiche puis en boucle sous
  leur combat : la page du parcours ouvre la séance avec `?boss=<personnage>` (`FIGHT_THEMES` de
  la page de séance).
- **On peut sauter un exercice** : ce qui suit s'ouvre, rien n'est gagné ; il reste jouable.
- **Il faut battre le boss d'un chapitre** pour ouvrir le suivant, et les mini-boss d'une section
  pour ouvrir la suivante (une section sans mini-boss : ses exercices réussis ou passés). Le **boss
  du chapitre est ouvert dès l'ouverture du chapitre**, et **les mini-boss dès l'ouverture de leur
  section** (dans l'ordre, depuis le 2026-10-22) : un joueur fort peut les battre tout de suite,
  sans les exercices (restés jouables ensuite ; les coffres ne se gagnent qu'en réussissant les
  exercices). Les **mini-boss cachés** apparaissent (« ? ») une fois leur section
  franchie : facultatifs, un bonus et un badge.
- **Coffres** : sur un chapitre long sans section, des coffres intermédiaires (chapitre 1 entre 1.3
  et 1.4 puis entre 1.6 et 1.7, depuis le 2026-10-21 ; chapitre 7 entre 7.2 et 7.3) ; chaque
  mini-boss et boss a le sien. Un coffre ajouté ou déplacé se donne sans migration : les coffres
  dus sont recalculés sur tout le parcours à chaque étape terminée (`PathState::chestsDue`). Ouverture avec l'animation
  des coffres de session (`TreasureChest`).
- **Modules** (Notation, Vision, variantes à l'aveugle) développés en parallèle, jouables aussi en
  libre et en session : libres d'emblée, sauf les modes de boss (après leur victoire) et les bots
  (après le chapitre 6).
- Images des boss : expressions du Métronome et de la Gargouille (§ 7), sinon
  `front/src/assets/mindMap/boss/cyclope.png` par défaut, en attendant les définitives ; animations de victoire à venir (Claude Design).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Catalogue | `App\MindMap\Path\{Paths, Visualisation, Calculation, Endgames, Challenges, Chapter, Section, Step, Activity, PathRules}` (une `Activity` préparée passe par le `prepare()` de son module au lancement ; un `Chapter` hors maîtrise ne compte pas pour le badge de maîtrise et porte `meta` ; une `Step` à clé de récompense ne paie ses cristaux qu'une fois par clé, `mind_map_chest.reward_key` : les défis, [DEFIS.md](DEFIS.md)) |
| État (pur) | `App\MindMap\Path\PathState` (statuts, coffres dus, sections franchies) |
| Progression | `App\MindMap\Progress\{StepLauncher, PassRecorder, Rewarder, PathReader, ChestOpener, Progresses}` |
| Chapitres d'un joueur | `App\MindMap\Progress\PathCatalog` : les chapitres fixes (`Paths`), puis, sur le parcours des défis, ceux d'ouverture du joueur (`App\Challenge\Path\OpeningChapters`, [DEFIS.md](DEFIS.md)) ; `StepLauncher`, `PassRecorder`, `PathReader` et le verdict lisent les étapes par lui |
| Point d'extension | `App\Training\Run\RunClosedListenerInterface` (Training le possède, `PassRecorder` l'implémente) |
| Entités | `App\Entity\MindMap\{Progress, Attempt, Chest}` (tables `mind_map_progress`, `mind_map_attempt`, `mind_map_chest`, migration `Version20261028090000`), `BossItem` (`mind_map_boss_item`, § 8) |
| Boss | `App\MindMap\Boss\{JongleurModule, JongleurRules, QuestionDrawer}` (module `jongleur`, § 8) |
| Enums | `App\Enum\MindMap\{Capacity, StepKind, StepStatus, AttemptOutcome, BossQuestion, BossItemStatus}` |
| Événement | `App\MindMap\Event\StepCompleted` (bonus d'XP, `Gamification\Handler\AwardBonusXp`) |
| API | `App\ApiResource\MindMap\{Path, StepAction, Outcome, Chest}`, `App\State\MindMap\*` |
| Front | pages `pages/index/cerveau/index.vue` (la carte) et `pages/index/cerveau/[capacity].vue` (le parcours), `components/mindMap/{BrainMap, CapacityCard, PathHero, PathChapter, PathNode, WoodChest, StepDetail, BossPortrait, BossVictory, RunOutcome, JongleurPlayer}.vue`, `utils/mindMap.js`, `utils/mindMapPath.js`, `utils/jongleur.js`, `services/api.js` (`mindMapApi`), `i18n/<langue>/mindMapPath.json` |

## 2. Étapes

Un chapitre a des **sections** (une seule, sans numéro, pour un chapitre qui n'en a pas), puis
son **boss**, annoncé en haut du chapitre et joué à la fin. Une section : ses **exercices** (et
coffres) dans l'ordre, ses **mini-boss** dans l'ordre, ses **boss cachés**.

| Genre (`StepKind`) | Passages | Jugement | Saut |
|---|---|---|---|
| `exercise` | 2 à 4 | au moins `count` éléments et 80 % justes (`PathRules::PASS_RATE`) | oui |
| `chest` | — | gagné quand tous les exercices avant lui dans sa section sont **réussis** (un saut ne compte pas) | non |
| `mini_boss`, `hidden_boss`, `boss` | 1 | au moins `count` éléments et moins de 3 erreurs (`PathRules::BOSS_LIVES`) | non |

Une étape joue une **séance de son module** (`Activity` : module, réglage, durée, `count`,
`lives`, sujet), lancée comme `POST /training/runs` et jugée à sa clôture. Une étape sans module
(il arrive avec son lot) est « bientôt » : un exercice peut être sauté, un boss bloque.

## 3. Statuts (`PathState`)

`locked`, `open`, `soon`, `hidden`, `done`, `skipped`, calculés à chaque lecture, jamais stockés :

- le chapitre 1 est ouvert ; un chapitre s'ouvre quand le boss du précédent est battu ;
- dans un chapitre ouvert, une section s'ouvre quand la précédente est **franchie** : ses
  mini-boss battus ; sans mini-boss, ses exercices réussis ou sautés ;
- dans une section ouverte, les exercices s'ouvrent dans l'ordre (réussi ou sauté ouvre le
  suivant) ; les mini-boss sont ouverts avec leur section, dans l'ordre (un battu ouvre le
  suivant ; depuis le 2026-10-22, avant : quand tous les exercices étaient réussis ou sautés) ;
  les boss cachés quand la section est franchie (avant : `hidden`, sans nom ni récompense
  affichés) ;
- le boss du chapitre est ouvert avec son chapitre (le battre ouvre le suivant, sections et
  mini-boss non faits compris).

Une étape `done` ou `skipped` se rejoue : un exercice sauté puis réussi devient `done` ; une étape
déjà faite est de l'entraînement, jugé, jamais payé à nouveau.

## 4. Déroulé

- `POST /mind-map/{capacité}/steps/{clé}/start` → `{capacity, key, run}` (la séance, comme
  `POST /training/runs`). 409 `step_locked`, `step_soon`, ou une autre séance en cours ; 404 clé
  inconnue. Une ligne `mind_map_attempt` relie la séance à l'étape, et la séance porte son étape
  (`training_run.path_step`, `visualisation:2-1-3`), posée par le serveur seul : un module s'y fie
  pour ouvrir ce que le parcours permet (un niveau de reconstitution pas encore ouvert, les options
  d'un boss) et la refuse à toute autre séance (le Jongleur ne se lance que par son étape).
- La séance se joue comme toute séance (`/training/runs/{id}/next`, `/submission`).
- À sa **clôture** (fin du temps, du sujet, « Terminer », ou clôture paresseuse), `TimeboxRunner`
  appelle les `RunClosedListenerInterface` dans la même transaction : `PassRecorder` juge la séance
  sur son récapitulatif (`itemCount`, `successCount`, et `metrics.failures` quand le module compte
  ses échecs autrement, comme les pièces fausses ou oubliées d'une reconstitution) et, réussie,
  compte un passage ; réussie avec une aide qui l'empêche de compter (le maillage des finales
  allumé, `metrics.netUsed`, [ENDGAMES.md](ENDGAMES.md) § 8), elle est `assisted` et ne compte pas. Le dernier
  passage termine l'étape et la récompense (§ 5), puis les coffres en chemin qu'elle débloque.
- `GET /mind-map/runs/{id}` → le verdict (`pending`, `passed`, `failed`, `assisted`), les éléments, `failures`
  (ce qui compte contre les vies : éléments ratés, ou `metrics.failures`), `count`, `lives`, les passages, `completed`, l'XP et le coffre de l'étape si cette séance l'a terminée, et
  tous les coffres pas encore ouverts du parcours. 404 pour une séance qui n'est pas celle d'une
  étape du joueur.
- `POST /mind-map/{capacité}/steps/{clé}/skip` → `{capacity, key, path}`. 409
  `step_not_skippable` (boss, coffre, exercice fait), `step_locked`.
- `POST /mind-map/chests/{id}/open` → `{id, capacity, step, crystals, opened, balance}` (idempotent,
  404 pour le coffre d'un autre).
- `GET /mind-map/{capacité}` → `{capacity, chapters: [{key, number, status, sections: [{key,
  number, status, steps, miniBosses, hidden}], boss}], badges, unopenedChests, lastPlayedAt}`
  (`lastPlayedAt` : le début de la dernière séance d'étape du parcours, null avant la première ; le
  dashboard propose le parcours joué en dernier) ; une étape :
  `{key, number, kind, status, passes, required, crystals, xp, character, badges, skippable,
  chest}`. Une séance d'étape dont le temps est passé est d'abord close (donc jugée).

Limiteurs : `training_run_start` (lancer, sauter), `shop_write` (ouvrir un coffre).

## 5. Récompenses (parcours visualisation)

L'XP des exercices des modules compte comme d'habitude (plafond du jour). En plus, **sans
plafond** : +15 XP par exercice validé (dernier passage), et l'XP des boss ci-dessous. Les cristaux
sont **crédités quand l'étape est gagnée** (une ligne `reward` du grand livre, « Carte mentale ·
visualisation · clé »), une seule fois, hors du plafond de 60 minutes des sessions ; le coffre ne
fait que les révéler.

| Étape | Clé | Cristaux | XP | Badges |
|---|---|---|---|---|
| Exercice validé | `1-1`… | — | 15 | — |
| Ch. 1, coffre entre 1.3 et 1.4 | `1-chest` | 60 | — | — |
| Ch. 1, coffre entre 1.6 et 1.7 | `1-chest-2` | 60 | — | — |
| Ch. 1, boss Le Jongleur | `1-boss` | 200 | 100 | `chapter_1`, `boss_jongleur` |
| Ch. 2, mini-boss 2.1 / 2.2 / 2.3 | `2-1-boss`… | 80 / 100 / 120 | 40 | `section_2_x` |
| Ch. 2, mini-boss caché 2.3 | `2-3-hidden` | 60 | 40 | `hidden_2_3` |
| Ch. 2, boss Le Cyclope | `2-boss` | 240 | 100 | `chapter_2`, `boss_cyclope` |
| Ch. 3, rappel Le Jongleur (version courte) | `3-0-boss` | 60 | 40 | — |
| Ch. 3, mini-boss 3.1 / 3.2 / 3.3 | `3-x-boss` | 100 / 120 / 140 | 40 | `section_3_x` |
| Ch. 3, boss La Gargouille | `3-boss` | 300 | 120 | `chapter_3`, `boss_gargouille` |
| Ch. 4, examens 4.1 / 4.2 / 4.3 / 4.4 | `4-x-boss` | 100 / 120 / 140 / 160 | 40 | `section_4_x` |
| Ch. 4, caché Le Sablier (4.2) | `4-2-hidden` | 80 | 40 | `boss_sablier` |
| Ch. 4, boss Le Cyclope (calcul) | `4-boss` | 360 | 150 | `chapter_4`, `boss_cyclope_calcul` |
| Ch. 5, mini-boss 5.1 | `5-1-boss` | 160 | 50 | `section_5_1` |
| Ch. 5, cachés 5.1 (2) | `5-1-hidden-1`, `-2` | 80 chacun | 40 | `hidden_5_1_x` |
| Ch. 5, mini-boss 5.2 (1 puis 2) | `5-2-boss-1`, `-2` | 160 / 180 | 50 | `section_5_2` (le 2) |
| Ch. 5, caché Le Flash (5.2) | `5-2-hidden` | 100 | 40 | `boss_flash` |
| Ch. 5, boss Le Fantôme | `5-boss` | 440 | 150 | `chapter_5`, `boss_fantome` |
| Ch. 6, boss Le Caméléon | `6-boss` | 500 | 200 | `chapter_6`, `boss_cameleon` |
| Ch. 7, coffre entre 7.2 et 7.3 | `7-chest` | 200 | — | — |
| Ch. 7, boss Le Sith aveugle | `7-boss` | 600 | 250 | `chapter_7`, `boss_sith`, `oeil_interieur` |

Repères : un gel d'un jour coûte 60 cristaux ; une heure de session parfaite en rapporte 180.
Montants doublés par rapport à la première proposition (choix du 2026-10-20). Passages par
exercice : `Visualisation::chapters()` (le dernier exercice d'une section en demande souvent 4).

Les badges sont ceux des étapes terminées (`GET /mind-map/{capacité}` → `badges`, avec la date) :
rien n'est stocké à part. Les badges à condition (« Membre fantôme » : Fantôme sans erreur ;
« Mue parfaite » : 20 coups sans erreur contre le Caméléon) viendront avec leurs boss.

## 5 bis. Parcours calcul

Contenu de l'utilisateur : [mindMap/profondeur_de_calcul.md](mindMap/profondeur_de_calcul.md)
(10 chapitres ; les 3 premiers d'abord, `App\MindMap\Path\Calculation`, capacité
`calculation`, page `/cerveau/calculation`). Format validé le 2026-10-21 : la position est montrée
sur un **échiquier figé** le temps de la réflexion (libre, « Je réponds », plafond 2 min), puis la
ligne entière est jouée **d'un trait, les deux camps**, chaque demi-coup moins de 3 s après le
précédent ; elle est jugée une fois, à la fin (les coups de la solution, défense comprise, le
dernier pouvant être n'importe quel mat). Puzzles de mat, non classés : le module Calcul ([CALCULATION.md](CALCULATION.md)), branché
sur le chapitre 1 et le Métronome (lots K1a à K1c, [CALCULATION.md](CALCULATION.md), § 6) ; les
chapitres 2 et 3 sont encore « bientôt ».

| Étape | Clé | Contenu | Cristaux | XP | Badges |
|---|---|---|---|---|---|
| Ch. 1 « La ligne droite » | `1-1` … `1-4` | mats en 1, en 2, en 2 avec une capture, en 3 (10 positions, 80 %) | — | 15 | — |
| Ch. 1, coffre après 1.2 | `1-chest` | | 60 | — | — |
| Ch. 1, mini-boss « Sans faute » | `1-mini` | 4 mats en 3, 2 vies | 80 | 40 | — |
| Ch. 1, boss Le Métronome | `1-boss` | une série de mats de longueur cachée : 12 justes d'affilée, une erreur remet à zéro et coûte une vie (3) ; réflexion 40 s, −3 s par ligne juste, au moins 15 s | 200 | 100 | `chapter_1`, `boss_metronome` |
| Ch. 2 « Tenir la distance » | `2-1` … `2-6` | gains de matériel en 3 coups (2.1, 2.2), mats en 3, gains en 4 coups ; puis 3 ou 4 coups des thèmes `sacrifice` (2.5), `quietMove` et `clearance` (2.6) | — | 15 | — |
| Ch. 2, coffre après 2.4 | `2-chest` | | 80 | — | — |
| Ch. 2, la position finale | `2-7`, `2-8` | 5 positions : la ligne de 3 (puis 4) coups, puis une question sur la position atteinte ([CALCULATION.md](CALCULATION.md), § 2 bis) | — | 15 | — |
| Ch. 2, mini-boss « Trouver, puis reconstituer » | `2-mini` | 4 puzzles de 3 ou 4 coups : résolus, puis rejoués de mémoire d'un trait ; 3 vies ([CALCULATION.md](CALCULATION.md), § 2 ter) | 100 | 40 | — |
| Ch. 2, boss Le Compteur | `2-boss` | un puzzle long gardé à l'écran, sa ligne (8 à 10 demi-coups) annoncée, 3 questions sur la position atteinte (une case, une pièce, combien de pièces) ; 3 positions, 15 s par question, 3 vies ([VISION.md](VISION.md), § 2 sexies) | 240 | 100 | `chapter_2`, `boss_compteur` |
| Ch. 3 « L'adversaire a son mot à dire » | `3-1` … `3-4` | jugés par Stockfish dans le navigateur ([CALCULATION.md](CALCULATION.md), § 2 quater) : la meilleure de deux réponses adverses (3.1, 10), une ligne contre chacune des deux réponses légales (3.2, 6 positions), parer une menace de mat (3.3, `defensiveMove`, 10), gagner malgré l'échec intermédiaire (3.4, 10) | — | 15 | — |
| Ch. 3, mini-boss | `3-mini` | `combo` ([CALCULATION.md](CALCULATION.md), § 2 quater) : trois coups forcés, une seule combinaison tient ; la choisir puis jouer sa ligne (2 ou 3 coups), 4 positions, 3 vies | 120 | 40 | — |
| Ch. 3, boss L'Avocat du diable | `3-boss` | `advocate` ([CALCULATION.md](CALCULATION.md), § 2 quater) : proposer son coup, puis jouer d'un trait la meilleure réponse adverse et sa suite (2 ou 3 coups) ; une idée qui ne tient pas coûte une vie, la feuille dit si sa réfutation a été trouvée ; 5 positions, 3 vies, aucune XP par ligne | 300 | 120 | `chapter_3`, `boss_avocat` |

## 6. Modèle de données

- `mind_map_progress` : `user_id`, `capacity`, `step` (`ascii_bin`), `passes`, `completed_at`,
  `skipped_at`, `updated_at` ; unique `(user_id, capacity, step)`.
- `mind_map_attempt` : `user_id`, `run_id` (unique, cascade), `capacity`, `step`, `outcome`,
  `item_count`, `success_count`, `completed`, `started_at`, `judged_at`.
- `mind_map_chest` : `user_id`, `capacity`, `step`, `crystals`, `created_at`, `opened_at` ; unique
  `(user_id, capacity, step)`.

Les changements d'un joueur se sérialisent sur sa ligne `app_user` (`ProgressRepository::lockPathOf`).
Tout est supprimé avec le compte (cascade) et exporté (`entrainement.json` : `mindMapProgress`,
`mindMapAttempts`, `mindMapChests`). L'XP : source `mind_map_step` (id de la ligne de progression),
genre `mind_map`, rattachée à la séance qui a terminé l'étape ; `app:gamification:rebuild` la
retrouve (l'XP de l'étape telle que le catalogue la donne).

## 7. Front

Mise en page de Claude Design « Parcours zone » (2026-10-22), aux couleurs claires et sombres de
l'appli.

- **Carte** (`/cerveau`) : un clic sur la carte ou la zone du cerveau d'une capacité qui a un
  parcours (`PATH_CAPACITIES` : la clé de la carte `visualization` → celle de l'API
  `visualisation`) ouvre directement son parcours ; les autres sont désactivées, avec la pastille
  « Bientôt » (leur zone ne réagit ni au clic ni au survol).
- **Parcours** (`/cerveau/visualization`) : un bandeau aux couleurs de la capacité (`PathHero` :
  numéro, étapes passées sur le total, nom, description, barre de progression du parcours entier,
  `pathProgress`), puis les chapitres l'un sous l'autre, **tous visibles** (un chapitre verrouillé
  montre ses étapes grisées). Une étape par ligne (`PathNode`), décalée selon sa place dans sa
  section (`nodeOffset` : −50, 30, 90, 30, −50, 0 px, × 0,55 sous 560 px), son titre sur le côté
  opposé ; une bulle « Commencer » flotte au-dessus de l'étape à jouer ; l'anneau des passages
  autour d'un exercice. Les boss au milieu, en grand disque (150 px pour le boss du chapitre, 92 pour
  un mini-boss ou un boss caché), leur visage selon l'état (`pathMood` : fier quand il attend le
  joueur, triste vaincu, neutre sinon ; grisé verrouillé), un halo qui pulse s'il est le prochain,
  leur pastille et leur nom dessous. Les coffres en bois (`WoodChest` : gris verrouillé, qui
  sautille gagné mais pas ouvert, ouvert sur un cristal). L'étape à jouer est **centrée** à
  l'arrivée.
- **Carte de chapitre** (`PathChapter`) : « Chapitre n · x / y », le titre, « Boss : … ». Elle
  colle sous l'en-tête de l'appli (sa hauteur mesurée en direct) pendant que son chapitre défile ;
  la carte suivante la pousse, et l'inverse en remontant. Collée, elle tient sur une ligne avec une
  ombre, et garde sa place dans le flux (une marge basse compense) : le chemin ne saute pas. L'état
  « collé » (`data-stuck`) est lu à chaque défilement (une fois par image), un saut de la page compris.
- **Détail d'une étape** (`StepDetail`) : sur grand écran, dans la colonne à droite (collante),
  l'étape à jouer tant que le joueur n'en a pas choisi une autre ; sur téléphone, en volet par le
  bas au clic. Exercice : en-tête aux couleurs de la capacité, « En bref », passages, règle des
  80 %, « Commencer » / « Recommencer » / « S'entraîner », « Passer » (confirmé) ; sur grand écran,
  un second clic sur l'exercice choisi le lance comme « Commencer » (`startsOnSecondClick()`, aussi
  sur `/finales` et `/defis` ; jamais un boss, dont le combat s'annonce). Boss : sa scène
  (en pied), sa pastille et son chapitre, son nom, sa devise, sa réplique, le défi, 3 vies,
  « Affronter ». Coffre : ce qu'il contient. Puis les gains (cristaux, XP, badges), l'état, et le
  bouton (grisé « Verrouillé » ou « Bientôt »). Un boss choisi par le joueur joue son thème tant
  qu'il est affiché (jamais celui montré d'office). Sous le détail : les badges et les règles. Les
  coffres pas encore ouverts s'ouvrent à l'arrivée, l'un après l'autre (`TreasureChest`, avec son
  propre `opener` et sa ligne « Carte mentale · étape »).
- **Jouer** : la séance s'ouvre sur `/training/{id}?mindMap=1`, jouée comme toute séance ; à sa
  fin, `RunOutcome` remplace le récapitulatif : le verdict (passage réussi, exercice validé, pas
  encore ; victoire, le boss l'emporte avec sa réplique), les passages ou les vies, l'XP ; un boss
  battu passe d'abord par `BossVictory` (provisoire en attendant les animations de Claude Design :
  confettis, rayons, visage vaincu, dernière réplique, badges), puis les coffres ; « Passage
  suivant » / « Rejouer » et « Retour au parcours ».
- **Images** : un boss qui a ses **expressions** (Claude Design « Fiches miniboss » : le Métronome,
  la Gargouille, le Jongleur, le Cyclope ; le Requin aussi, pas encore placé dans un parcours) a un dossier `assets/mindMap/boss/<personnage>/` : `<personnage>-<humeur>.png`
  (bustes : joie, surprise, doute, tristesse, fierte, colere, concentration, angoisse, neutre ;
  `MOODS` de `utils/mindMapPath.js`, clés anglaises) et `<personnage>-pose.png` (en pied, dans son
  détail `StepDetail`). `BossPortrait` prend une `mood`, sinon celle de sa pose (`POSE_MOODS` : `idle`
  → neutre, `cheer` → joie, `angry` → colère, `search` → concentration, `beaten` → tristesse) et
  `full` (en pied). Les autres découpent une pose (`POSES`) dans la planche du personnage
  (`assets/mindMap/boss/<personnage>.png`, 1536 × 1024) ; sans planche, celle du cyclope.
- **Textes** : `mindMapPath.<capacité>.{chapters, sections, steps.s<clé>}` (clé de l'étape, `-`
  → `_` : `2-1-3` → `s2_1_3`), `mindMapPath.characters.<personnage>.{name, tagline, taunt, defeat,
  win}`.

## 8. Le Jongleur

Boss du chapitre 1 (`1-boss`, 20 questions) et rappel du chapitre 3 (`3-0-boss`, 10 questions) :
une suite rapide de questions mêlant les exercices du chapitre 1. Module d'entraînement `jongleur`
(`App\MindMap\Boss\{JongleurModule, JongleurRules, QuestionDrawer}`), joué depuis le parcours
seulement (refusé en séance : `prepare` lève `InvalidRunConfigException`) ; config `count` (1 à 20),
seule option.

- **Questions** (`BossQuestion`) : `find` (cliquer la case nommée), `name` (nommer la case
  allumée), `color` (claire ou foncée, sans échiquier), `play` (jouer le coup annoncé dans une
  position de puzzle), `write` (écrire en SAN le coup qu'on vient de voir joué). Cases vues des
  Blancs, jamais deux fois la même de suite ; positions et coups tirés comme pour le module
  Notation (`PuzzlePicker`, `MoveChooser` : roques, promotions, prises, coups à préciser). Chaque
  genre revient autant que les autres (un des moins posés jusque-là) ; sans puzzle, une question de
  case.
- **Temps** : 7 s par question (`JongleurRules::QUESTION_SECONDS`, 6 s jusqu'au 2026-10-24), pour toutes, sur l'horloge du
  serveur. Le départ est stocké à la seconde (tronqué) : la limite ajoute 1 s et la tolérance réseau
  de 2 s, toujours en faveur du joueur. Une réponse en retard (`late`), ou vide (le temps du client
  écoulé), est un échec. Une question rechargée revient avec son temps qui court.
- **Vies** : 3 (`PathRules::BOSS_LIVES`) ; la 3e erreur ferme la séance (`subject_finished`,
  raison `lives_out`), sinon elle se ferme au compte (`count_done`). Le combat est gagné avec
  `count` réponses et moins de 3 erreurs (`Activity::passes`).
- **Soumission** : `answers[0].square` (une case, ou `light` / `dark`), `moves[0]` (UCI) ou
  `written[0]` (SAN, lettres anglaises) ; au plus une réponse. Verdict : `status` (`right`,
  `wrong`, `late`), `expected` (la case, la couleur, ou `{uci, san}`), `answer`, `lives` restantes,
  `position`, `count`.
- **XP** : aucune par question ; un exercice `jongleur` par séance (journal d'activité, série du
  jour), 0 XP. La victoire rapporte la récompense du boss (§ 5).
- **Rejouer** : un boss battu se rejoue depuis le parcours (bouton « Rejouer »), sans rien
  rapporter de plus (§ 4).
- **Écran** (`JongleurPlayer`, page de séance) : « Question n sur N », le Jongleur (il jongle
  pendant la question, jubile à une erreur, boude à une bonne réponse) et sa réplique, les 3 cœurs,
  une barre de 7 s calée sur le départ du serveur (à zéro, une réponse vide part), sous elle la
  **consigne en grand bandeau** (2026-10-22, sous la barre depuis le 2026-10-24 : à droite de
  l'échiquier sur grand écran, au-dessus sur téléphone, échiquier réduit d'autant pour tenir dans la
  hauteur ; une couleur et une icône par genre, la
  case ou le coup demandé, une ligne d'aide ; il rebondit à chaque question et tremble avec
  « Nouveau ! » quand le genre change, `jongleur.order.<genre>`), puis la question :
  échiquier vide à cliquer (`find`), case allumée et son nom tapé au clavier, celui de l'appareil sur écran tactile (`name`), boutons
  Claire / Foncée ou leurs initiales, C / F (L / D en anglais, `coordinates.colorKeys`) (`color`), coup annoncé à jouer (`play`), coup joué sous les yeux puis
  champ et touches des pièces dans la langue de l'interface (`write`). Le verdict reste 0,7 s (juste)
  ou 1,6 s (raté, avec la bonne réponse), puis la question suivante vient seule ; le combat fini,
  `RunOutcome`. **Distractions** légères, environ une question sur trois, tirées de l'id de la
  question (`distractionOf`) : l'échiquier vacille (`shake`) ou des pièces le traversent (`drift`) ;
  aucune si le système demande moins d'animations.
- **Données** : table `mind_map_boss_item` (migration `Version20261102090000`), commune aux futurs
  boss (`boss` = le personnage), dans l'export du compte (`mindMapBossItems`).

## 9. Lots

| Lot | Contenu | État |
|---|---|---|
| M1 | API du moteur : catalogue, progression, lancement, saut, coffres, badges, verrous | fait |
| M2 | Front du moteur : chemin, écrans de boss (cyclope par défaut), coffre, victoire provisoire | fait |
| L1 | Coordonnées : modes « nommer la case » et « couleur de la case » (1.2, 1.3) | fait |
| L2 | Module Notation (1.4 à 1.7, [NOTATION.md](NOTATION.md)) | fait |
| L3 | Parties célèbres (1.8, 1.9) et boss Jongleur (§ 8) | fait |
| L4 | Chapitre 2 : exercices (reconstitution, niveaux 1 à 12), mini-boss 2.1, module Vision (mini-boss 2.2, 2.3, caché), Cyclope | fait |
| L5 | Chapitre 3 : L5a section 3.1 « suivre une pièce » et distractions des boss, vert ; L5b 3.2 « actualiser une position », vert ; L5c 3.3 « relations entre pièces », vert ; L5d page Vision libre | en cours |
| L6 | La Gargouille (distractions fortes, 15 s par question, musique et cris) | écrit 2026-10-20 |
| L7 à L10 | Chapitres 4 à 7 (précisions de l'utilisateur attendues) | à faire |

Jouables aujourd'hui : 1.1 (série de coordonnées `find` d'une minute, Blancs en bas, au moins 10
réponses à 80 %), 1.2 (mode `name`, une minute, au moins 10 réponses à 80 %) et 1.3 (mode `color`,
une minute, au moins 20 réponses à 80 %), [COORDINATES.md](COORDINATES.md), § 2 bis ; 1.4 à 1.6
et 1.7 (leçons comptées du module Notation : 10 coups annoncés, 5 suites, 5 lignes à écrire, 5
lignes du répertoire à compléter, 80 % ; 1.7 refusé sans répertoire de 10 tronçons, raison
`no_repertoire`, [NOTATION.md](NOTATION.md)) ; 1.8 et 1.9 (parties légendaires) ; le Jongleur
(§ 8). Chapitre 2 : 2.1.1 à 2.3.4 = niveaux 1 à 12 de la reconstitution
([BLINDFOLD_RECALL.md](BLINDFOLD_RECALL.md), § 3 bis), 5 positions par leçon, seules les exactes
justes, 80 % ; mini-boss 2.1 = niveaux 2, 3 puis 4, échiquier sans coordonnées, une vie par pièce
fausse ou oubliée ; chapitre 3 : rappel du Jongleur (10 questions) ; section 3.1 = module Vision,
« suivre une pièce » ([VISION.md](VISION.md), § 2 bis), mini-boss 3.1 = 3 trajets × 3 questions ;
section 3.2 « actualiser une position » (§ 2 ter) : 3.2.1 et 3.2.2 en reconstitution après 1 et 3
coups, 3.2.3 à 3.2.5 et le mini-boss en questions Vision ; section 3.3 « relations entre pièces »
(§ 2 quater) : toutes les pièces attaquées, défendues, alignées, en prise, le mini-boss distinguant
« attaquée » et « capture gagnante » ;
mini-boss 2.2 et 2.3 et boss caché 2.3 = module Vision ([VISION.md](VISION.md),
§ 5), 5 questions, 3 vies ; le Cyclope = 2 positions de puzzles de 16 pièces (niveau 14), montrées
30 s chacune, une vie par pièce fausse ou oubliée.
