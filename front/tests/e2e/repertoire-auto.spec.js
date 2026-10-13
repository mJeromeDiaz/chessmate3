import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Repertoire from my games (docs/REPERTOIRE.md): the page's steps, on a simulated API (the
 * builds' endpoints are covered by tests/Functional/Repertoire/AutoBuild*Test.php; Lichess is never
 * reached).
 */

const ID = '01890000-0000-7000-8000-000000000001'
const ITALIAN = 'white:Italian Game'

/** [path, san, fen, user, games, wins, draws, losses] */
const NODES = [
  ['e2e4', 'e4', 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -', true, 6, 3, 1, 2],
  ['e2e4 e7e5', 'e5', 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -', false, 6, 3, 1, 2],
  ['e2e4 e7e5 g1f3', 'Nf3', 'rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq -', true, 6, 3, 1, 2],
  ['e2e4 e7e5 g1f3 b8c6', 'Nc6', 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq -', false, 6, 3, 1, 2],
  ['e2e4 e7e5 g1f3 b8c6 f1c4', 'Bc4', 'r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R b KQkq -', true, 6, 3, 1, 2],
  ['e2e4 e7e5 g1f3 b8c6 f1c4 f8c5', 'Bc5', 'r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq -', false, 3, 3, 0, 0],
  ['e2e4 e7e5 g1f3 b8c6 f1c4 f8c5 c2c3', 'c3', 'r1bqk1nr/pppp1ppp/2n5/2b1p3/2B1P3/2P2N2/PP1P1PPP/RNBQK2R b KQkq -', true, 3, 3, 0, 0],
  ['e2e4 e7e5 g1f3 b8c6 f1c4 g8f6', 'Nf6', 'r1bqkb1r/pppp1ppp/2n2n2/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq -', false, 3, 0, 1, 2],
  ['e2e4 e7e5 g1f3 b8c6 f1c4 g8f6 f3g5', 'Ng5', 'r1bqkb1r/pppp1ppp/2n2n2/4p1N1/2B1P3/8/PPPP1PPP/RNBQK2R b KQkq -', true, 3, 0, 1, 2]
].map(([path, san, fen, user, games, wins, draws, losses]) => {
  const parts = path.split(' ')
  return {
    path,
    parent: parts.slice(0, -1).join(' '),
    depth: parts.length,
    uci: parts[parts.length - 1],
    san,
    fen,
    user,
    games,
    wins,
    draws,
    losses,
    analysed: 0,
    errored: 0,
    think: null,
    moveAnalysed: 0,
    moveErrors: 0,
    suggested: false,
    suggestion: null,
    alternatives: []
  }
})

const line = (path, number, name, tag, counts) => ({
  path,
  cut: path.split(' ').slice(0, 6).join(' '),
  name,
  nodes: NODES.filter(n => path === n.path || path.startsWith(`${n.path} `)).map(n => n.path),
  ...counts,
  analysed: 0,
  errored: 0,
  fen: NODES.find(n => n.path === path).fen,
  number,
  tag,
  evaluated: true,
  evalCp: 40,
  evalMate: null,
  locked: false
})

const synthesis = {
  games: 9,
  analysed: 0,
  wins: 3,
  draws: 4,
  losses: 2,
  families: [
    { key: ITALIAN, family: 'Italian Game', color: 'white', games: 6, wins: 3, draws: 1, losses: 2, analysed: 0 },
    { key: "black:Queen's Gambit Declined", family: "Queen's Gambit Declined", color: 'black', games: 3, wins: 0, draws: 3, losses: 0, analysed: 0 }
  ]
}

const base = {
  id: ID,
  username: 'alicelichess',
  maxGames: 50,
  perfs: ['blitz', 'rapid'],
  scanned: 0,
  error: null,
  batch: null,
  synthesis: null,
  selection: null,
  trees: [],
  pendingEvaluations: 0,
  freeLines: null,
  repertoireIds: [],
  expiresAt: '2026-10-10T12:00:00+00:00'
}

const trees = [
  {
    key: ITALIAN,
    family: 'Italian Game',
    color: 'white',
    games: 6,
    wins: 3,
    draws: 1,
    losses: 2,
    trunk: 5,
    nodes: NODES,
    lines: [
      line('e2e4 e7e5 g1f3 b8c6 f1c4 f8c5 c2c3', 1, 'Giuoco Piano', 'weapon', { games: 3, wins: 3, draws: 0, losses: 0 }),
      line('e2e4 e7e5 g1f3 b8c6 f1c4 g8f6 f3g5', 2, 'Two Knights Defense', 'risk', { games: 3, wins: 0, draws: 1, losses: 2 })
    ]
  }
]

const feed = ['g1', 'g2', 'g3'].map((id, i) => ({
  id,
  color: 'white',
  result: i === 2 ? -1 : 1,
  opponent: `Opp${i}`,
  rating: 1800 + i,
  family: 'Italian Game',
  perf: 'blitz'
}))

/**
 * The profile with a linked Lichess account, on top of the real one.
 *
 * @param {import('@playwright/test').Page} page
 */
async function withLichess(page) {
  await page.route(/\/api\/profile(\/(timezone|theme))?$/, async route => {
    if (route.request().method() === 'OPTIONS') return route.fallback()
    const response = await route.fetch()
    const profile = await response.json()
    await route.fulfill({
      response,
      json: {
        ...profile,
        identities: [
          ...(profile.identities ?? []),
          { id: 'lichess-identity', provider: 'lichess', username: 'alicelichess', ratings: { blitz: { rating: 1842, provisional: false } }, linkedAt: '2026-10-01T10:00:00+00:00', removable: true }
        ]
      }
    })
  })
}

/**
 * The builds' API, simulated: what each call answers, and what was sent.
 *
 * @param {import('@playwright/test').Page} page
 */
async function simulateBuilds(page) {
  const sent = { create: null, selection: [] }
  await page.route(/\/api\/repertoires\/auto-builds.*/, async route => {
    const request = route.request()
    if (request.method() === 'OPTIONS') return route.fallback()
    const url = request.url()
    if (url.endsWith('/auto-builds')) {
      sent.create = request.postDataJSON()
      return route.fulfill({ status: 201, json: { ...base, status: 'fetching', games: 0 } })
    }
    if (url.endsWith('/fetch')) {
      return route.fulfill({ json: { ...base, status: 'ready', games: 3, scanned: 3, batch: feed, synthesis } })
    }
    if (url.endsWith('/selection')) {
      const selection = request.postDataJSON()
      sent.selection.push(selection)
      return route.fulfill({ json: { ...base, status: 'ready', games: 9, synthesis, selection, trees, pendingEvaluations: 0 } })
    }
    if (url.endsWith('/apply')) {
      return route.fulfill({ json: { ...base, status: 'applied', games: 9, synthesis, selection: { families: [ITALIAN], choices: {}, removed: {} }, trees, repertoireIds: ['01890000-0000-7000-8000-0000000000aa'] } })
    }
    return route.fulfill({ status: 404, json: {} })
  })
  return sent
}

test('without a linked Lichess account, the page asks to link one', async ({ page, context }) => {
  await signIn(context)
  await page.goto('/#/repertoire')

  await page.getByTestId('repertoire-auto-card').click()

  await expect(page).toHaveURL(/#\/repertoire\/auto$/)
  await expect(page.getByTestId('auto-link')).toBeVisible()
  await expect(page.getByTestId('auto-start')).toBeDisabled()
})

test('from the games to the repertoire: read, choose, build, look at a line, validate', async ({ page, context }) => {
  await signIn(context)
  await withLichess(page)
  const sent = await simulateBuilds(page)
  await page.goto('/#/repertoire/auto')

  await expect(page.getByTestId('auto-account')).toContainText('@alicelichess')
  await page.getByTestId('auto-count-50').click()
  await page.getByTestId('auto-start').click()
  expect(sent.create).toEqual({ maxGames: 50, perfs: ['blitz', 'rapid'] })

  // The games scroll by, then the synthesis.
  await expect(page.getByTestId('auto-feed-item')).toHaveCount(3)
  await expect(page.getByTestId('auto-scan-count')).toContainText('3 sur 50')
  await page.getByTestId('auto-to-select').click()

  await expect(page.getByTestId('auto-family')).toHaveCount(2)
  await expect(page.getByTestId('auto-step')).toContainText('Sélection')
  // Both checked by default (the main families): keep the Italian only.
  await page.getByTestId('auto-family').nth(1).click()
  await page.getByTestId('auto-generate').click()

  await expect(page.getByTestId('auto-gen-title')).toBeVisible()
  await expect(page.getByTestId('auto-line')).toHaveCount(2, { timeout: 15_000 })
  expect(sent.selection[0]).toEqual({ families: [ITALIAN], choices: {}, removed: {} })
  // A line is shown whole: what the opening's lines share, then its own moves.
  await expect(page.getByTestId('auto-line-moves').first()).toContainText('1.e4 e5 2.Nf3 Nc6 3.Bc4')
  await expect(page.getByTestId('auto-line-moves').first()).toContainText('3…Bc5 4.c3')

  await page.getByTestId('auto-line').nth(1).click()
  const sheet = page.getByTestId('auto-sheet')
  await expect(sheet).toBeVisible()
  await expect(sheet.getByTestId('chess-board')).toBeVisible()
  await expect(sheet.getByTestId('auto-sheet-current')).toHaveText('4.Ng5')
  await expect(sheet.getByTestId('auto-sheet-eval')).toContainText('+0,4 pour toi')

  // Removing the line: undoable.
  await sheet.getByTestId('auto-remove').click()
  expect(sent.selection[1].removed).toEqual({ [ITALIAN]: ['e2e4 e7e5 g1f3 b8c6 f1c4 g8f6'] })
  await expect(page.getByTestId('auto-toast')).toContainText('Two Knights Defense')
  await page.getByTestId('auto-toast').getByRole('button', { name: 'Annuler' }).click()
  await expect.poll(() => sent.selection.length).toBe(3)
  expect(sent.selection[2].removed).toEqual({})

  await page.getByTestId('auto-validate').click()
  await expect(page.getByTestId('auto-done-text')).toContainText('2 lignes')
  await expect(page.getByTestId('auto-train')).toBeVisible()
})
