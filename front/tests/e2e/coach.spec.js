import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * The tailored session (docs/COACH.md): the page's five screens, on a simulated API (the diagnosis
 * and the composition are covered by tests/Functional/Coach/*Test.php; Lichess is never reached).
 */

const BUILD = '01890000-0000-7000-8000-0000000000c1'
const PLAN = '01890000-0000-7000-8000-0000000000c2'

const base = {
  id: BUILD,
  username: 'alicelichess',
  maxGames: 200,
  perfs: ['blitz', 'rapid', 'classical'],
  scanned: 0,
  error: null,
  batch: null,
  synthesis: null,
  selection: null,
  trees: [],
  pendingEvaluations: 0,
  freeLines: null,
  repertoireIds: [],
  expiresAt: '2026-10-12T12:00:00+00:00'
}

const feed = [
  { color: 'white', family: 'Italian Game', endgame: 'rook' },
  { color: 'white', family: 'Italian Game', endgame: null },
  { color: 'black', family: 'Sicilian Defense', endgame: 'rook' }
].map((game, i) => ({
  id: `g${i}`,
  result: i === 1 ? 1 : 0,
  opponent: `Opp${i}`,
  rating: 1700 + i,
  perf: 'blitz',
  ...game
}))

const rook = {
  type: 'rook',
  games: 2,
  wins: 0,
  draws: 1,
  losses: 1,
  advantages: 2,
  lost: 2
}

const italian = {
  family: 'Italian Game',
  games: 2,
  wins: 1,
  draws: 0,
  losses: 1
}
const sicilian = {
  family: 'Sicilian Defense',
  games: 1,
  wins: 0,
  draws: 1,
  losses: 0
}

const diagnosis = {
  buildId: BUILD,
  status: 'ready',
  games: 3,
  analysed: 1,
  rating: 1701,
  white: [italian],
  black: [sicilian],
  endgames: { games: 2, advantages: 2, lost: 2, types: [rook] },
  weakest: rook
}

const repertoires = [
  {
    id: '01890000-0000-7000-8000-0000000000c3',
    name: 'Italian Game',
    color: 'white'
  }
]

const composition = {
  planId: PLAN,
  minutes: 29,
  diagnosis,
  repertoire: { status: 'generated', repertoires },
  modules: [
    {
      module: 'repertoire',
      minutes: 10,
      included: true,
      missing: null,
      facts: {
        settings: { repertoireIds: [repertoires[0].id] },
        repertoires,
        opening: italian
      }
    },
    {
      module: 'evaluation',
      minutes: 2,
      included: true,
      missing: null,
      facts: {
        settings: { elo: 1700, side: 'both' },
        endgames: { games: 2, advantages: 2, lost: 2 }
      }
    },
    {
      module: 'woodpecker',
      minutes: 10,
      included: false,
      missing: 'no_puzzles',
      facts: {
        settings: [],
        color: 'white',
        opening: italian,
        puzzleCount: null,
        bothColors: null
      }
    },
    {
      module: 'brilliancy',
      minutes: 2,
      included: true,
      missing: null,
      facts: { settings: [] }
    },
    {
      module: 'endgames',
      minutes: 10,
      included: true,
      missing: null,
      facts: {
        settings: { mode: 'review', themes: ['rooks'], elo: 1700 },
        weakest: rook
      }
    },
    {
      module: 'blindfold',
      minutes: 5,
      included: true,
      missing: null,
      facts: {
        settings: { level: 'medium', length: 2, visibleSeconds: 10 },
        weakest: rook
      }
    }
  ]
}

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
          {
            id: 'lichess-identity',
            provider: 'lichess',
            username: 'alicelichess',
            ratings: { blitz: { rating: 1701, provisional: false } },
            linkedAt: '2026-10-01T10:00:00+00:00',
            removable: true
          }
        ]
      }
    })
  })
}

/**
 * The builds', the coach's and the launch's API, simulated: what was sent.
 *
 * @param {import('@playwright/test').Page} page
 */
async function simulate(page) {
  const sent = { create: null, compose: null, launched: null }
  await page.route(/\/api\/repertoires\/auto-builds.*/, async route => {
    const request = route.request()
    if (request.method() === 'OPTIONS') return route.fallback()
    const url = request.url()
    if (url.endsWith('/auto-builds')) {
      sent.create = request.postDataJSON()
      return route.fulfill({
        status: 201,
        json: { ...base, status: 'fetching', games: 0 }
      })
    }
    if (url.endsWith('/fetch')) {
      return route.fulfill({
        json: { ...base, status: 'ready', games: 3, scanned: 3, batch: feed }
      })
    }
    return route.fulfill({
      json: { ...base, status: 'ready', games: 3, scanned: 3 }
    })
  })
  await page.route(
    /\/api\/coach\/builds\/[^/]+\/(diagnosis|session)$/,
    async route => {
      const request = route.request()
      if (request.method() === 'OPTIONS') return route.fallback()
      if (request.url().endsWith('/diagnosis'))
        return route.fulfill({ json: diagnosis })
      sent.compose = request.postDataJSON()
      return route.fulfill({ json: composition })
    }
  )
  // The launch refuses (a module that cannot be played now): what it was asked is enough here,
  // launching a saved session is covered by session-play.spec.js.
  await page.route(/\/api\/training\/plans\/[^/]+\/launch$/, async route => {
    if (route.request().method() === 'OPTIONS') return route.fallback()
    sent.launched = route.request().url()
    return route.fulfill({ status: 422, json: { detail: 'step_invalid' } })
  })
  return sent
}

test('a visitor is offered to sign in with Lichess', async ({ page }) => {
  await page.goto('/#/session/auto')

  await expect(page.getByTestId('coach-step')).toContainText('1/')
  await expect(page.getByTestId('coach-sign-in')).toHaveAttribute(
    'href',
    /\/api\/auth\/oauth\/lichess\/redirect$/
  )
  await expect(page.getByTestId('coach-start')).toBeDisabled()
})

test('a signed-in user without Lichess is asked to link it', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/session')

  await page.getByTestId('plans-coach').click()

  await expect(page).toHaveURL(/#\/session\/auto$/)
  await expect(page.getByTestId('coach-link')).toBeVisible()
  await expect(page.getByTestId('coach-start')).toBeDisabled()
})

test('from the games to the first module: read, diagnose, compose, look, launch', async ({
  page,
  context
}) => {
  await signIn(context)
  await withLichess(page)
  const sent = await simulate(page)
  await page.goto('/#/session/auto')

  // Account: no repertoire yet, so generating one is offered (checked).
  await expect(page.getByTestId('coach-account')).toContainText('@alicelichess')
  await expect(
    page.getByTestId('coach-repertoire-option').locator('input')
  ).toBeChecked()
  await page.getByTestId('coach-start').click()
  expect(sent.create).toEqual({
    maxGames: 200,
    perfs: ['blitz', 'rapid', 'classical']
  })

  // Analysis: the games one by one, then the weak spot.
  await expect(page.getByTestId('coach-scan-count')).toHaveText('3', {
    timeout: 15_000
  })
  await expect(page.getByTestId('coach-openings-white')).toContainText(
    'Italian Game'
  )
  await expect(page.getByTestId('coach-openings-black')).toContainText(
    'Sicilian Defense'
  )
  await expect(page.getByTestId('coach-endgame-rook')).toContainText(
    'POINT FAIBLE'
  )
  await expect(page.getByTestId('coach-scan-says')).toContainText('« tours »')
  await page.getByTestId('coach-compose').click()
  expect(sent.compose).toEqual({ withRepertoire: true })

  // Composition: the modules forged one by one.
  await expect(page.getByTestId('coach-forged')).toHaveCount(6, {
    timeout: 15_000
  })
  await expect(page.getByTestId('coach-forged').nth(2)).toContainText(
    'Pas cette fois'
  )
  await page.getByTestId('coach-to-session').click()

  // The session: 29 minutes, the absent module says why.
  await expect(page.getByTestId('coach-minutes')).toHaveText('29 min')
  await expect(page.getByTestId('coach-module-0')).toContainText('Répertoire')
  await expect(page.getByTestId('coach-module-0')).toContainText(
    'Italian Game'
  )
  await expect(page.getByTestId('coach-module-2')).toContainText('Absent')
  await expect(page.getByTestId('coach-module-3')).toContainText(
    'Brillant ou Blunder'
  )
  await expect(page.getByTestId('coach-module-4')).toContainText(
    'Finales · Tours'
  )
  await expect(page.getByTestId('coach-says')).toContainText(
    'ton répertoire blanc'
  )

  // A reload of the tab comes back to the same screen.
  await page.reload()
  await expect(page.getByTestId('coach-minutes')).toHaveText('29 min')

  // Module 1: the White repertoire, then 3, 2, 1, go.
  await page.getByTestId('coach-begin').click()
  await expect(page.getByTestId('coach-go-kicker')).toContainText(
    'MODULE 1 SUR 5 · 10 MIN'
  )
  await expect(
    page.getByText('Tes coups préparés, joués de mémoire')
  ).toBeVisible()
  await page.getByTestId('coach-launch').click()
  await expect(page.getByTestId('coach-countdown')).toBeVisible()
  await expect
    .poll(() => sent.launched, { timeout: 10_000 })
    .toContain(`/training/plans/${PLAN}/launch`)
  await expect(page.getByRole('alert')).toContainText(
    'Un module ne peut pas être joué maintenant'
  )
})
