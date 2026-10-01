# Activité — ChessMate (phase 4, socle)

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Socle commun à tous les modes d'entraînement : fuseau horaire de l'utilisateur, événements de domaine
publiés après le commit, et journal d'activité. Les phases suivantes (XP, séries, dashboard) s'y
branchent sans modifier le code des exercices. Premier consommateur : [WOODPECKER.md](WOODPECKER.md).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Événements | `App\Activity\Event\{DomainEventInterface, ExerciseCompleted}`, `App\Woodpecker\Event\*` |
| Publication | `App\Activity\EventPublisher` (seul point d'entrée) |
| Journal | `App\Entity\Activity\LogEntry`, `App\Activity\Log\{ActivityLogger, LocalDate}`, `App\Activity\Handler\{LogExerciseCompleted, AcknowledgeDomainEvent}` |
| Reprise | `App\Activity\Backfill\SourceInterface`, `App\Command\Activity\BackfillCommand` (`app:activity:backfill`), `App\Puzzle\Attempt\AttemptBackfillSource` |
| Enum | `App\Enum\Activity\ExerciseType` |
| Front | `utils/timezone.js`, `components/profile/TimezoneSection.vue`, `stores/auth.js` (détection du fuseau) |

## 2. Temps : UTC partout, fuseau de l'utilisateur pour les jours

Règle : **tout instant est créé, stocké et comparé en UTC** ; un **jour local** (« aujourd'hui »,
échéance d'un cycle) est toujours calculé explicitement avec le fuseau IANA de l'utilisateur.

- **PHP** : `Kernel::boot()` impose `date_default_timezone_set('UTC')`, indépendamment de `php.ini`.
- **MySQL** : chaque connexion exécute `SET time_zone = '+00:00'` (option PDO `1002`,
  `config/packages/doctrine.yaml`). Les colonnes `DATETIME` ne sont pas converties par MySQL : c'est
  l'application qui écrit de l'UTC ; la session UTC garantit seulement que `NOW()`/`CURDATE()`
  s'accordent avec PHP.
- Un test fonctionnel (`TimezoneTest`) vérifie les deux côtés.

### `User.timezone`

`VARCHAR(64)` nullable, validé par `Assert\Timezone` (liste IANA de PHP). Sans valeur, l'application
utilise UTC (`User::getDateTimeZone()`).

| Moment | Comportement |
|---|---|
| Inscription | champ facultatif `timezone` de `POST /api/auth/register` |
| Après toute connexion (mot de passe, OAuth, refresh) | si le profil n'a pas de fuseau, le front envoie celui du navigateur (`Intl.DateTimeFormat().resolvedOptions().timeZone`) à `PUT /api/profile/timezone` ; le flux de connexion et sa 2FA ne changent pas |
| Profil | liste déroulante filtrable (`TimezoneSection.vue`) |

Un changement de fuseau **ne réécrit pas le passé** : `local_date` des entrées déjà journalisées et
échéances Woodpecker déjà calculées restent telles quelles.

## 3. Événements de domaine (contrat stable)

Messages Messenger immuables, **types scalaires uniquement** (jamais d'entité), qui implémentent
`DomainEventInterface` (`getUserId()`, `getOccurredAt()`). On ajoute des champs, on n'en renomme ni
n'en supprime jamais.

| Événement | Émis par | Contenu |
|---|---|---|
| `ExerciseCompleted` | chaque exercice terminé | `userId`, `type` (`ExerciseType`), `success`, `durationMs` (serveur), `itemCount`, `sourceType` + `sourceId`, `occurredAt` (UTC), `metadata` (petit tableau) |
| `Woodpecker\Event\CycleCompleted` | fin d'un cycle dans les temps | set, numéro de cycle et de run, réussis/échoués, temps actif, durée calendaire, échéance |
| `Woodpecker\Event\CycleLost` | échéance dépassée | set, cycle, run, puzzles joués, échéance |
| `Woodpecker\Event\SetCompleted` | dernier cycle terminé | set, nombre de cycles et de puzzles, runs perdus |
| `Woodpecker\Event\SetGrown` | croissance d'un set light | set, manche, puzzles ajoutés, nouvelle taille |
| `Training\Event\RunCompleted` | clôture d'une séance chronométrée ([TRAINING.md](TRAINING.md)) | séance, module, sujet (`subjectType` + `subjectId`), `parentId`, motif, budget, durée réelle, éléments et réussites, `startedAt` |

`ExerciseType` : `puzzle_rated`, `puzzle_unrated`, `woodpecker_puzzle`, `repertoire_segment` (extensible).

| Source | `type` | `sourceType` |
|---|---|---|
| Puzzle classé (phase 2) | `puzzle_rated` | `puzzle_attempt` |
| Rejeu non classé (historique, puzzle récalcitrant) | `puzzle_unrated` | `puzzle_attempt` |
| Puzzle Woodpecker (cycle classique ou manche light) | `woodpecker_puzzle` | `woodpecker_attempt` |
| Tronçon présenté dans un test de répertoire (une ligne : un par tronçon traversé) | `repertoire_segment` | `repertoire_presentation` |

`metadata` d'un puzzle Woodpecker : `setId`, `cycle`, `run`, `puzzleId` (Lichess), `mode`
(`classic`, `light`) et, joué en séance, `trainingRunId`.

`RunCompleted` peut arriver **en retard, voire jamais** : une séance abandonnée n'est close qu'à la
prochaine requête d'entraînement de l'utilisateur (clôture paresseuse, [TRAINING.md § 2](TRAINING.md#2-règles-validées)).
Un futur handler (XP, séries) ne doit donc pas supposer qu'une séance commencée produit un
`RunCompleted` ; `occurredAt` vaut l'instant de clôture (l'expiration pour `time_up`).

### Publication « après le commit » : outbox transactionnelle

Tous les `DomainEventInterface` sont routés vers le transport **`activity`**, un transport Doctrine
sur la connexion de l'application (`config/packages/messenger.yaml`) :

- publier = `INSERT` dans `messenger_messages` **dans la même transaction** que l'exercice ;
- rollback ⇒ le message disparaît avec le reste : rien n'est émis ;
- commit ⇒ le message existe, un worker le traite (5 relances, backoff ×3, puis transport `failed`) ;
- durable : un crash juste après le commit ne perd aucun événement.

`EventPublisher::publish()` **refuse** (`LogicException`) d'être appelé hors transaction : c'est ce qui
garantit la propriété ci-dessus. Ne jamais basculer `activity` sur un transport non transactionnel.

La table `messenger_messages` est créée par une migration (`Version20260928161420`), le DSN utilisant
`auto_setup=0`.

**Livraison « au moins une fois »** : tout handler doit être **idempotent**, par exemple avec une clé
unique sur `(sourceType, sourceId)`.

**Tout événement a au moins un handler** : Messenger rejette un message sans handler
(`NoHandlerForMessageException`), et le worker le relancerait 5 fois avant de le ranger dans
`failed`. `AcknowledgeDomainEvent`, handler vide typé `DomainEventInterface`, consomme donc les
événements auxquels rien ne réagit encore (`RunCompleted`, `SetGrown`, `Cycle*`, `SetCompleted`) ;
`EventHandlingTest` vérifie chaque événement du code, les futurs compris.

Worker (production : sous superviseur, redémarré à chaque déploiement) :

```bash
bin/console messenger:consume activity async --time-limit=3600
```

En test, `activity` reste un vrai transport Doctrine (c'est son comportement transactionnel qu'on
teste) ; DAMA annule ses lignes. `ActivityOutboxTrait` consomme la file dans les tests fonctionnels.

## 4. Journal d'activité

Table `activity_log_entry` (entité `readOnly`, jamais modifiée), une ligne par exercice terminé :

| Colonne | Rôle |
|---|---|
| `user_id` | FK `ON DELETE CASCADE` (suppression de compte) |
| `exercise_type`, `success`, `duration_ms`, `item_count` | copie de l'événement |
| `source_type`, `source_id` | origine (`ascii_bin`) |
| `occurred_at` | instant UTC |
| `local_date` | jour local de `occurred_at` dans le fuseau de l'utilisateur **au moment de l'écriture** |
| `timezone` | fuseau utilisé pour `local_date` |
| `metadata` | JSON |

Index : `uniq_activity_log_entry_source (source_type, source_id)` (idempotence),
`idx_activity_log_entry_user_date (user_id, local_date)`,
`idx_activity_log_entry_user_type_date (user_id, exercise_type, local_date)` (futures séries et
statistiques par jour).

Écriture : `LogExerciseCompleted` → `ActivityLogger::record()`, un
`INSERT … ON DUPLICATE KEY UPDATE id = id`. Une relivraison ou une reprise relancée ne fait rien
(`INSERT IGNORE` masquerait aussi des erreurs sans rapport). Un utilisateur supprimé entre-temps :
l'événement est ignoré.

`LocalDate::of()` applique le décalage en vigueur à l'instant donné (heure d'été gérée) :
2026-07-14 22:30 UTC est le 15 juillet à Paris, 2026-01-14 22:30 UTC encore le 14.

## 5. Reprise des données

```bash
bin/console app:activity:backfill [--batch-size=1000]
```

Parcourt chaque source (`SourceInterface`, tag autoconfiguré) par lots avec pagination par clé
(mémoire constante), et écrit via le même `ActivityLogger`. Relançable sans doublon. Aujourd'hui, seule
la phase 2 fournit une source (`AttemptBackfillSource` : tentatives résolues).

Limite connue : pour l'historique, `local_date` est calculé avec le fuseau connu **au moment de la
reprise**, pas celui de l'époque (inconnu).

## 6. Ajouter un type d'exercice

1. Ajouter un cas à `ExerciseType`.
2. Dans le service du domaine, **dans la transaction** qui résout l'exercice, appeler
   `EventPublisher::publish(new ExerciseCompleted(...))` avec un `sourceType` propre au domaine et
   l'identifiant de la tentative comme `sourceId`.
3. Si des exercices de ce type existent déjà en base, implémenter `SourceInterface` pour la reprise.
4. Tests : événement émis au commit, rien au rollback (voir `ActivityLogTest`, `PuzzleActivityTest`).

Pour réagir à un événement (XP, série…) : un simple `#[AsMessageHandler]`, **idempotent**, sans
toucher au code des exercices.
