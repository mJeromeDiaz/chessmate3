# Séances chronométrées — ChessMate (phase 4b)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Une **séance** (*run*) est un temps d'entraînement fixé à l'avance (5 à 30 minutes, ou libre de 1 à
60) pendant lequel on enchaîne les éléments d'un **module** : aujourd'hui Woodpecker, dans ses deux
modes ([WOODPECKER.md](WOODPECKER.md)). Le socle est générique : un futur module (répertoire, calcul…)
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
| API | `App\ApiResource\Training\*`, `App\State\Training\*` |
| Front | `services/api.js` (`trainingApi`), `composables/training/useTimeboxedRun.js`, `stores/training.js`, `components/training/{RunLauncher, RunRecap, RunTable}.vue`, `utils/training.js`, `pages/index/training/[id].vue` |

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
`config` (JSON, options du module, aucune aujourd'hui), `budget_seconds`, `status` (`active`,
`closed`), `started_at`, `expires_at`, `closed_at`, `close_reason`, `summary` (JSON, figé à la
clôture) et `parent_id` (future séance multi-modules, phase 6 ; toujours `NULL`).

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
| `subject_unavailable` | set mis en pause ou abandonné pendant la séance | la requête qui le constate |

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
affichée), **jamais un résultat**.

**Récapitulatif normalisé** (`Summary`, identique pour tous les modules) : `durationMs` (réelle),
`itemCount`, `successCount`, `failureCount`, `successRate`, `itemsPerMinute`, plus `metrics`
(propres au module) et `context` (motif de clôture). Woodpecker y met `mode`, `solved`, `failed`,
`activeMs` et `averageMs` (temps plafonné à 5 min par puzzle), `rounds`, `added` (croissance du set
pendant la séance), `puzzleCount` et, en classique, `cycle` (numéro, run, joués, échéance).

Ajouter un module : un cas à `Module`, une implémentation du contrat, un `subjectPath` côté front
(`utils/training.js`) et le rendu de son type d'élément dans `pages/index/training/[id].vue`.

## 6. API

Préfixe `/api`, utilisateur authentifié ; une séance d'un autre utilisateur répond **404**.

| Endpoint | Rôle | Erreurs |
|---|---|---|
| `POST /training/runs` | `{module, subjectId, budgetSeconds}` : démarre (30 par heure) | 404 sujet, 409 séance déjà en cours ou sujet non jouable, 422 |
| `GET /training/runs/current` | La séance active, sinon `null` | — |
| `GET /training/runs/{id}` | Une séance, avec `serverNow` | 404 |
| `POST /training/runs/{id}/next` | `{run, item}` : élément à jouer, `item: null` une fois close (300 par 10 min) | 404 |
| `POST /training/runs/{id}/submission` | `{itemId, moves, hintLevel, solutionShown}` → `{run, result}` (300 par 10 min) | 400 coups impossibles, 404, 409 trop tard, déjà soumis ou séance close |
| `POST /training/runs/{id}/stop` | Termine la séance | 404 |

L'identifiant de l'élément est dans le corps, pas dans l'URL : une seule route de soumission quel que
soit le module. `shortName` : `TrainingRun`, `TrainingRunStep` ; `requirements` UUID sur les routes
d'item, routes sœurs couvertes par `tests/Functional/Training/RoutingTest.php`. La vue d'un set
Woodpecker liste ses séances (`runs`).

## 7. Front

- `useTimeboxedRun` : phases `idle` → `running` → `timeUp` → `ended`. Décalage d'horloge estimé à
  partir de `serverNow` sur l'échange **au plus court aller-retour** ; le compte à rebours affiche
  l'horloge serveur. À zéro, plus rien n'est joué : le composable demande l'état au serveur, qui
  clôt la séance. Pas de grâce côté client non plus.
- Après un puzzle réussi, le suivant arrive seul (500 ms) ; après une erreur, la solution se déroule,
  puis « Suivant ».
- `RunLauncher` (page du set) : durées 5, 10, 15, 20, 30 min ou « Autre » (1 à 60) ; si une séance
  est déjà en cours, propose de la reprendre ou de la terminer.
- `RunRecap` : récapitulatif normalisé et lignes du module ; `RunTable` : historique avec l'évolution
  des puzzles par minute d'une séance à l'autre.

## 8. Tests

- PHPUnit : `tests/Functional/Training/TimedRunTest.php` (expiration sur l'horloge serveur,
  tolérance de 2 s, reprise du même élément, clôture paresseuse, une séance par utilisateur,
  événements, temps actif plafonné), `ClassicRunTest.php` (cycle qui avance et set tenu, repos,
  enchaînement sans repos, set terminé, pause pendant la séance, cycle perdu pendant la séance) et `RoutingTest.php`.
- Vitest : `use-timeboxed-run.test.js` (décalage d'horloge, phases, fin de temps),
  `training-store.test.js`.
- Playwright : `tests/e2e/training.spec.js` : une séance light d'1 min terminée avec « Terminer »
  (1 réussi, 1 échoué, récapitulatif, historique) et une séance classique d'1 min menée **jusqu'à son
  expiration réelle** (puzzle à l'écran non compté, cycle avancé d'un seul puzzle). Environ 75 s.
