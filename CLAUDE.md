# ChessMate3

Chess training app (Duolingo-style). One git repository (monorepo) at the root:

- `api/`: Symfony 7.4 + API Platform 5, Doctrine ORM 3, **MySQL 8.0** (8.0.46 in dev, local
  server, no Docker), Messenger on the Doctrine transport. **No PostgreSQL**: never use a
  PostgreSQL-only feature (arrays, GIN, `RETURNING`, partial indexes, `ON CONFLICT`...); MySQL
  equivalents are generated columns + unique indexes, JSON, `ON DUPLICATE KEY UPDATE`. **No Redis**:
  rate limiters use the filesystem cache pool (local to one server).
- `front/`: Vue 3 + Quasar 2 + Pinia, **JavaScript** (Composition API, `<script setup>`, JSDoc on
  non-trivial functions and stores), file-based routing under `src/pages/`, hash router mode.
- `docs/` (root): general documentation — `AUTH.md`, `SECURITY.md`, `PUZZLES.md`,
  `PUZZLE_IMPORT.md`, `ACTIVITY.md` (timezone, domain events, activity log), `WOODPECKER.md`
  (classic and light modes), `TRAINING.md` (timed runs, module contract), `REPERTOIRE.md`
  (opening repertoires: normalized FEN, graph, one prepared move per position, trash, segments,
  editor, PGN and OpenBook import/export, FSRS cards, timed test, statistics), `DASHBOARD.md`
  (home dashboard: endpoints, local days, Lichess rating history, showcase values), `NOTIFICATIONS.md`
  (Web Push, VAPID keys, session reminders and their cron). Code paths quoted in them (`src/...`, `config/...`,
  `bin/console`) are relative to `api/` unless they name `front/`.

Each app keeps its own `.gitignore` (`api/.gitignore`, `front/.gitignore`); the root one only covers
editor and OS files.

## Working rules

- Propose a detailed plan and wait for validation before writing code; ask about real tradeoffs
  (performance, rating integrity, security, UX) instead of deciding alone.
- Check current package versions and official docs before installing or configuring a library.
- Work in small testable steps: tests green, PHPStan level max, oxlint/oxfmt clean at each step.
- **Never commit**: the user commits.
- Never read the Lichess puzzle CSV (hundreds of MB); its format is in `docs/PUZZLE_IMPORT.md`.

## Code organisation: by domain, short class names

Each business domain (Puzzle, Activity, Woodpecker, Training, Repertoire, Dashboard, Notification today) gets a sub-namespace in every
layer, and classes inside it keep short names: `App\Entity\Puzzle\Theme`, never `PuzzleTheme`.

| Layer | Location |
|---|---|
| Entities | `src/Entity/<Domain>/` → `App\Entity\<Domain>\X` (create with `bin/console make:entity '<Domain>\X'`) |
| Repositories | `src/Repository/<Domain>/` |
| Enums | `src/Enum/<Domain>/` |
| Business logic | `src/<Domain>/<Area>/` → e.g. `App\Puzzle\Rating\Glicko2`, `App\Puzzle\Selection\PuzzleSelector` (the namespace names the domain, as `App\Security\` does for auth) |
| API Platform state | `src/State/<Domain>/` (providers, processors), `src/ApiResource/<Domain>/` for DTO resources |
| Commands | `src/Command/<Domain>/`, named `app:<domain>:<action>` |
| Fixtures | `src/DataFixtures/<Domain>/` |
| Tests | `tests/{Unit,Functional}/<Domain>/` |
| Front | `src/components/<domain>/`, `src/composables/<domain>/`, `src/stores/<domain>.js`, `src/pages/index/<domain>/` (pages live under the `index` layout) |
| Front, cross-domain | `src/components/chess/` (the chessboard, reused by every training mode) |
| Server, cross-domain | `src/Chess/` → `App\Chess\Rules` (legal moves, UCI, lenient SAN, normalized FEN), `App\Chess\Position\{FenNormalizer, PositionKey}`, `App\Chess\Pgn\{Parser, Writer}`; front twin of the normalizer: `src/utils/chess/normalizeFen.js` |

Directories and namespaces are PascalCase and singular (PSR-4). Transverse entities stay at the root:
`App\Entity\User` and the Phase 1 auth entities are not moved.

Naming in the database and the API, to avoid collisions between domains that all have a `Theme` or
an `Attempt`:

- Tables are explicit and prefixed by the domain: `#[ORM\Table(name: 'puzzle_theme')]`. Same for
  indexes and constraints: `idx_puzzle_...`, `uniq_puzzle_...`, `fk_puzzle_...`.
- API Platform resources have an explicit, unique `shortName` (`PuzzleTheme`, `PuzzleAttempt`) and
  URIs grouped under the domain (`/puzzles/themes`, `/puzzles/attempts`). An item route such as
  `/puzzles/{id}` gets a `requirements` regex so it never captures a sibling collection, and a
  functional test covers every sibling route.

## Commands

API (`cd api`; prefix with `php -d xdebug.mode=off` if Xdebug reports a false infinite loop):

```bash
vendor/bin/phpunit                                   # all tests (unit + functional, test DB)
vendor/bin/phpstan analyse --memory-limit=1G         # level max
bin/console doctrine:migrations:migrate [--env=test]
bin/console doctrine:fixtures:load                   # PURGES the DB: themes, sample puzzles, demo user + Woodpecker data + 12 weeks of activity, openings + demo repertoires
bin/console app:puzzle:sync-themes                   # load/update the Lichess puzzle themes
bin/console app:puzzle:rebuild-selection             # after a puzzle import or a quality-threshold change
bin/console app:activity:backfill                    # log past exercises in the activity log (idempotent)
bin/console app:repertoire:sync-openings             # load/update the opening names (data/chess-openings, ~9 s; fixtures do it too)
bin/console cache:pool:prune                         # daily cron: expired Lichess explorer/cloud-eval answers
bin/console app:training:send-reminders              # cron every minute: reminders of saved sessions (docs/NOTIFICATIONS.md)
bin/console app:notification:vapid-keys              # once per environment: Web Push key pair (private key = secret)
bin/console messenger:consume activity async         # worker: domain events (outbox), emails, big repertoire imports
```

Front (`cd front`):

```bash
npm run dev
npm test                  # Vitest
npm run lint:check        # oxfmt + oxlint
npm run build
npm run e2e:prepare       # once: ChessMateGo_e2e database, migrations, fixtures (APP_ENV=e2e)
npm run test:e2e          # Playwright (API with APP_ENV=e2e on :8100, quasar dev on :9100)
```

End-to-end environment: `APP_ENV=e2e` (`api/.env.e2e`) uses its own database (`dbname_suffix:
_e2e`) and registers `app:e2e:seed-user` (a signed-in user without the email 2FA), which exists in
no other environment. PHP's built-in server needs `-d variables_order=EGPCS` to see `APP_ENV`.

## Gotchas

- A raw SQL write (bulk UPDATE, `SelectionRebuilder`, the repertoire's derived data written by
  `Repertoire\Graph\{IndexWriter, SegmentReconciler}`) bypasses Doctrine's identity map: clear the
  entity manager (and reload) before reading those entities in the same process.
- `EntityManager::wrapInTransaction()` closes the entity manager on any exception, a business refusal
  included: services that refuse inside a transaction either return the refusal and throw it outside
  (`TimeboxRunner`) or use `App\Repertoire\Transaction` and validate before their first write.
- Services only used by one other service are inlined, and unused ones removed, from the test
  container: tests that fetch them directly need them public under `when@test` (`config/services.yaml`).
- API Platform drops `null` fields by default: resources set `skip_null_values: false`.
- API Platform's resource metadata is cached in `test` and `e2e`: after adding a property or an
  operation, `bin/console cache:clear --env=test` (and `--env=e2e`), or the field is missing.
- Tests never reach a push service (`push.client` is a `MockHttpClient` under `when@test`); a test
  that replaces it calls `$client->disableReboot()` first (a kernel reboot brings the original back).
- MySQL `SET @a = 1, @b = @a + 1` evaluates `@b` with the old `@a`: use separate statements.
- MySQL collations ignore case (and accents) by default: a column holding a FEN, moves, a Lichess id
  or any case-sensitive identifier or digest uses `ascii_bin` (or `utf8mb4_bin`), e.g.
  `options: ['charset' => 'ascii', 'collation' => 'ascii_bin']`; `CaseSensitiveColumnsTest` lists them.
- Time: every instant is UTC (forced in `Kernel::boot()` and on each MySQL connection); a local day
  (activity date, Woodpecker deadline) is computed explicitly with `User::getDateTimeZone()`.
- Domain events go through `App\Activity\EventPublisher`, **inside** the transaction of the change
  (Messenger `activity` transport = transactional outbox). Delivery is at-least-once: every handler
  is idempotent. Events carry scalars only; add fields, never rename or remove them.
- Cross-domain hooks are tagged interfaces owned by the domain being extended
  (`Puzzle\Selection\ExclusionProviderInterface`, `Puzzle\Attempt\ReplayAuthorizerInterface`): the
  Puzzle domain never depends on Woodpecker.
- Timed runs: time belongs to the server (`Training\Run\TimeboxRunner`), no grace (2 s network
  tolerance only), one active run per user, lazy closing (no cron). A module implements
  `Training\Module\TimeboxedModuleInterface` and locks its subject after the run, never before.
- Raw DBAL parameters used as numbers in SQL expressions (`LEAST`, `GREATEST`, arithmetic) must be
  typed `ParameterType::INTEGER`: untyped ones are bound as strings and `LEAST()` then compares as
  strings.
- Lichess rate-limits the anonymous `puzzle/next` and `puzzle/batch` endpoints hard (429 for many
  minutes); never script them in a loop. Calls to Lichess (repertoire explorer, cloud eval, studies;
  dashboard rating history) all go through `Repertoire\Lichess\LichessGateway` (one request at a time, pause after a 429);
  tests never reach Lichess (`MockHttpClient` in PHPUnit, `page.route` in Playwright).
- API Platform hides the detail of any 5xx outside debug: a reason the SPA needs travels in a
  header exposed by CORS (`X-Lichess-Unavailable` on the Lichess proxy's 503).
