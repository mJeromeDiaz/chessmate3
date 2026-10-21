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
  Un moteur qui ne parle pas UCI (Maia en ONNX, par exemple) offrirait les mêmes `analyse` et
  `terminate`. `setWorkerFactory` remplace le worker (tests unitaires).

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
