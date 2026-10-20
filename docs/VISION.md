# Vision — Don't Stay Rooky

> Chemins de code et commandes relatifs à `api/` (sauf mention de `front/`).

Le module **Vision** pose des questions sur une position qu'on ne voit plus : elle s'affiche
quelques secondes, disparaît, des coups sont parfois annoncés, puis une question porte sur la
position atteinte ; on répond sur un échiquier vide. Il sert d'abord la carte mentale (chapitre 2 du
parcours visualisation, [MIND_MAP.md](MIND_MAP.md)) et **ne se lance pour l'instant que depuis une
étape du parcours** ; la page libre viendra avec les questions du chapitre 3 (suivre une pièce,
actualiser une position).

Choix validés (2026-10-20) : questions « Où est… ? » et « Qu'y a-t-il en… ? » (mini-boss 2.2) ;
toute pièce en prise est juste (mini-boss 2.3) ; les attaquants d'une case se cliquent tous puis se
valident (boss caché 2.3) ; 4 XP par question juste, 1 par question ratée (comme la Notation).

## 1. Organisation du code

| Couche | Emplacement |
|---|---|
| Règles | `App\Vision\VisionRules`, XP dans `App\Gamification\Xp\XpRules` (`VISION_*`) |
| Échiquier | `App\Chess\Attacks` (attaquants d'une case, commun), `App\Vision\Board\EnPrise` |
| Tirage | `App\Vision\Drawing\{Draw, LocateDrawer, LinePicker}` |
| Entité | `App\Entity\Vision\Question` (table `vision_question`, migration `Version20261104090000`) |
| Enums | `App\Enum\Vision\{Kind, Ask, QuestionStatus}` |
| Module de séance | `App\Vision\Training\VisionModule` (`Module::Vision`, `vision`) |
| API | champ `squares` de la soumission (`ApiResource\Training\SubmitItemInput`, `Training\Module\ItemSubmission`) |
| Front | `components/vision/VisionPlayer.vue` (dans la page de séance), `utils/vision.js`, `i18n/<langue>/vision.json` ; la réserve `components/blindfold/PiecePalette.vue` |

## 2. Les questions

| `kind` | Position montrée | Ensuite | Question (`ask`) | Réponse |
|---|---|---|---|---|
| `locate` | générée comme le niveau 8 de la reconstitution (2 rois, une tour, 5 pions ; `LOCATE_LEVEL`), 12 s | — | `where` : « Où est le roi noir ? » (`target` = lettre FEN) | une case qui porte cette pièce (`squares: [e8]`) |
| | | | `what` : « Qu'y a-t-il en e5 ? » (`target` = la case ; une pièce 2 fois sur 3, sinon une case vide) | `placement: [{square: e5, piece: R}]`, ou `[]` pour « vide » |
| `en_prise` | puzzle de 6 à 12 pièces (`PUZZLE_PIECES`), après le premier coup de l'adversaire, 15 s | les 3 coups suivants de sa solution, annoncés | `en_prise` : « Quelle pièce est en prise ? » | une case d'une pièce en prise (`squares: [f2]`), n'importe laquelle |
| `attackers` | mêmes positions | mêmes coups | `attackers` : « Quelles pièces noires attaquent f2 ? » (`target`, `side`) | toutes les cases des attaquants, rien de plus (`squares: [c5]`) |

Une position de puzzle n'est retenue que si la position atteinte a de quoi être demandée : une pièce
en prise, ou pour `attackers` une pièce adverse (hors roi) attaquée par le camp au trait (sinon une
case qu'il attaque deux fois). Jamais deux fois le même puzzle dans une séance ; d'abord un puzzle
jamais rencontré dans ce module.

## 3. Attaques et pièces en prise

- **Attaquants** (`Attacks::attackers`) : sur l'échiquier seul. Un pion attaque ses deux diagonales
  vers l'avant, un cavalier ses sauts, le roi ses voisines, fou, tour et dame leurs lignes jusqu'à la
  première pièce rencontrée (pas de rayon X). Clouages et trait ignorés.
- **En prise** (`EnPrise::squares`) : une pièce que le camp au trait peut prendre par un coup légal,
  si elle n'est pas défendue (aucun attaquant de son camp) ou si son preneur vaut moins qu'elle
  (P 1, C 3, F 3, T 5, D 9). Jamais le roi ; la prise en passant ne compte pas.

## 4. Déroulé (module `vision`)

- **Démarrage** : seulement par une étape du parcours (`Run::isPathStep()`, sinon 422) ; `config:
  {kind, count?, lives?}` (`count` et `lives` de 1 à 20 et 1 à 10). Aucune étape de session
  (`prepare` refuse). La première question est servie au démarrage : sans puzzle qui convienne,
  409 (`no_puzzle`).
- **Élément** `vision` : `kind`, `ask`, `fen` (la position montrée), `visibleSeconds`,
  `hiddenSeconds` (2 s de voile), `announced` (`[{uci, san}]`), `target`, `side`, `position`,
  `count`, `lives` (restantes), `startedAt`. Le temps d'affichage est celui de l'écran.
- **Soumission** : `squares` (16 au plus) ou `placement` (une pièce au plus, sur la case demandée) ;
  sinon 400. Un seul essai.
- **Verdict** : `status` (`right`, `wrong`), `expected`, `answer`, `finalFen` (la position
  atteinte, pour la correction), `position`, `count`, `lives`, `durationMs`.
- **Fin** : à `count` questions (raison `count_done`) ou quand les erreurs atteignent `lives`
  (`lives_out`). La question à l'écran n'est pas comptée. Récapitulatif : `itemCount`,
  `successCount` ; `metrics` `kind`, `right`, `wrong`, `activeMs`, `averageMs`.
- **Jamais classé.** Chaque question est un `ExerciseCompleted` `vision`
  ([ACTIVITY.md](ACTIVITY.md)) : 4 XP juste, 1 ratée, dans le plafond du jour.

## 5. Écran (`VisionPlayer`)

La position s'affiche avec son compte à rebours (« J'ai mémorisé » l'écourte), un voile de 2 s,
puis les coups annoncés un par un (1,5 s chacun, lettres de la langue de l'interface), puis la
question au-dessus d'un échiquier vide : un clic (`where`, `en_prise`), une pièce de la réserve ou
« Case vide » (`what`, la case demandée en surbrillance), des clics puis « Valider » (`attackers`,
la case demandée en surbrillance, les cases choisies marquées). Le verdict montre la position
atteinte : bonnes cases en vert, mauvais clics en rouge. Juste, la question suivante vient seule ;
ratée, elle attend « Suivant ». En tête : « Question n sur N », les cœurs d'un boss, « Terminer ».
Prof : Noctis.

## 6. Dans la carte mentale

| Étape | `kind` | Questions | Vies |
|---|---|---|---|
| Mini-boss 2.2 (`2-2-boss`) | `locate` | 5 | 3 |
| Mini-boss 2.3 (`2-3-boss`) | `en_prise` | 5 | 3 |
| Boss caché 2.3 (`2-3-hidden`) | `attackers` | 5 | 3 |
