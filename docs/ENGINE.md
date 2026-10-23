# Moteur d'échecs dans le navigateur

Stockfish tourne **chez le joueur**, dans un Web Worker : l'hébergement mutualisé OVH ne peut pas
faire tourner de moteur côté serveur (docs/DEPLOY_OVH.md). Il sert le chapitre 3 du parcours calcul
([CALCULATION.md](CALCULATION.md)) et servira le module des finales et les parties contre des bots.
Rien n'y est propre à un module : un module demande une analyse, il en tire son verdict.

## 1. Le moteur

- **Stockfish.js 19.0.1** (nmrugg, compilé pour l'analyse de chess.com),
  <https://github.com/nmrugg/stockfish.js>, version **lite, un seul fil** :
  `front/public/engine/stockfish-19-lite-single.js` et `.wasm` (1,7 Mo en tout), **hors git**
  (`front/.gitignore`) : `npm run engine:fetch` (`front/scripts/fetch-engine.mjs`) les télécharge
  depuis la page des versions GitHub, vérifie leur SHA-256 et écrit `engine/README.txt` (licence et
  sources). À lancer une fois après un clone (dev, e2e) ; `deploy/ovh/deploy.sh` le lance avant
  chaque build. Pas de paquet npm : `stockfish` pèse 205 Mo, ses cinq versions comprises. Pour
  changer de version : l'URL et les empreintes du script, et `STOCKFISH_URL`.
- **Un seul fil** : pas de `SharedArrayBuffer`, donc pas d'en-têtes COOP/COEP (qui bloqueraient
  les images et fenêtres d'autres origines). Le réseau « lite » est plus faible que le complet,
  mais bien plus fort que tout humain.
- **Licence** : GPLv3 (Stockfish et Stockfish.js). Le moteur est un programme à part, chargé dans
  son worker et piloté par messages ; ses sources sont au lien ci-dessus.
- **Servir le moteur** : `front/public/.htaccess` donne aux `.wasm` le type `application/wasm`
  (exigé, avec `X-Content-Type-Options: nosniff`) ; la CSP du SPA permet `'wasm-unsafe-eval'`
  (docs/SECURITY.md, § 2.10).

## 2. Le code (front)

| Fichier | Rôle |
|---|---|
| `src/utils/engine/uci.js` | le protocole UCI, pur : commandes `position`, `go`, `setoption` ; lecture des lignes `info` (bornes `lowerbound`/`upperbound` ignorées) et `bestmove` ; scores (`scoreValue`, `flipScore`, `isNearBest`) |
| `src/utils/engine/UciEngine.js` | un moteur UCI dans un worker : démarrage (`uci` → `uciok`, 30 s au plus), **une recherche à la fois** (les autres attendent leur tour), abandon par `AbortSignal` (`stop`, le `bestmove` qui suit est lu avant la recherche suivante), panne du worker |
| `src/composables/engine/useEngine.js` | le moteur **partagé** de l'application : chargé à la première recherche demandée, arrêté quand le dernier composant qui s'en sert disparaît ; les recherches d'un composant sont abandonnées à son démontage |
| `src/components/chess/EngineStatus.vue` | une ligne d'état : chargement, réflexion, échec du démarrage (rien au repos) |

```js
const engine = useEngine()
// Les 3 meilleures lignes, 1,5 s de réflexion, dans la position après e2e4 e7e5.
const { lines, bestMove } = await engine.analyse(fen, {
  moves: ['e2e4', 'e7e5'],
  multiPv: 3,
  movetime: 1500
})
// lines[0] = { move: 'g1f3', score: { cp: 40 }, pv: ['g1f3', 'b8c6', …], depth: 14 }
```

- **Scores** : toujours du point de vue **du camp au trait** dans la position analysée, comme UCI les
  donne (`{cp: 35}` ou `{mate: 3}`, `{mate: -2}` : mat subi en 2). La valeur d'un coup pour le camp
  qui le joue est l'opposé du score de la position atteinte (`flipScore`).
- **« Un des meilleurs coups »** (`isNearBest(lines, move, toleranceCp)`) : sa ligne est à
  `toleranceCp` au plus de la première, ou, quand la première mate, il mate aussi vite. Un coup que
  l'analyse n'a pas regardé (MultiPV trop petit) n'en est pas un : on analyse avec assez de lignes,
  ou sur ce coup seul (`searchMoves`). Le seuil appartient au module (0,5 pion pour le calcul).
- **Options** : chaque recherche part de `DEFAULT_OPTIONS` (`MultiPV 1`, pleine force) complété de
  ses `options` (`{UCI_LimitStrength: true, UCI_Elo: 1500}` pour un bot) : le moteur étant partagé,
  une recherche n'hérite jamais des réglages de la précédente. Seules les options changées sont
  envoyées.
- **Erreurs** : une recherche abandonnée (signal, `stop()`, démontage) est rejetée avec une
  `AbortError` (`isEngineAbort`), sans message pour le joueur ; un moteur qui ne démarre pas ou
  plante, avec `EngineFailureError` (statut `failed`) : la recherche suivante en relance un.
- **Statut** (`status`) : `idle` (pas chargé), `loading`, `ready`, `thinking` (une recherche en
  cours, tous composants confondus), `failed`.
- **Autre moteur** : `UciEngine` prend n'importe quel worker qui parle UCI par `postMessage`.
  Maia, qui ne parle pas UCI, a sa propre classe (§ 5). `setWorkerFactory` remplace le worker
  (tests unitaires).

## 3. Confiance

Le moteur juge **chez le joueur** : le serveur ne peut pas vérifier son verdict, seulement la
légalité des coups et le temps. Choix assumé (2026-10-21) : rien n'empêche d'ouvrir Lichess à côté,
et les modules qui s'en servent ne touchent pas au classement (XP et cristaux seulement).

## 4. Tests

- Vitest : `tests/unit/engine.test.js` (lignes UCI, scores, file des recherches, options remises
  par défaut, abandon, panne, moteur partagé), avec un faux worker qui parle UCI.
- Vitest : `tests/unit/lineJudge.test.js` (le jugement des lignes du calcul, un faux worker qui
  score chaque position).
- Playwright : le vrai moteur est chargé par les tests des modules qui s'en servent :
  `tests/e2e/mind-map-calculation-engine.spec.js` (chapitre 3 du parcours calcul, puzzles ajoutés
  au catalogue par `app:e2e:seed-user --engine-puzzles`). `npm run engine:fetch` doit avoir été
  lancé.

## 5. Maia

**Maia-3** (CSSLab, université de Toronto) joue **comme un humain** d'un Elo donné, pas le meilleur
coup : c'est l'adversaire du module des finales ([ENDGAMES.md](ENDGAMES.md)). Il tourne lui aussi
chez le joueur, dans son propre Web Worker.

- **Le modèle** : Maia-3 23M en float16, export ONNX de <https://huggingface.co/bqrio/maia3-onnx>
  (commit `923df9a`), `front/public/maia/maia3-23m.fp16.onnx` (46 Mo), **hors git** :
  `npm run maia:fetch` (`front/scripts/fetch-maia.mjs`) le télécharge, vérifie son SHA-256 et
  écrit `maia/README.txt` (licence et sources). À lancer une fois après un clone ;
  `deploy/ovh/deploy.sh` le lance avant chaque build. `.htaccess` le met en cache un an (un autre
  modèle aura un autre nom).
- **Plus tard** : Maia-3 5M (10,8 Mo, mêmes entrées et sorties) pour les données mobiles et les
  appareils faibles. Le choix reste à décider (2026-10-23 : le 23M pour tous en attendant).
- **Le moteur d'exécution** : `onnxruntime-web` 1.30.0, point d'entrée `onnxruntime-web/wasm`
  (WebAssembly seul, pas de WebGPU), un seul fil (pas de COOP/COEP, comme Stockfish). Son `.wasm`
  (14 Mo) vient de `node_modules`, importé avec `?url` : Vite le sert dans `assets/` avec une
  empreinte. `quasar.config.js` met les workers en modules ES et sort `onnxruntime-web` du
  pré-bundling. Optimisation du graphe `basic` : les niveaux `extended`/`all` d'ORT cassent sur
  les conversions float16 de ce graphe (README de l'export). La CSP en place suffit
  (`'wasm-unsafe-eval'`, worker et modèle sur la même origine).
- **Licence** : AGPLv3 (le modèle et le code de Maia-3), acceptée (2026-10-22). Le modèle est un
  fichier à part, chargé dans son worker ; ses sources sont dans `maia/README.txt`.

### Entrées et sorties

Telles que le code de Maia-3 (CSSLab/maia3) les construit, dans `src/utils/maia/encoding.js` :

- **Le plateau** est vu par le camp au trait : quand les noirs ont le trait, il est retourné
  (rangées inversées, couleurs échangées). `tokens` : 64 cases (a1 = 0, b1 = 1 … h8 = 63) × 12
  canaux, pion, cavalier, fou, tour, dame, roi du camp au trait (0–5) puis de l'adversaire (6–11).
  Ni roques, ni prise en passant, ni historique : le modèle recopie la position sur ses 8 positions
  d'historique.
- **`elo_self`, `elo_oppo`** : l'Elo du camp au trait (Maia) et de l'adversaire (le même par
  défaut), gardés entre 600 et 2600.
- **`logits_move`** : 4352 coups, chaque couple départ × arrivée (4096, départ d'abord, a1a1
  compris) puis les 256 promotions de la 7e à la 8e rangée (colonne de départ × colonne d'arrivée ×
  q, r, b, n), dans le repère retourné quand les noirs ont le trait (rangée r → 9 − r). Les coups
  illégaux sont écartés, puis softmax (`legalChances`).
- **`logits_value`** : perte, nulle, gain pour le camp au trait (`outlook`).
- **Le coup joué** (`drawMove`) : tiré au sort selon les probabilités, parmi les coups d'au moins
  5 % (le plus probable si aucun) : les défenses varient comme celles d'un humain de ce niveau,
  sans coup absurde. Avec `among`, le tirage se fait parmi ces coups seulement (les ex aequo de la
  meilleure défense, au niveau « Parfait »).

### Le code

| Fichier | Rôle |
|---|---|
| `src/utils/maia/encoding.js` | l'encodage, pur : `encodeBoard`, `MOVES`, `moveIndex`, `mirrorMove`, `legalChances`, `outlook`, `drawMove` |
| `src/utils/maia/maia.worker.js` | le worker : télécharge le modèle (progression), crée la session, répond à chaque position par ses logits |
| `src/utils/maia/MaiaEngine.js` | parle au worker : `ready()`, `predict(fen, {elo, opponentElo, signal})`, `terminate()` ; 20 s au plus par prédiction ; mêmes erreurs que Stockfish (`EngineAbortError`, `EngineFailureError`) |
| `src/composables/maia/useMaia.js` | Maia **partagé**, comme `useEngine` : `preload()` (le module des finales le lance à son ouverture, avant le premier coup du joueur), `predict`, `chooseMove(fen, {elo, among})`, `stop()`, `status`, `progress` (0 à 1, null si la taille est inconnue) ; `setMaiaWorkerFactory` (tests) |

```js
const maia = useMaia()
maia.preload() // à l'ouverture du module : 46 Mo la première fois, le cache du navigateur ensuite
const move = await maia.chooseMove(fen, { elo: 1200 }) // 'e7e5', null sans coup légal
```

- **Maia indisponible** (le modèle ne se charge pas, le worker plante) : `status` passe à
  `failed`, `preload()` et les prédictions rejettent `EngineFailureError`, l'appel suivant relance
  un worker. Le module des finales joue alors la meilleure défense de la tablebase et le dit.

### Tests

- Vitest : `tests/unit/maia.test.js` (vocabulaire, plateau retourné, coups des noirs et
  promotions, probabilités, tirage, `MaiaEngine` et `useMaia` avec un faux worker).
- Vitest, le vrai modèle sous Node : `npm run test:maia` (`vitest.maia.config.js`,
  `tests/maia/model.test.js`, sauté sans le modèle) : il se charge avec ces réglages, ouvre
  e4 ou d4, répond e5 ou c5 avec les noirs, voit gagné K+D contre K, promeut pour les deux camps.
  Il affiche le temps de chaque prédiction.
