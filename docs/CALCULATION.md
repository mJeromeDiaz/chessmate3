# Calcul — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le module **Calcul** entraîne la profondeur de calcul : une position de mat s'affiche sur un
**échiquier figé**, on calcule toute la ligne de tête, puis on la **joue d'un trait, les deux
camps**, très vite. Il sert la capacité « Profondeur de calcul » de la carte mentale (parcours
`calculation`, [MIND_MAP.md](MIND_MAP.md), § 5 bis ; contenu de l'utilisateur :
[mindMap/profondeur_de_calcul.md](mindMap/profondeur_de_calcul.md)) et **ne se lance pour
l'instant que depuis une étape du parcours**.

Choix validés (2026-10-21) : réflexion libre, plafonnée à 2 min, close par « Je réponds » ; chaque
demi-coup joué moins de 2 s après le précédent (le premier, 2 s après « Je réponds ») ; le joueur
joue aussi la défense, qui doit être celle de la solution ; le dernier coup peut être n'importe quel
mat ; puzzles de mat, non classés ; 4 XP par ligne juste, 1 par ligne fausse ou en retard.

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Calculation\CalculationRules`, XP dans `App\Gamification\Xp\XpRules` (`CALCULATION_*`) |
| Tirage | `App\Calculation\Drawing\{MatePicker, Line}` |
| Jugement | `App\Calculation\Judge\{LineJudge, IllegalLineException}` |
| Entité | `App\Entity\Calculation\Attempt` (table `calculation_attempt`, migration `Version20261106090000`) |
| Enum | `App\Enum\Calculation\AttemptStatus` (`pending`, `right`, `wrong`, `late`) |
| Module de séance | `App\Calculation\Training\CalculationModule` (`Module::Calculation`, `calculation`) |
| Front | `components/calculation/CalculationPlayer.vue` (dans la page de séance), `utils/calculation.js`, `i18n/<langue>/calculation.json`, `trainingApi.ready` |
| API | `POST /training/runs/{id}/ready` (`Training\Module\ReadyModuleInterface`, `TimeboxRunner::ready`) ; la ligne dans `moves` de la soumission, `pass: true` quand le temps est écoulé |

## 2. Les positions

Config de la séance (posée par l'étape du parcours, `Run::isPathStep()` ; toute autre séance est
refusée, 422) : `mate` (1 à 4), `capture` (une capture dans la ligne), `count` (les lignes ; la
séance se ferme à son compte) et `lives` (elle se ferme à autant d'échecs).

Un puzzle du thème Lichess `mateIn<mate>` dont la solution a exactement `2 × mate − 1` demi-coups
(après le premier coup de l'adversaire, joué d'office), qui finit bien par un mat et, avec
`capture`, contient une capture. Autour du classement puzzle du joueur moins 150
(`RATING_GAP` ; 1500 sans classement), élargi pas à pas jusqu'à tout classement (les tranches de
Parcours, `ParcoursRules::ranges`). Jamais deux fois le même puzzle dans une séance tant qu'il y en
a un autre ; d'abord un puzzle que le joueur n'a jamais eu ici. Rien ne compte pour le classement.

L'élément (`type: calculation`) : `fen`, `color` (le camp du joueur), `mate`, `capture`,
`thinkSeconds` (120), `plySeconds` (2), `position`, `count`, `lives` (restantes, ou `null`),
`startedAt`, `readyAt`. La solution n'est jamais envoyée avant la réponse.

## 3. Le déroulé et le jugement

1. **Réflexion** : l'échiquier est figé. « Je réponds » appelle `POST /training/runs/{id}/ready
   {itemId}` → `{data: {readyAt}}` ; le serveur note l'instant, une fois (un second appel rend le
   premier). Passé `thinkSeconds`, la réponse s'ouvre d'elle-même : sans appel, l'horloge de la
   réponse part de la fin de la réflexion, et un appel plus tardif n'y change rien.
2. **Réponse** : la ligne entière en UCI (`moves`), les deux camps. Le serveur accepte jusqu'à
   `readyAt` (à la seconde, + 1 s) + 2 s × demi-coups de la solution + 2 s de tolérance réseau ;
   l'horloge par demi-coup (2 s après le précédent) n'est tenue que par le front. En retard, ou
   `pass: true` (le front l'envoie quand son horloge tombe) : `late`, un échec.
3. **Jugement** (`LineJudge`) : chaque demi-coup légal (sinon 400) ; ceux de la solution, défense
   comprise, jusqu'au mat ; un mat donné par le joueur termine la ligne juste, même plus tôt ou par
   un autre coup (comme Lichess) ; une ligne arrêtée avant le mat est fausse là où elle s'arrête.

Le résultat : `status`, `expected` et `answer` (`{uci, san}`), `mistakeAt` (le premier demi-coup
hors solution), `position`, `count`, `lives`, `durationMs`. La séance se ferme (`subject_finished`)
à son compte (`count_done`) ou à court de vies (`lives_out`). Le résumé : `mate`, `count`, `lives`,
`right`, `wrong`, `late`, `activeMs`, `averageMs`. La relecture rejoue chaque ligne.

## 4. Journal, XP, données

Chaque ligne publie un `ExerciseCompleted` (`calculation`, source `calculation_attempt`, metadata
`mate`, `status`, `puzzleId`, `trainingRunId`) : 4 XP juste, 1 sinon, dans le plafond du jour. Les
lignes sont supprimées avec le compte (cascade) et exportées (`entrainement.json` :
`calculationAttempts`).

## 5. Lots

- K1a (2026-10-21) : l'API, le chapitre 1 du parcours calcul branché (1.1 à 1.4, mini-boss).
- K1b (2026-10-21) : le lecteur du front. Réflexion : échiquier figé, barre de 120 s (depuis le
  départ du serveur, `offsetMs`), « Je réponds » (`calculation-ready`) ; réponse : les deux camps
  jouables tour à tour, une barre de 2 s remise à zéro à chaque demi-coup ; la ligne part au mat,
  à la longueur du mat (`mate` connu ; sinon un mat en 4) ou quand la barre tombe (ce qui a été
  joué, sinon `pass`). Verdict : la feuille de résultat (solution numérotée, ta ligne, l'écart),
  la flèche du bon coup, puis la solution rejouée coup par coup ; une ligne juste passe à la
  suivante d'elle-même, une fausse attend « Suivant ». Les vies d'un mini-boss en cœurs.
- K1c (2026-10-21) : le Métronome (§ 6).
- K1d (2026-10-21) : les expressions de la Gargouille dans son combat ([VISION.md](VISION.md), § 2
  quinquies) ; le chapitre 1 du parcours calcul est complet.

## 6. Le Métronome

Boss du chapitre 1 (`1-boss`, config `{streak: 12, lives: 3}`, à la place de `mate`) : une série de
mats dont la **longueur n'est jamais dite** (`mate: null` dans l'élément). Il faut **12 lignes
justes d'affilée** ; un échec (faux, en retard, rien joué) remet la série à zéro et coûte une vie ;
3 vies. Les mats s'allongent en moyenne avec les lignes justes de tout le combat
(`CalculationRules::streakMates` : en 1 ou 2 au début, jusqu'à 4 à partir de 9 ; un mat d'une autre
longueur quand le catalogue n'a plus rien). La réflexion suit le **tempo** : 40 s, 3 s de moins par
ligne juste du combat, jamais moins de 15 s (`tempo()`, colonne `think_seconds`, migration
`Version20261107090000`) ; la réponse garde ses 2 s par demi-coup. Le front envoie la ligne au mat
ou quand l'horloge tombe (au plus 7 demi-coups, un mat en 4).

L'élément et le résultat portent `streak` (la série en cours), `streakTarget`, `lives`, et
l'élément `right` (les lignes justes, pour le tempo). Gagné à la série (`streak_done`), perdu à la
dernière vie (`lives_out`) ; arrêté avant, le résumé compte autant d'échecs que de vies
(`metrics.failures`, avec `bestStreak` et `won`) pour que le parcours le juge perdu. Aucune XP par
ligne (`metadata.boss`), seulement la récompense du boss (200 cristaux, 100 XP).

**Son visage** (`metronomeMood` de `utils/calculation.js`, les expressions de
`assets/mindMap/boss/metronome/`, dans la bulle du professeur) : neutre au départ ; tristesse à 3
d'affilée, colère à 6, concentration à 9, angoisse à 11 ; doute passé la moitié de la réflexion ;
surprise pour une ligne juste trouvée en moins d'un tiers du temps (série < 3) ; joie quand tu te
trompes, fierté quand le temps t'a manqué. Ses répliques : `calculation.metronome.*`.
