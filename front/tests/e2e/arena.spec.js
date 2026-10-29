import { expect, test } from '@playwright/test'
import { ENGINE_TIMEOUT, noMaia } from './endgames.js'
import { playMove, signIn } from './helpers.js'

/**
 * The arena (docs/ARENA.md) against the real API: the bosses by level, the Zombie arriving for a new
 * player, the Requin bought and arriving at once; a game against the Zombie, Maia never downloaded
 * (46 MB) so that Stockfish plays for it at its strength, resigned, its result and its review with
 * the PGN; cancelling and restarting; Lizy arriving with every endgame lesson mastered, Albert
 * locked with his feat, Ray still a mystery (the exceptional badges: PHPUnit and Vitest; a new
 * player's dashboard has no trophies card). The rules (who arrives when, prices, clocks, ends, rewards)
 * are covered by PHPUnit (tests/Functional/Arena/ArenaTest.php) and Vitest (tests/unit/arena*.js).
 */

/** The lessons of the endgames path (api src/MindMap/Path/Endgames.php): Lizy's feat. */
const ENDGAME_LESSONS = [
  'kqk',
  'krk',
  'kbbk',
  'kbnk',
  'square-promote',
  'square-stop',
  'opposition',
  'key-squares',
  'rook-pawn',
  'pawn-defence',
  'triangulation',
  'pawn-race',
  'queen-vs-pawn',
  'queen-vs-bishop-pawn',
  'queen-vs-rook',
  'king-cut-off',
  'lucena',
  'philidor',
  'rook-vs-pawn',
  'vancura',
  'wrong-rook-pawn',
  'bishop-vs-pawn',
  'opposite-bishops',
  'bishop-pawn',
  'knight-vs-pawn',
  'knight-vs-rook-pawn',
  'knight-pawn'
]

const isOverview = r =>
  r.url().endsWith('/api/arena') && r.request().method() === 'GET'
const isPurchase = r => /\/api\/arena\/bosses\/[^/]+\/purchase$/.test(r.url())
const isStart = r =>
  r.url().endsWith('/api/arena/games') && r.request().method() === 'POST'
/** @param {number} ply */
const isMove = ply => r =>
  /\/api\/arena\/games\/[^/]+\/moves$/.test(r.url()) &&
  r.request().postDataJSON()?.ply === ply

/**
 * Confirms the dialog opened by an action of the game (its last button).
 *
 * @param {import('@playwright/test').Page} page
 */
async function confirm(page) {
  await page.locator('.q-dialog').getByRole('button').last().click()
}

/**
 * The boss's sheet, from the arena page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} boss
 */
async function openSheet(page, boss) {
  const read = page.waitForResponse(isOverview)
  await page.goto(`/#/arene?boss=${boss}`)
  await read
  const sheet = page.getByTestId('arena-sheet')
  await expect(sheet).toHaveAttribute('data-boss', boss)
  return sheet
}

/**
 * A game against the Zombie, White, in 5+3, from its sheet.
 *
 * @param {import('@playwright/test').Page} page
 */
async function startZombie(page) {
  const sheet = await openSheet(page, 'zombie')
  await sheet.getByTestId('arena-color-white').click()
  await sheet.getByTestId('arena-cadence-5+3').click()
  const started = page.waitForResponse(isStart)
  await sheet.getByTestId('arena-play').click()
  expect((await started).status()).toBe(201)
  await expect(page.getByTestId('arena-game')).toHaveAttribute(
    'data-status',
    'active'
  )
  return (await (await started).json()).id
}

test('the bosses by level, the Zombie arriving, the Requin bought', async ({
  page,
  context
}) => {
  await signIn(context, { crowns: 200, arrivals: true })

  // A new player: the Zombie, free, arrives at the first visit.
  await page.goto('/#/')
  const arrival = page.getByTestId('arena-arrival')
  await expect(arrival).toHaveAttribute('data-boss', 'zombie')
  const announced = page.waitForResponse(r =>
    r.url().endsWith('/api/arena/unlocks/announce')
  )
  await arrival.getByTestId('arena-arrival-close').click()
  await announced
  await expect(arrival).toBeHidden()

  const read = page.waitForResponse(isOverview)
  await page.goto('/#/arene')
  await read
  for (const level of [1, 2, 3, 4, 5, 6])
    await expect(page.getByTestId(`arena-level-${level}`)).toBeVisible()
  await expect(page.getByTestId('arena-boss-zombie')).toHaveAttribute(
    'data-state',
    'open'
  )
  await expect(page.getByTestId('arena-boss-jongleur')).toHaveAttribute(
    'data-state',
    'locked'
  )
  await expect(page.getByTestId('arena-boss-sith')).toHaveAttribute(
    'data-state',
    'soon'
  )
  await expect(page.getByTestId('arena-boss-requin')).toHaveAttribute(
    'data-state',
    'shop'
  )
  await expect(
    page.getByTestId('arena-level-6').getByTestId('arena-boss-ray')
  ).toBeVisible()
  await expect(arrival).toBeHidden()

  // A path's boss: where to beat it, no price.
  await page.getByTestId('arena-boss-jongleur').click()
  const sheet = page.getByTestId('arena-sheet')
  await expect(sheet.getByTestId('arena-locked-go')).toBeVisible()
  await expect(sheet.getByTestId('arena-buy')).toHaveCount(0)
  await sheet.getByTestId('arena-sheet-close').click()

  // The Requin: 150 crystals, then it arrives at once.
  await page.getByTestId('arena-boss-requin').click()
  await expect(sheet).toHaveAttribute('data-boss', 'requin')
  const bought = page.waitForResponse(isPurchase)
  await sheet.getByTestId('arena-buy').click()
  const arena = await (await bought).json()
  expect(arena.balance).toBe(50)
  await expect(arrival).toHaveAttribute('data-boss', 'requin')
  await arrival.getByTestId('arena-arrival-challenge').click()
  await expect(arrival).toBeHidden()
  await expect(page.getByTestId('arena-sheet')).toHaveAttribute(
    'data-boss',
    'requin'
  )
  await expect(page.getByTestId('arena-play')).toBeVisible()
  await expect(page.getByTestId('arena-boss-requin')).toHaveAttribute(
    'data-state',
    'open'
  )

  // The shop shows it bought.
  await page.goto('/#/boutique')
  await expect(
    page.getByTestId('shop-section-arena').getByTestId('arena-boss-requin')
  ).toHaveAttribute('data-state', 'open')
})

test('a game against the Zombie: stepped back, resigned, its result, its review and its PGN', async ({
  page,
  context
}) => {
  test.setTimeout(150_000)
  await signIn(context)
  await noMaia(page)
  await startZombie(page)

  // Stockfish plays for the Zombie (Maia unavailable), and the page says so.
  const answered = page.waitForResponse(isMove(1), { timeout: ENGINE_TIMEOUT })
  await playMove(page, 'e2e4')
  expect((await answered).status()).toBe(200)
  await expect(page.getByTestId('arena-moves')).toContainText(/1\.\s*e4/)
  await expect(page.getByTestId('arena-notice')).toBeVisible()
  await expect(page.getByTestId('arena-material-player')).toHaveAttribute(
    'data-score',
    '0'
  )

  // Back to 1. e4 once the Zombie has answered: no move until back to the game.
  await expect(page.getByTestId('arena-ply-2')).toBeVisible({
    timeout: ENGINE_TIMEOUT
  })
  await page.getByTestId('arena-step-previous').click()
  await expect(page.getByTestId('arena-browsing')).toBeVisible()
  await expect(page.getByTestId('chess-board')).toHaveAttribute(
    'data-fen',
    /^rnbqkbnr\/pppppppp\/8\/8\/4P3\//
  )
  await page.getByTestId('arena-live').click()
  await expect(page.getByTestId('arena-browsing')).toBeHidden()

  await page.getByTestId('arena-resign').click()
  await confirm(page)
  const result = page.getByTestId('arena-result')
  await expect(result).toHaveAttribute('data-status', 'lost')
  await expect(result.getByTestId('arena-result-xp')).toHaveCount(0)

  // The review: Stockfish over the three positions, then the PGN.
  await result.getByTestId('arena-review-open').click()
  const review = page.getByTestId('arena-review')
  await expect(review).toBeVisible()
  await expect(review.getByTestId('arena-review-progress')).toBeHidden({
    timeout: ENGINE_TIMEOUT
  })
  await expect(review.getByTestId('arena-review-curve')).toBeVisible()
  await expect(review.getByTestId('arena-review-summary-player')).toBeVisible()
  await review.getByTestId('arena-review-first').click()
  await expect(review.getByTestId('arena-review-previous')).toBeDisabled()

  const downloaded = page.waitForEvent('download')
  await review.getByTestId('arena-review-download').click()
  const download = await downloaded
  expect(download.suggestedFilename()).toMatch(
    /^dontstayrooky-arene-zombie-\d{4}-\d{2}-\d{2}\.pgn$/
  )
  const path = await download.path()
  const { readFile } = await import('node:fs/promises')
  const pgn = await readFile(path, 'utf8')
  expect(pgn).toContain('[Result "0-1"]')
  expect(pgn).toContain('[BlackElo "700"]')
  expect(pgn).toMatch(/1\. e4 \S+ 0-1/)

  // Back to the result, then to the bosses: the Zombie now has a game lost.
  await review.getByTestId('arena-review-back').click()
  await page.getByTestId('arena-result').getByTestId('arena-back').click()
  await expect(page.getByTestId('arena')).toBeVisible()
  await page.getByTestId('arena-boss-zombie').click()
  await expect(page.getByTestId('arena-sheet')).toContainText('0 V · 0 N · 1 D')
})

test('cancelling and restarting a game at any time', async ({
  page,
  context
}) => {
  await signIn(context)
  await noMaia(page)
  const first = await startZombie(page)

  // Restart: the same game again, a new one.
  const restarted = page.waitForResponse(r =>
    r.url().endsWith(`/api/arena/games/${first}/restart`)
  )
  await page.getByTestId('arena-restart').click()
  await confirm(page)
  const second = await (await restarted).json()
  expect(second.id).not.toBe(first)
  expect(second.moves).toEqual([])
  expect([second.boss, second.color, second.cadence]).toEqual([
    'zombie',
    'white',
    '5+3'
  ])

  // Left, then resumed from the arena page.
  const read = page.waitForResponse(isOverview)
  await page.goto('/#/arene')
  await read
  await page.getByTestId('arena-resume').click()
  await expect(page.getByTestId('arena-game')).toHaveAttribute(
    'data-status',
    'active'
  )

  // Cancel: back to the bosses, nothing to resume, counted nowhere.
  await page.getByTestId('arena-abort').click()
  await confirm(page)
  await expect(page.getByTestId('arena')).toBeVisible()
  await expect(page.getByTestId('arena-resume')).toHaveCount(0)
  await page.getByTestId('arena-boss-zombie').click()
  await expect(page.getByTestId('arena-sheet')).not.toContainText(' V · ')
})

test('Lizy arrives with every endgame lesson mastered, Albert locked, Ray a mystery', async ({
  page,
  context
}) => {
  await signIn(context, {
    arrivals: true,
    mindMapCapacity: 'endgames',
    mindMapSteps: ENDGAME_LESSONS
  })

  // The Zombie, then Lizy, the professors last.
  await page.goto('/#/')
  const arrival = page.getByTestId('arena-arrival')
  await expect(arrival).toHaveAttribute('data-boss', 'zombie')
  await arrival.getByTestId('arena-arrival-next').click()
  await expect(arrival).toHaveAttribute('data-boss', 'lizy')
  await expect(arrival).toContainText('Lizy')
  const announced = page.waitForResponse(r =>
    r.url().endsWith('/api/arena/unlocks/announce')
  )
  await arrival.getByTestId('arena-arrival-close').click()
  await announced
  await expect(arrival).toBeHidden()

  const read = page.waitForResponse(isOverview)
  await page.goto('/#/arene')
  await read
  const masters = page.getByTestId('arena-level-6')
  await expect(masters.getByTestId('arena-boss-lizy')).toHaveAttribute(
    'data-state',
    'open'
  )
  await expect(masters.getByTestId('arena-boss-albert')).toHaveAttribute(
    'data-state',
    'locked'
  )
  await expect(masters.getByTestId('arena-boss-albert')).toContainText(
    'Streak 0/50'
  )
  const ray = masters.getByTestId('arena-boss-ray')
  await expect(ray).toContainText('???')
  await expect(ray).not.toContainText('Ray')

  // Albert's sheet: his feat and where to reach it.
  await masters.getByTestId('arena-boss-albert').click()
  const sheet = page.getByTestId('arena-sheet')
  await expect(sheet).toHaveAttribute('data-boss', 'albert')
  await expect(sheet.getByTestId('arena-locked')).toContainText(
    '50 puzzles d’affilée'
  )
  await expect(sheet.getByTestId('arena-feat')).toHaveText('0 / 50')
  await expect(sheet.getByTestId('arena-locked-go')).toHaveAttribute(
    'href',
    '#/streak'
  )
  await sheet.getByTestId('arena-sheet-close').click()

  // Lizy's: 1000 crystals, ready to play.
  await masters.getByTestId('arena-boss-lizy').click()
  await expect(sheet).toHaveAttribute('data-boss', 'lizy')
  await expect(sheet.getByTestId('arena-sheet-reward')).toContainText('1000')
  await expect(sheet.getByTestId('arena-play')).toBeVisible()
})
