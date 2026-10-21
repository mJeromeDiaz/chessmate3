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
| Tirage | `App\Calculation\Drawing\{LinePicker, LineSpec, Line}` |
| Jugement | `App\Calculation\Judge\{LineJudge, IllegalLineException}` |
| Entité | `App\Entity\Calculation\Attempt` (table `calculation_attempt`, migration `Version20261106090000`) |
| Enums | `App\Enum\Calculation\{AttemptStatus, Ending}` (`pending`, `right`, `wrong`, `late` ; `mate`, `material`) |
| Module de séance | `App\Calculation\Training\CalculationModule` (`Module::Calculation`, `calculation`) |
| Front | `components/calculation/CalculationPlayer.vue` (dans la page de séance), `utils/calculation.js`, `i18n/<langue>/calculation.json`, `trainingApi.ready` |
| API | `POST /training/runs/{id}/ready` (`Training\Module\ReadyModuleInterface`, `TimeboxRunner::ready`) ; la ligne dans `moves` de la soumission, `pass: true` quand le temps est écoulé |

## 2. Les positions

Config de la séance (posée par l'étape du parcours, `Run::isPathStep()` ; toute autre séance est
refusée, 422) : `moves` (les coups du joueur dans la ligne, 1 à 4, ou une liste), `ending`
(`mate` ou `material` ; sans, l'une ou l'autre), `themes` (thèmes Lichess, OU, 3 au plus), `mate`
(chapitre 1 : `moves` et une fin en mat), `capture` (une capture dans la ligne), `count` (les
lignes ; la séance se ferme à son compte) et `lives` (elle se ferme à autant d'échecs).

Un puzzle de ses thèmes (sans thèmes : `mateIn<n>` pour un mat, sinon le thème de longueur Lichess
`oneMove`, `short`, `long`, `veryLong`) dont la solution a exactement `2 × coups − 1` demi-coups
(après le premier coup de l'adversaire, joué d'office), qui finit comme demandé (un mat, ou pas de
mat : un gain à jouer exactement) et, avec `capture`, contient une capture. Autour du classement puzzle du joueur moins 150
(`RATING_GAP` ; 1500 sans classement), élargi pas à pas jusqu'à tout classement (les tranches de
Parcours, `ParcoursRules::ranges`). Jamais deux fois le même puzzle dans une séance tant qu'il y en
a un autre ; d'abord un puzzle que le joueur n'a jamais eu ici. Rien ne compte pour le classement.

L'élément (`type: calculation`) : `fen`, `color` (le camp du joueur), `moves`, `ending` et `mate`
(seulement quand la leçon fixe la fin : un sacrifice peut mater ou gagner du matériel), `capture`,
`thinkSeconds` (120), `plySeconds` (2), `position`, `count`, `lives` (restantes, ou `null`),
`startedAt`, `readyAt`. La solution n'est jamais envoyée avant la réponse.

## 2 bis. La question en fin de ligne

Avec `question: true` (étapes 2.7 et 2.8 ; `count` compte les lignes et les questions : 10 = 5
positions), chaque ligne est suivie d'un élément `kind: question` sur la position qu'atteint **sa
solution** (même quand la ligne jouée était fausse : la solution vient d'être rejouée), tiré par
`QuestionDrawer` une fois sur deux :

- `ask: where` : « Au bout de la ligne, où est la dame noire ? » (`target` = lettre FEN d'une pièce
  que la ligne a déplacée et qui est encore là) ; réponse `squares: [g2]`, juste si une pièce de
  cette lettre s'y trouve ;
- `ask: what` : « Au bout de la ligne, qu'y a-t-il en h4 ? » (`target` = une case touchée par la
  ligne, départ ou arrivée) ; réponse `placement: [{square: h4, piece: P}]`, ou `[]` pour
  « case vide ».

La question se répond sur un échiquier vide, sans limite de temps ; l'élément ne donne pas la
position atteinte (colonnes `ask`, `target`, `fen` = la position atteinte, `expected` = la réponse ;
migration `Version20261109090000`). Le résultat la donne (`fen`), le front la montre avec les bonnes
cases en vert. Même XP qu'une ligne (`metadata.kind: question`).

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
   comprise, jusqu'à sa fin ; un mat donné par le joueur termine la ligne juste, même plus tôt ou
   par un autre coup (comme Lichess) ; une ligne de gain doit être jouée entière, coup pour coup ;
   une ligne arrêtée avant est fausse là où elle s'arrête.

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
- K2a (2026-10-21) : lignes sans mat (`ending: material`), longueurs et thèmes (`LineSpec`),
  migration `Version20261108090000` (`mate` → `moves`, `ending`) ; chapitre 2, étapes 2.1 à 2.6.
- K2b (2026-10-21) : la question en fin de ligne (§ 2 bis), étapes 2.7 et 2.8.
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

**Ses sons** (`front/public/media/son/mindMap/`, si les sons sont activés) : son thème
(`metronome-theme.mp3`, 3 min) sur sa fiche, puis en boucle sous le combat ; le tic-tac
(`metronome-tick.mp3`) en boucle pendant la réflexion, une fois et demie plus vite sur ses 5
dernières secondes ; les verdicts de tous les boss (`ding.mp3`, `off.mp3`, [MIND_MAP.md](MIND_MAP.md), § 2).
