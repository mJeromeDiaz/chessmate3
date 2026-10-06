# Séances chronométrées — Don't Stay Rooky (phase 4b)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Une **séance** (*run*) est un temps d'entraînement fixé à l'avance (5 à 30 minutes, ou libre de 1 à
60) pendant lequel on enchaîne les éléments d'un **module** : Woodpecker, dans ses deux
modes ([WOODPECKER.md](WOODPECKER.md)), le test des répertoires d'ouvertures
([REPERTOIRE.md § 15](REPERTOIRE.md#15-test-du-répertoire-séances-chronométrées)), les puzzles
classés (§ 5 bis) et le temps libre (§ 5 ter). Le socle est générique : un module
implémente un contrat et hérite du chronomètre, des règles de fin et du récapitulatif. Événements et
journal : [ACTIVITY.md](ACTIVITY.md).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Entité | `App\Entity\Training\Run` (table `training_run`) |
| Enums | `App\Enum\Training\{Module, RunStatus, CloseReason}` |
| Chronomètre | `App\Training\Run\{TimeboxRunner, Step}` |
| Contrat des modules | `App\Training\Module\{TimeboxedModuleInterface, ModuleRegistry, Item, ItemSubmission, ItemResult, Summary}` |
| Événement | `App\Training\Event\RunCompleted` |
| Module Woodpecker | `App\Woodpecker\Training\WoodpeckerModule` |
| Module Répertoire | `App\Repertoire\Training\RepertoireModule` |
| Module Puzzles | `App\Puzzle\Training\PuzzleModule` |
| Module Libre | `App\Training\Free\FreeModule` |
| API | `App\ApiResource\Training\*`, `App\State\Training\*` |
| Front | `services/api.js` (`trainingApi`), `composables/training/useTimeboxedRun.js`, `stores/training.js`, `components/training/{RunLauncher, RunHeader, RunRecap, RunTable, FreeRunPanel}.vue`, `components/puzzle/PuzzleRunDialog.vue`, `utils/training.js`, `pages/index/training/[id].vue` |

## 2. Règles validées

- **Pas de délai de grâce** : la séance s'arrête à l'expiration. Le puzzle à l'écran à ce moment
  n'est **pas compté** (ni reporté à la séance suivante).
- Seule tolérance : **2 s de réseau** (`TimeboxRunner::SUBMISSION_TOLERANCE_MS`) pour une soumission
  envoyée juste avant la fin. Ce n'est pas une grâce : aucun élément n'est servi pendant ce délai.
- **Une seule séance active par utilisateur**, garanti par la base. En lancer une autre renvoie
  vers celle en cours (reprendre ou la terminer).
- Une séance est **à usage unique** : une fois close, elle ne se rouvre jamais. La seule « reprise »
  consiste à rejoindre la **même** séance encore en cours après une déconnexion ou une sortie de page :
  on retrouve le même élément, chronomètre toujours en marche.
- Bouton « Terminer » : clôt la séance avant la fin (`stopped`). Quitter la page ne la clôt pas.
- **Clôture paresseuse** : ni cron ni Scheduler. Une séance abandonnée (onglet fermé) est close à la
  prochaine requête d'entraînement de l'utilisateur, **à la date de son expiration**. Conséquence
  acceptée : `RunCompleted` peut être émis en retard, voire jamais pour un utilisateur qui ne revient
  pas.
- Le temps appartient au **serveur** : expiration, durées et verdicts sont calculés côté API ; le
  front ne fait qu'afficher un compte à rebours recalé sur l'horloge serveur.

## 3. Modèle de données

`training_run` : `module`, `subject_type` + `subject_id` (ex. `woodpecker_set` + id du set),
`config` (JSON, options du module : la portée d'un test de répertoire, les thèmes des puzzles, le format et les notes du temps libre ; aucune pour Woodpecker), `budget_seconds`, `status` (`active`,
`closed`), `started_at`, `expires_at`, `closed_at`, `close_reason`, `summary` (JSON, figé à la
clôture) et `parent_id` (la session dont la séance est une étape, § 9 ; `NULL` pour une séance
lancée seule ; index `idx_training_run_parent`).

| Index | Rôle |
|---|---|
| colonne générée `active_user_id = IF(status = 'active', user_id, NULL)` **VIRTUAL** + `uniq_training_run_active_user` | une seule séance active par utilisateur (même technique que `woodpecker_set`, voir [WOODPECKER.md § 3](WOODPECKER.md#3-modèle-de-données)) |
| `idx_training_run_user_started` | historique d'un utilisateur |
| `idx_training_run_subject (subject_type, subject_id, started_at)` | séances d'un set (page du set) |

`woodpecker_attempt.training_run_id` (nullable) rattache chaque tentative à sa séance.

Motifs de clôture (`CloseReason`, stockés par leur valeur : on ajoute des cas, on n'en renomme
jamais) :

| Valeur | Quand | `closed_at` |
|---|---|---|
| `time_up` | expiration | l'expiration, même si la clôture est constatée plus tard |
| `stopped` | bouton « Terminer » | l'instant de l'arrêt |
| `subject_finished` | le sujet est terminé (dernier cycle d'un set classique) | l'instant de la soumission |
| `subject_resting` | cycle classique terminé, le suivant est au repos (`context.availableAt`) | idem |
| `subject_unavailable` | set mis en pause ou abandonné pendant la séance ; plus rien à tester dans la portée d'un test de répertoire | la requête qui le constate |

## 4. Chronomètre (`TimeboxRunner`)

- `start` : refuse un budget hors [60 s, 3 600 s], clôt d'abord une séance expirée, puis crée la
  séance et laisse le module préparer son sujet dans la même transaction. Une seconde séance active
  est refusée par l'index unique (409).
- `next` : l'élément à jouer, ou aucun si la séance est (désormais) close. Un élément servi et non
  soumis est **resservi** tel quel (rechargement de page), sa durée continue de courir.
- `submit` : accepté jusqu'à 2 s après l'expiration ; au-delà, la séance est close (`time_up`) et la
  soumission refusée (409). Le module peut demander la clôture (sujet terminé, au repos).
- `stop` : idempotent ; `time_up` si l'expiration est déjà passée, `stopped` sinon.
- `closeExpired` : clôture paresseuse, appelée avant chaque requête d'entraînement et avant le jeu
  non chronométré d'un sujet qu'une séance pourrait tenir.

Toute opération verrouille **la séance d'abord, puis le module verrouille son sujet** : jamais
l'inverse, donc pas d'interblocage. À la clôture, le module abandonne l'élément en attente (non
compté) et calcule le récapitulatif ; `RunCompleted` est publié dans la même transaction.

## 5. Contrat d'un module (`TimeboxedModuleInterface`)

Service tagué `app.training.module` (autoconfiguré) et cas dans l'enum `Module` :

| Méthode | Rôle |
|---|---|
| `module()`, `subjectType()` | identifiants stockés dans `training_run` |
| `start(Run, now)` | vérifie que le sujet est jouable et le prépare ; `SubjectNotFoundException` (404, y compris le sujet d'un autre) ou `SubjectUnavailableException` (409, séance non créée) |
| `next(Run, now)` | l'élément en attente de la séance, sinon un nouveau (`Item` : id, type, données scalaires) |
| `submit(Run, ItemSubmission, now)` | juge côté serveur ; `ItemResult` (réussite, clôture éventuelle et son contexte) |
| `close(Run, reason, now)` | abandonne l'élément en attente (non compté) et clôt ce que le module a ouvert (ex. la manche light) |
| `summarize(Run, closedAt)` | le `Summary` figé dans la séance |

`ItemSubmission` rapporte ce que le client a fait (coups UCI tentés, niveau d'indice, solution
affichée, et `thinkMs`, le temps de réflexion qu'il a mesuré, animations exclues), **jamais un résultat**.
Un module qui utilise `thinkMs` le plafonne par le temps écoulé côté serveur depuis que l'élément a
été servi : le client peut minorer son temps, jamais l'augmenter.

**Récapitulatif normalisé** (`Summary`, identique pour tous les modules) : `durationMs` (réelle),
`itemCount`, `successCount`, `failureCount`, `successRate`, `itemsPerMinute`, plus `metrics`
(propres au module) et `context` (motif de clôture). Woodpecker y met `mode`, `solved`, `failed`,
`activeMs` et `averageMs` (temps plafonné à 5 min par puzzle), `rounds`, `added` (croissance du set
pendant la séance), `puzzleCount` et, en classique, `cycle` (numéro, run, joués, échéance).

### 5 bis. Module Puzzles (`puzzles`)

Sujet : l'utilisateur (`subjectType = puzzle_player`, `subjectId` = son id). `config` :
`{themes?: string[]}`, 10 clés connues au plus, combinées en OU (422 sinon). Élément `puzzle` : une
tentative **classée**, choisie comme en jeu libre autour du classement ; le résultat
(`ratingAfter`, `ratingDelta`) suit le Glicko-2 habituel ([PUZZLES.md](PUZZLES.md)). Le premier
puzzle est servi au démarrage : sans puzzle disponible, 409 et pas de séance.

Règles validées (2026-10-04), pour qu'aucun puzzle classé ne puisse être esquivé :

- la tentative classée **en attente** (jeu libre, ou laissée par une séance précédente) est le
  premier puzzle de la séance, même hors des thèmes choisis ;
- le puzzle à l'écran à la fin (temps écoulé, « Terminer ») n'est **pas compté** mais **reste en
  attente**, détaché de la séance : il revient au prochain « puzzle suivant » ;
- pendant la séance, le jeu libre refuse ce puzzle (409, `Rejoindre la séance` côté front).

Récapitulatif : `solved`, `failed`, `activeMs` / `averageMs` (5 min au plus par puzzle), `themes`,
`ratingBefore`, `ratingAfter`, `ratingDelta`. Lancement : page Puzzles, « Séance chronométrée »
(thèmes du filtre).

### 5 ter. Module Libre (`free`)

Temps d'étude libre (livre, vidéo, cours, podcast, autre). Sujet : l'utilisateur
(`subjectType = free_owner`). `config` : `{format: book|video|course|podcast|other, notes?: string}`
(500 caractères au plus ; 422 sinon). Un seul élément, `free_timer` (id = la séance, données
`format` et `notes`), rien à soumettre (400) : la séance finit par « Terminer » ou à l'expiration.
À la clôture, sa **durée serveur** (jusqu'à l'expiration au plus, même constatée plus tard) est
journalisée en `ExerciseCompleted` de type `free_study` ([ACTIVITY.md](ACTIVITY.md)) ; rien si elle
est nulle. Récapitulatif : la durée, `metrics.format` et `metrics.notes`. Pas de point d'entrée
seul : il se lance depuis une session (Session Builder).

### 5 quater. Bilan d'une séance (`ReviewableModuleInterface`)

Une séance **close** se revoit élément par élément (bilan de fin de séance, rejeu des ratés) :
`GET /training/runs/{id}/review` → `{id, module, items}`, chaque élément
`{index, type, status, durationMs, data}` dans l'ordre joué. Le module l'expose en implémentant
aussi `Training\Module\ReviewableModuleInterface::review(Run)` (`ModuleRegistry::reviewer()` le
trouve) ; le temps libre ne l'implémente pas (`items: []`). Statut (`ReviewItem`) : `ok` réussi,
`hint` raté sans coup faux (indice ou solution demandés), `fail` un coup faux. Les éléments non
comptés (puzzle à l'écran à la fin, unité interrompue ou abandonnée) n'y sont pas.

- `puzzle` et `woodpecker_puzzle` : les tentatives résolues de la séance (`training_run_id`), avec le
  puzzle complet (solution comprise : la séance est close), `mistakes`, `hintLevel`,
  `solutionShown`, et pour Woodpecker `number` (sa place dans l'ordre du cycle, 1-based).
- `repertoire_unit` : une entrée par unité présentée (une ligne regroupe ses tronçons), `fail` si un
  de ses tronçons est raté. `data` : `unit`, `rank`, `round`, `repertoireId`, `repertoireName`,
  `orientation`, `label` (celui du dernier tronçon), `moves` (SAN des tronçons bout à bout),
  `firstErrorPly`, et `startFen`, la FEN normalisée d'où part le premier tronçon,
  **figée à la présentation** (`repertoire_presentation.start_fen`) pour rester juste si le
  répertoire change ensuite ; `null` pour les présentations antérieures (non rejouables).

Le rejeu depuis le bilan se fait **côté client**, sans appel d'API : aucun effet sur le classement,
le cycle Woodpecker, les cartes FSRS, l'activité ni la durée de la session.

Ajouter un module : un cas à `Module`, une implémentation du contrat, un `subjectPath` côté front
(`utils/training.js`) et le rendu de son type d'élément dans `pages/index/training/[id].vue`.

## 6. API

Préfixe `/api`, utilisateur authentifié ; une séance d'un autre utilisateur répond **404**.

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `POST /training/runs` | `{module, subjectId, budgetSeconds, config}` : démarre (30 par heure) | 404 sujet, 409 séance déjà en cours ou sujet non jouable, 422 (dont `config` invalide) |
| `GET /training/runs/current` | La séance active, sinon `null` | — |
| `GET /training/runs/{id}` | Une séance, avec `serverNow` | 404 |
| `POST /training/runs/{id}/next` | `{run, item}` : élément à jouer, `item: null` une fois close (600 par 10 min) | 404 |
| `POST /training/runs/{id}/submission` | `{itemId, moves, hintLevel, solutionShown, thinkMs?}` → `{run, result}` (600 par 10 min) | 400 coups impossibles, 404, 409 trop tard, déjà soumis ou séance close |
| `POST /training/runs/{id}/stop` | Termine la séance | 404 |
| `GET /training/runs/{id}/review` | Bilan d'une séance close, élément par élément (§ 5 quater) | 404, 409 séance encore active |

L'identifiant de l'élément est dans le corps, pas dans l'URL : une seule route de soumission quel que
soit le module. `shortName` : `TrainingRun`, `TrainingRunStep`, `TrainingRunReview` ; `requirements` UUID sur les routes
d'item, routes sœurs couvertes par `tests/Functional/Training/RoutingTest.php`. La vue d'un set
Woodpecker liste ses séances (`runs`).

## 7. Front

- `useTimeboxedRun` : phases `idle` → `running` → `timeUp` → `ended`. Décalage d'horloge estimé à
  partir de `serverNow` sur l'échange **au plus court aller-retour** ; le compte à rebours affiche
  l'horloge serveur. À zéro, plus rien n'est joué : le composable demande l'état au serveur, qui
  clôt la séance. Pas de grâce côté client non plus.
- `training/[id]` choisit le lecteur selon le module : `PuzzlePlayer` (Woodpecker) ou
  `RepertoireDrillPlayer` ([REPERTOIRE.md § 15](REPERTOIRE.md#front)) ; `RunHeader` (compte à
  rebours, « Terminer », barre du temps écoulé) leur est commun.
- Woodpecker : après un puzzle réussi, le suivant arrive seul (500 ms) ; après une erreur, la
  solution se déroule, puis « Suivant ». Répertoire : unité réussie, la suivante seule (600 ms) ;
  ratée, « Suivant ».
- `RunLauncher` (page du set, dialogue de test d'un répertoire) : durées 5, 10, 15, 20, 30 min ou
  « Autre » (1 à 60), `config` du module envoyée telle quelle (`show-unit` ajoute le choix tronçons
  ou lignes) ; si une séance est déjà en cours, propose de la reprendre ou de la terminer.
- Alertes (`composables/training/useRunAlerts.js`, `utils/alerts.js`) : un module de session joue
  `front/public/media/son/alert.mp3` 5 s avant sa fin ; un module libre le joue à l'expiration avec
  une notification navigateur « Temps libre terminé » (permission demandée au clic de lancement).
  Chaque alerte une fois par séance, seulement pour une séance vue en cours sur la page.
- Sons de fin (`utils/sounds.js`, `moduleEndSound`), joués par `useRunAlerts` à la clôture de toute
  séance (de session ou non), une fois, seulement pour une séance vue en cours sur la page :
  `bigFail.mp3` si le module est en échec (au moins 3 éléments terminés et moins de 80 % réussis ;
  le temps libre n'échoue jamais), `success3.mp3` sinon ; pour une étape de session, la page recharge
  la session et, si elle est désormais `completed`, joue `success.mp3` à la place. Ces sons, comme
  ceux des puzzles et des unités de répertoire, suivent le réglage « Sons » du profil (`moveSound`).
- `RunEndDialog` (design « Fin de séance ») : bilan qui s'ouvre sur la page dès que la séance est
  close, aussi pour une séance rouverte une fois finie. Il contient :
  - un bandeau du prof du module (content, ou pensif si le module est en échec selon la règle de
    `moduleEndSound`), avec des confettis (`ConfettiBurst`) seulement pour une fin vécue sur la page
    et réussie ;
  - le message du prof, composé côté client : taux de réussite, thème ou ouverture le plus raté ;
  - quatre chiffres selon le module, dont l'XP gagnée dans la séance (`xp` de la revue, écrit par
    le worker : redemandée une fois 3 s plus tard si elle arrive trop tôt) et le niveau atteint ;
  - la grille des éléments (Réussi / Avec aide / Raté) et la liste « À revoir », dont chaque élément
    se rejoue dans le bilan (`RunEndReplay`, colonne de droite ; toute la feuille sur un téléphone),
    **côté client seul** : rien n'est envoyé (ni classement, ni cycle, ni carte FSRS, ni activité, ni
    temps de session ; une étape de session affiche « Session en pause le temps de la révision »).
    Un puzzle se rejoue avec `PuzzlePlayer` (indices et solution, son `resolve` ignoré) ; une unité
    de répertoire depuis sa position de départ (`start_fen`) avec `composables/repertoire/useLineReplay.js`
    (coups adverses joués seuls, mauvais coup repris et bon coup fléché, seul accepté ensuite) ; une
    unité présentée avant `start_fen` est « non rejouable ». « Raté suivant → » enchaîne, l'élément
    rejoué jusqu'au bout est coché « revu » (en mémoire seulement) ;
  - les boutons « Module suivant → » (étape de session), « Bilan de la session → » ou le retour au
    sujet, et « Fermer », qui laisse le récapitulatif avec un bouton « Voir le bilan ».

  Calculs purs dans `utils/runEnd.js` ; données de `GET /training/runs/{id}/review` (§ 5 quater).
- `RunRecap` : récapitulatif normalisé et lignes du module ; `RunTable` : historique avec l'évolution
  des puzzles par minute d'une séance à l'autre.

## 8. Tests

- PHPUnit : `tests/Functional/Training/TimedRunTest.php` (expiration sur l'horloge serveur,
  tolérance de 2 s, reprise du même élément, clôture paresseuse, une séance par utilisateur,
  événements, temps actif plafonné), `ClassicRunTest.php` (cycle qui avance et set tenu, repos,
  enchaînement sans repos, set terminé, pause pendant la séance, cycle perdu pendant la séance) et `RoutingTest.php`.
- Vitest : `use-timeboxed-run.test.js` (décalage d'horloge, phases, fin de temps),
  `training-store.test.js`, `run-end.test.js` (bilan), `use-line-replay.test.js` (rejeu d'une unité).
- Playwright : `tests/e2e/training.spec.js` : une séance light d'1 min terminée avec « Terminer »
  (1 réussi, 1 échoué, bilan et rejeu du raté sans requête à l'API, récapitulatif, historique) et une séance classique d'1 min menée **jusqu'à son
  expiration réelle** (puzzle à l'écran non compté, cycle avancé d'un seul puzzle). Environ 75 s.
  Le test des répertoires a le sien (dont le rejeu d'un tronçon raté depuis le bilan) : `tests/e2e/repertoire-test.spec.js` ([REPERTOIRE.md § 16](REPERTOIRE.md#16-tests)).
- Puzzles et Libre : `tests/Functional/Training/{PuzzleRunTest, FreeRunTest}.php` (puzzles classés
  et thèmes, puzzle en attente qui ouvre la séance, puzzle à l'écran qui reste en attente, jeu libre
  refusé pendant la séance, aucun puzzle ; durée réelle journalisée, séance abandonnée comptée
  jusqu'à l'expiration, options validées) et `tests/e2e/training-modules.spec.js`.

## 9. Sessions

Une **session** est un programme de modules composé dans le Session Builder (`front/src/pages/index/session/new.vue`),
**figé au lancement** et joué étape par étape : chaque étape est une séance chronométrée ordinaire
(toutes les règles ci-dessus s'appliquent) dont `parent_id` est la session.

| Couche | Emplacement |
|---|---|
| Entité | `App\Entity\Training\Session` (table `training_session`) |
| Enums | `App\Enum\Training\{SessionStatus, StepStatus}` |
| Service | `App\Training\Session\SessionManager` ; `TimeboxedModuleInterface::prepare()` (réglages d'une étape → sujet et `config` de la séance) |
| Événement | `App\Training\Event\SessionClosed` |
| API | `App\ApiResource\Training\{Session, SessionLaunch, CreateSessionInput, SessionStepInput, SessionStepView}`, `App\State\Training\{CreateSessionProcessor, SessionNextProcessor, SessionActionProcessor, SessionProvider, SessionViewFactory}` |
| Front | `services/api.js` (`sessionApi`), `stores/session.js` (`launch`, `canLaunch`), `utils/session/{catalog, steps}.js` (`toStep`), `composables/session/useSessionStep.js`, `pages/index/session/[id].vue`, récap de `training/[id].vue`, `components/dashboard/RecentSessions.vue` |

### Règles validées (2026-10-04)

- **Pas de modèles réutilisables** : une session lancée est une instance jouée ; le brouillon reste
  dans le navigateur pour la relancer.
- **Enchaînement manuel** : après chaque module, son récapitulatif, puis « Module suivant » (le
  chrono du suivant ne part qu'au clic).
- **La session appartient à son jour local** (fuseau de l'utilisateur) : `expiresAt` = minuit
  suivant. On peut la reprendre ce jour-là ; au-delà, elle est close paresseusement (`expired`), les
  étapes restantes « non jouées ». Une séance d'une étape commencée avant minuit va à son terme.
- **Étape injouable** (set light en pause ou absent, rien à réviser, plus de puzzle, répertoire
  supprimé) : `next` répond 409 et l'étape reste courante avec sa raison (`blocked`) ; l'utilisateur
  réessaie, la passe (`skip`) ou abandonne.
- **Une seule session active par utilisateur** (colonne générée `active_user_id` + index unique) ;
  en lancer une autre propose de reprendre ou d'abandonner l'actuelle.

### Modèle

`training_session` : `title` (≤ 120), `description` (≤ 500), `steps` (JSON : `module`, `minutes`,
`notes`, `settings`, `status`, `runId`, `blocked`), `current_index`, `status` (`active`,
`completed`, `abandoned`, `expired`), `started_at`, `expires_at`, `closed_at`. Statuts d'étape :
`pending`, `running`, `done`, `skipped`, `unplayed`.

Réglages d'une étape, vérifiés par le module au lancement **et** au démarrage de l'étape :

| Module | `settings` | Sujet de la séance |
|---|---|---|
| `puzzles` | `{themes?: string[]}` | l'utilisateur |
| `woodpecker` | `{}` | son set light en cours **au démarrage de l'étape** (actif, sinon bloquée) |
| `repertoire` | `{repertoireIds: string[]}` (tronçons) | l'utilisateur |
| `free` | `{format}` ; les `notes` de l'étape deviennent celles de la séance | l'utilisateur |

### Déroulé (`SessionManager`)

- **Synchronisation paresseuse** : chaque requête clôt d'abord la séance expirée de l'utilisateur,
  puis réconcilie la session (verrouillée) : une séance close termine son étape ; plus d'étape ⇒
  `completed` ; jour passé sans séance en cours ⇒ `expired`. Pas de cron.
- `next` : prépare l'étape (module), démarre la séance (`TimeboxRunner::start(..., parentId)`), puis
  la rattache à l'étape. Les transactions du chronomètre ne sont jamais imbriquées dans celle de la
  session (un refus y fermerait l'entity manager). Une étape déjà en cours renvoie sa séance.
- `skip` : refusé pendant la séance de l'étape (409). `abandon` : arrête d'abord la séance en cours
  (elle compte comme jouée), idempotent.
- À la clôture, `SessionClosed` (même transaction) : `status`, étapes, faites, passées, temps joué
  (somme des `durationMs` de ses séances).

### API

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `POST /training/sessions` | `{title, description, steps: [{module, minutes (1–60), notes, settings}]}` (1 à 10 étapes ; 20 par heure) | 409 session en cours, 422 (étape invalide, son numéro dans le message) |
| `GET /training/sessions` | Les 10 dernières, de la plus récente | — |
| `GET /training/sessions/current` | La session active (404 sinon) | — |
| `GET /training/sessions/{id}` | Une session : programme, étapes et récap de leurs séances, `durationMs` | 404 |
| `POST /training/sessions/{id}/next` | Démarre l'étape courante : `{session, run}` (limite des démarrages de séance) | 404, 409 (étape bloquée, autre séance en cours, session finie) |
| `POST /training/sessions/{id}/skip` | Passe l'étape courante | 404, 409 |
| `POST /training/sessions/{id}/abandon` | Termine la session | 404 |

`shortName` : `TrainingSession`, `TrainingSessionLaunch` ; `requirements` UUID ; routes sœurs dans
`RoutingTest`. Tests : `tests/Functional/Training/SessionTest.php`, `tests/unit/session-steps.test.js`,
`tests/e2e/session-play.spec.js`.

## 10. Sessions enregistrées (plans)

Une session se **compose et s'enregistre** (`App\Entity\Training\Plan`, table
`training_session_plan`) : programme et réglages. On la lance depuis « Mes sessions » (`/session`),
le bloc « Mes sessions » du tableau de bord ou le constructeur (« Enregistrer et lancer ») ; chaque
lancement crée une **session jouée** (§ 9) avec une copie figée du programme et `plan_id`.

Règles validées (2026-10-04) :

- Répétition : **À la demande** (aucun horaire : lancée depuis la liste), **Quotidienne** (une heure,
  les jours cochés, tous par défaut), **Hebdomadaire** (un jour, une heure). L'heure est locale (fuseau
  de l'utilisateur) ; `App\Training\Plan\Schedule` calcule la prochaine occurrence (`nextAt`, UTC),
  changements d'heure compris (une heure sautée au printemps tombe juste après le saut).
- **Publique / privée** : un simple drapeau pour l'instant (future fonction communautaire).
- **Rappel** (email et/ou navigateur, 10 min, 30 min, 1 h ou 1 jour avant), envoyé par
  `app:training:send-reminders` (cron chaque minute) : voir [NOTIFICATIONS.md](NOTIFICATIONS.md).
  **Calendrier** : voir ci-dessous. Sans horaire (à la demande), ni rappel ni calendrier.
- Modifier ou supprimer une session enregistrée ne change que l'avenir : les sessions jouées gardent
  leur programme, et leur `plan_id` passe à `NULL` (clé `ON DELETE SET NULL`).
- À l'enregistrement, chaque étape est vérifiée par son module (réglages, sujet existant) **sans**
  exiger qu'elle soit jouable maintenant (un set light en pause est accepté) ; au lancement, oui
  (`StepChecker`, 422 avec le numéro de l'étape).
- 50 sessions enregistrées par utilisateur au plus (409 au-delà).

| Couche | Emplacement |
|---|---|
| Entité, enum | `App\Entity\Training\Plan`, `App\Enum\Training\Repetition` |
| Service | `App\Training\Plan\{PlanManager, PlanSettings, Schedule}`, `App\Training\Session\StepChecker` |
| API | `App\ApiResource\Training\{Plan, PlanInput}`, `App\State\Training\{PlanProvider, PlanProcessor}`, lancement dans `CreateSessionProcessor` |
| Front | `services/api.js` (`planApi`), `stores/session.js` (`settings`, `planId`, `save`, `edit`, `startNew`), `utils/session/plans.js`, `components/session/{SessionBuilder, SessionSettings}.vue`, `composables/session/usePlanLaunch.js`, `pages/index/session/{index, new}.vue`, `pages/index/session/plans/[id].vue`, `components/dashboard/MyPlans.vue` |

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `GET /training/plans` | Les sessions enregistrées, dernière modifiée d'abord, avec `nextAt` | — |
| `POST /training/plans` | `{title, description, steps, repetition, time, weekdays, public, reminderEnabled, reminderChannels, reminderMinutes, calendarEnabled}` (120 écritures par heure) | 409 trop de sessions, 422 |
| `GET` / `PUT` / `DELETE /training/plans/{id}` | Lire, remplacer, supprimer | 404, 422 |
| `POST /training/plans/{id}/launch` | Lance une session jouée (201, vue `TrainingSession`) ; son premier module démarre par `/training/sessions/{id}/next` | 404, 409 session en cours, 422 module injouable |

### Calendrier (iCal)

Règles validées (2026-10-04) :

- Chaque utilisateur peut créer une **adresse privée** de calendrier (profil, section
  « Calendrier ») : `GET /api/calendar/{jeton}.ics`, à ajouter à Google Agenda (« À partir de
  l'URL »), Apple Calendrier ou Outlook (lien `webcal://`). L'agenda s'y abonne et suit les
  changements. Le flux liste les sessions répétées cochées « Intégrer à mon calendrier ».
- Le jeton est gardé **chiffré** (clé dédiée `CALENDAR_TOKEN_KEY`, `SecretBox`) pour réafficher
  l'adresse, et trouvé par son empreinte sha256 ; « Nouvelle adresse » invalide l'ancienne,
  « Désactiver » supprime le flux. Sécurité : [SECURITY.md § 8.8](SECURITY.md).
- Une session = un événement récurrent (`RRULE:FREQ=WEEKLY;BYDAY=...`) à l'heure locale
  (`DTSTART;TZID=<fuseau de l'utilisateur>`, `VTIMEZONE` dérivé des transitions de PHP ; heures UTC
  pour un utilisateur sans fuseau), durée = somme des modules, première occurrence = la prochaine
  après la dernière modification (l'agenda ne réécrit pas le passé). `VALARM` seulement si la
  session a un rappel (même délai). UID stable `<id du plan>@dontstayrooky`.
- Une session répétée se télécharge aussi en `.ics` (« Mes sessions », icône agenda) : import
  ponctuel, qui ne suit pas les changements.
- Écrivain maison (`IcsWriter`) : les bibliothèques disponibles n'écrivent pas de `RRULE`. Lignes
  pliées à 75 octets sans couper un caractère UTF-8, texte échappé (RFC 5545).

| Couche | Emplacement |
|---|---|
| Entité | `App\Entity\Training\CalendarFeed` (table `training_calendar_feed`, une ligne par utilisateur) |
| Service | `App\Training\Calendar\{FeedTokens, IcsWriter, TimezoneComponent}` |
| API | `App\Controller\Training\{CalendarController, CalendarFeedController}` (contrôleurs simples : une adresse et des fichiers) |
| Front | `services/api.js` (`calendarApi`), `components/profile/CalendarSection.vue`, bouton `.ics` de `pages/index/session/index.vue`, indication dans `SessionSettings.vue` |

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `GET /training/calendar` | `{url, webcalUrl}` de l'adresse, `null` sans adresse | — |
| `POST /training/calendar` | Crée ou remplace l'adresse (20 par heure avec la révocation) | 429 |
| `DELETE /training/calendar` | Supprime l'adresse (204) | 429 |
| `GET /calendar/{jeton}.ics` | **Public** : le flux (`text/calendar`), 120 lectures par heure par jeton | 404, 429 |
| `GET /training/plans/{id}/calendar.ics` | Une session répétée en pièce jointe | 404 (autre utilisateur, à la demande) |

Tests : `tests/Unit/Training/IcsWriterTest.php`, `tests/Functional/Training/CalendarTest.php`,
`tests/e2e/session-play.spec.js` (« calendar: ... »).

Constructeur : un nouveau brouillon reste dans le navigateur jusqu'à « Enregistrer » ; une fois
enregistré, il est vidé et la session se modifie sur `/session/plans/:id` (le brouillon d'une
nouvelle session n'est pas touché). Tests : `tests/Unit/Training/ScheduleTest.php`,
`tests/Functional/Training/PlanTest.php`, `tests/unit/session-plans.test.js`,
`tests/e2e/session-play.spec.js`.
