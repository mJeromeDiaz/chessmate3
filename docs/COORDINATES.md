# Coordonnées — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Une série de coordonnées montre un échiquier vide, sans coordonnées, et le nom d'une case ; on
clique la case. Juste : vert, faux : rouge, et la case suivante arrive aussitôt. Une série dure
5 minutes et se joue avec les Blancs ou les Noirs en bas ; **chaque orientation se valide
séparément**. La validation est un objectif (badge, bonus d'XP) : elle n'ouvre rien. Le module est
indépendant des puzzles à l'aveugle ([BLINDFOLD.md](BLINDFOLD.md)), qui n'ont aucun prérequis.

Choix validés (2026-10-06 et 2026-10-07) : cases tirées par le serveur au départ, retour immédiat
côté client, réponses envoyées par paquets et rejugées par le serveur ; validation des deux
orientations ; XP forfaitaire par série et bonus à la première validation de chaque orientation.
2026-10-07 : sorti du domaine Blindfold (domaine `Coordinates`, table `coordinates_series`, migration
`Version20261011090000` qui renomme aussi les sources stockées `blindfold_coordinate_series` et
`blindfold_validation`), sans verrou sur le jeu à l'aveugle.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles (seuils) | `App\Coordinates\Series\CoordinateRules` |
| Tirage | `App\Coordinates\Series\SquareDrawer` |
| Entité | `App\Entity\Coordinates\Series` (table `coordinates_series`) |
| Enum | `App\Enum\Coordinates\Orientation` (`white`, `black`) |
| Module de séance | `App\Coordinates\Training\CoordinatesModule` (`Module::Coordinates`, [TRAINING.md](TRAINING.md)) |
| Événement | `App\Coordinates\Event\SeriesValidated` |
| API | `App\ApiResource\Coordinates\Overview`, `App\State\Coordinates\OverviewProvider` |

## 2. Règles (`CoordinateRules`)

| Constante | Valeur | Rôle |
|---|---|---|
| `SERIES_SECONDS` | 300 | durée d'une série (minutes entières : une étape de session se compte en minutes) |
| `MIN_ANSWERS` | 50 | réponses minimum pour valider |
| `MIN_SUCCESS_RATE` | 0,95 | taux de réussite minimum pour valider |
| `SQUARES_PER_SERIES` | 900 | cases tirées (3 par seconde : plus que quiconque n'en répond) |
| `MAX_ANSWERS_PER_SUBMISSION` | 100 | réponses par envoi |

Une série **valide** son orientation si elle a au moins `MIN_ANSWERS` réponses, au moins
`MIN_SUCCESS_RATE` de réussite **et** est allée à son terme (`time_up`, ou plus aucune case :
`subject_finished`) ; une série terminée avec « Terminer » ne valide pas. Le verdict est figé à la
clôture : changer un seuil ne revient pas sur les séries déjà validées. Les seuils ne sont écrits
nulle part ailleurs : le front les lit dans l'API.

## 3. Déroulé (module `coordinates`)

- **Démarrage** : `POST /training/runs` `{module: 'coordinates', subjectId: <id de l'utilisateur>,
  budgetSeconds: 300, config: {orientation: 'white'|'black'}}`. Toute autre durée, orientation ou
  option : 422. Le serveur tire les 900 cases (uniformes, jamais deux fois la même d'affilée).
- **Élément** : un seul par série, `coordinates_series` (id = la série), données `orientation`,
  `squares` (toutes les cases tirées), `answered` et `successCount` (une page rechargée reprend après
  les réponses déjà jugées), `rules`.
- **Réponses** : le client juge chaque clic lui-même pour le retour immédiat et envoie les réponses
  **par paquets** (toutes les quelques secondes, et à la fin) par la route de soumission commune :
  `{itemId, answers: [{index, square, ms}]}`. Une requête par clic dépasserait la limite de
  600 soumissions par 10 minutes. Le serveur :
  - exige des index qui se suivent, sans trou après les réponses déjà jugées (400 sinon) ;
  - garde le premier verdict d'une réponse renvoyée (paquet rejoué après une coupure) ;
  - juge chaque réponse contre la case tirée au même rang ;
  - plafonne les temps (`ms`, temps passé sur la case) : leur somme ne dépasse jamais le temps écoulé
    sur son horloge ;
  - refuse un paquet arrivé plus de 2 s après l'expiration (409), comme toute soumission.
  Résultat : `{results: [{index, correct}], answerCount, successCount}`.
- **Clôture** : la série valide ou non (§ 2). Si elle a au moins une réponse, elle est journalisée en
  un `ExerciseCompleted` `coordinates_series` (`success` = validante, `itemCount` = réponses ;
  [ACTIVITY.md](ACTIVITY.md)), et une série validante publie `SeriesValidated`.
- **Récapitulatif** : `metrics` `orientation`, `validated`, `answeredMs`, `averageMs`, `rules`.
- **Bilan** (`GET /training/runs/{id}/review`) : un élément `coordinate` par réponse, `ok` ou `fail`,
  `durationMs`, `data: {index, target, clicked}` (le ruban de fin de série).

Session Builder : une étape `coordinates` dure exactement `SERIES_SECONDS` (sinon 422), réglage
`orientation`. Côté front, la carte « Coordonnées » (prof Noctis) a un champ `fixedDuration` : sa
durée n'est pas choisie mais vient des règles de l'API (`fetchSubjects` du store de session les
charge et recale les modules du programme ; tant qu'elles manquent, le module ne s'ajoute pas :
« Chargement de la durée… »).

Seule la série sert le client : il connaît les cases à l'avance et pourrait tricher avec un script.
Accepté : rien ne dépend de ce résultat hors de l'utilisateur lui-même.

## 4. Modèle de données

`coordinates_series` : `user_id`, `run_id` (unique, cascade), `orientation`, `squares`
(JSON), `answers` (JSON, `[{square, ms}]`, la réponse i porte sur la case i), `answer_count`,
`success_count`, `answered_ms`, `validated`, `started_at`, `closed_at`. Index
`(user_id, started_at)` (historique) et `(user_id, orientation, validated)` (état). Exportée avec le
compte (`entrainement.json`, `coordinates`).

## 5. API

| Endpoint | Rôle |
|---|---|
| `GET /coordinates` | `{rules, orientations: [{orientation, validated, validatedAt, seriesCount, best}], history}` : seuils, état de chaque orientation (première validation, séries closes, meilleure série = le plus de bonnes réponses), 20 dernières séries closes. Clôt d'abord une série abandonnée. |

## 6. XP

20 XP par série d'au moins 10 réponses (validante ou non), +100 à la première validation de chaque
orientation ([GAMIFICATION.md](GAMIFICATION.md)). Source du bonus : `coordinates_validation`
(clé `<utilisateur>:<orientation>`) ; celle de la série : `coordinates_series`.

## 7. Front

- Page `/coordinates` (`front/src/pages/index/coordinates/index.vue`, lien « Coordonnées » du
  menu) : les règles (lues dans l'API), l'état des Blancs et des Noirs, le lancement d'une série
  (`RunLauncher` avec `fixed-minutes`, orientation au choix, la première non validée par défaut)
  et les 20 dernières séries, chacune avec son ruban (bilan chargé à l'ouverture).
- Série : `components/coordinates/CoordinatesPlayer.vue` sur `training/[id]`. Échiquier vide
  (`ChessBoard` avec `:coordinates="false"` et `square-input` : une case cliquable par case,
  événement `square`), la case à trouver en grand, verdict immédiat (case verte, ou rouge et la
  bonne en vert, 400 ms), compteurs. Les clics ne comptent plus à zéro.
- Envoi : `composables/coordinates/useCoordinatesSeries.js` juge chaque clic, envoie les réponses
  toutes les 3 s (ou dès 100 en attente), garde un paquet qui a échoué (réseau) pour le suivant et
  oublie un paquet refusé parce que la série est close. Les dernières réponses partent avant la
  clôture grâce à `useTimeboxedRun().beforeClose()` (attendu avant « Terminer » et avant la requête
  qui laisse le serveur clore la série à zéro). Un onglet fermé perd les réponses pas encore
  envoyées (3 s au plus).
- Fin de série : l'écran de résultat commun ([TRAINING.md](TRAINING.md), `RunResult`) ; une case
  n'a rien à rejouer, il propose « Leçon suivante → » au lieu de corriger les erreurs. Le ruban
  `components/coordinates/CoordinateRibbon.vue` (une cellule par réponse, verte ou rouge ; infobulle
  ou toucher : « #3 · e4 → d4 · 1,2 s » ; totaux) reste dans l'historique de la page. Calculs
  purs dans `utils/coordinates.js` ; un taux s'affiche arrondi vers le bas (`formatRate` de
  `utils/format.js`) : 94,6 % ne se lit jamais « 95 % ».

## 8. Tests

- Playwright : `tests/e2e/coordinates.spec.js` (une série Noirs arrêtée après 5 réponses dont une
  fausse : verdict immédiat, réponses envoyées avant « Terminer », ruban et case ratée au bilan,
  pas de validation, historique de la page). Une série entière dure 5 min : sa validation est
  couverte par PHPUnit.
- Vitest : `coordinates.test.js` (ruban, totaux, texte de validation, formats),
  `use-coordinates-series.test.js` (jugement, paquets, reprise, envoi avant clôture),
  `use-timeboxed-run.test.js` (`beforeClose`), `run-end.test.js` (bilan d'une série),
  `session-catalog.test.js` et `session-store.test.js` (carte du Session Builder, durée fixe).
- PHPUnit : `tests/Unit/Coordinates/CoordinatesTest.php` (seuils, tirage, jugement) et
  `tests/Functional/Coordinates/CoordinatesRunTest.php` (validation par orientation et une seule fois
  le bonus, recalcul de l'XP, ce qui ne valide pas, réponses rejugées, paquets rejoués, trous, temps
  plafonnés, paquet tardif, durée fixe et options, étape de session, état privé).
