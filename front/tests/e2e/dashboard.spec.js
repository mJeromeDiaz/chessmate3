import { expect, test } from '@playwright/test'
import { signIn } from './helpers.js'

/**
 * Y-m-d of today shifted by `days` (the API answers in the user's timezone; the seeded user is in
 * UTC unless the browser reports another one, and only relative positions matter here).
 *
 * @param {number} days
 * @returns {string}
 */
function day(days) {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

const SCREENSHOTS = process.env.DASHBOARD_SCREENSHOTS

test('a new user is welcomed and pointed to a first session', async ({
  page,
  context
}) => {
  await signIn(context)
  await page.goto('/#/')
  await expect(page.getByTestId('dashboard-welcome')).toBeVisible()
  await expect(page.getByTestId('rating-card')).toHaveCount(0)
  await expect(page.getByTestId('module-row-analyse')).toContainText('Bientôt')
  await page.getByTestId('welcome-session').click()
  await expect(page).toHaveURL(/#\/session\/new$/)
})

/**
 * The local month of the streak sheet (the summary's `streak.month`): today played, the two days
 * before it played then frozen when the month allows, the rest missed, after today to come.
 *
 * @returns {{first: string, days: string[]}}
 */
function month() {
  const now = new Date()
  const today = now.getUTCDate()
  const length = new Date(
    Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 0)
  ).getUTCDate()
  const days = Array.from({ length }, (_, i) => {
    const n = i + 1
    if (n === today || n === today - 2) return 'played'
    if (n === today - 1) return 'frozen'
    return n < today ? 'missed' : 'future'
  })
  return { first: day(1 - today), days }
}

/** @param {number} hours from now @returns {string} */
function inHours(hours) {
  return new Date(Date.now() + hours * 3_600_000).toISOString()
}

/**
 * GET /api/shop: a freeze and a pack on sale (the freeze ends first), an offer at its usual price
 * and one coming soon, neither of them a deal.
 */
const SHOP = {
  balance: 120,
  offers: [
    {
      id: 'offer-pack',
      slug: 'pack-streak',
      section: 'aids',
      name: 'Pack Puzzle Streak',
      subtitle: null,
      description: '',
      items: [{ item: 'puzzle_streak_help', quantity: 3 }],
      module: 'puzzle_streak',
      price: 90,
      wasPrice: 150,
      comingSoon: false,
      endsAt: null
    },
    {
      id: 'offer-freeze',
      slug: 'gel-semaine',
      section: 'freeze',
      name: 'Gel d’automne',
      subtitle: null,
      description: '',
      items: [{ item: 'streak_freeze_7', quantity: 1 }],
      module: null,
      price: 60,
      wasPrice: 100,
      comingSoon: false,
      endsAt: inHours(50)
    },
    {
      id: 'offer-plain',
      slug: 'gel-jour',
      section: 'freeze',
      name: 'Gel du jour',
      subtitle: null,
      description: '',
      items: [{ item: 'streak_freeze_1', quantity: 1 }],
      module: null,
      price: 20,
      wasPrice: null,
      comingSoon: false,
      endsAt: null
    },
    {
      id: 'offer-soon',
      slug: 'bientot',
      section: 'aids',
      name: 'Bientôt là',
      subtitle: null,
      description: '',
      items: [{ item: 'puzzle_streak_pass', quantity: 1 }],
      module: 'puzzle_streak',
      price: 10,
      wasPrice: 40,
      comingSoon: true,
      endsAt: null
    }
  ],
  slides: [],
  inventory: {},
  freezes: [],
  earning: {
    crownsPerHour: 60,
    perfectPercent: 150,
    nearPercent: 100,
    missedPercent: 50,
    dailyMinutes: 60,
    minutesLeftToday: 60
  },
  dailyWheel: null,
  gifts: []
}

test('with history: level, streak and its sheet, path, store deals, modules, gamification', async ({
  page,
  context
}) => {
  // One step of the visualisation path done: the path card proposes it (docs/DASHBOARD.md § 3).
  await signIn(context, { mindMapSteps: ['1-1'] })
  const today = day(0)
  const calendar = month()
  await page.route('**/api/dashboard/activity**', route =>
    route.fulfill({
      json: {
        timezone: 'UTC',
        from: day(-83),
        today,
        days: [
          { date: day(-2), count: 3, successCount: 2, durationMs: 90_000 },
          { date: today, count: 25, successCount: 20, durationMs: 600_000 }
        ],
        totals: {
          puzzle_rated: { count: 28, successCount: 22, durationMs: 690_000 }
        }
      }
    })
  )
  await page.route('**/api/shop', route => route.fulfill({ json: SHOP }))

  await page.route('**/api/gamification/summary', route =>
    route.fulfill({
      json: {
        xp: 30_340,
        level: 12,
        xpInLevel: 2340,
        xpForNext: 3000,
        rank: 'tactician',
        nextRank: { rank: 'strategist', level: 15 },
        modules: {
          puzzles: { xp: 2100, level: 6, xpInLevel: 0, xpForNext: 600 }
        },
        streak: {
          current: 3,
          best: 21,
          playedToday: true,
          week: [true, true, true, false, false, false, false],
          frozenWeek: [],
          freezeDays: 2,
          nextMilestone: 7,
          month: calendar
        },
        today: { date: today, exerciseXp: 40, cap: 500 }
      }
    })
  )
  await page.route('**/api/gamification/trophies', route =>
    route.fulfill({
      json: {
        trophies: [
          {
            key: 'first_step',
            goal: 1,
            current: 1,
            unlocked: true,
            unlockedAt: `${day(-2)}T08:00:00+00:00`,
            ratio: null
          },
          {
            key: 'streak_3',
            goal: 3,
            current: 3,
            unlocked: true,
            unlockedAt: `${day(-2)}T08:00:00+00:00`,
            ratio: null
          },
          {
            key: 'centurion',
            goal: 1000,
            current: 22,
            unlocked: false,
            unlockedAt: null,
            ratio: null
          }
        ]
      }
    })
  )
  await page.route('**/api/gamification/quest', route =>
    route.fulfill({
      json: {
        id: 'quest-1',
        template: 'rated_puzzles',
        theme: null,
        module: 'puzzles',
        goal: 40,
        current: 22,
        reward: 150,
        completed: false,
        completedAt: null,
        weekStart: day(-1),
        weekEnd: day(5)
      }
    })
  )

  await page.goto('/#/')
  await expect(page.getByTestId('dashboard')).toBeVisible()
  await expect(page.getByTestId('dashboard-welcome')).toHaveCount(0)

  // The rating curve, the recent sessions and the weak-theme tip left for /stats (design v2).
  await expect(page.getByTestId('rating-card')).toHaveCount(0)
  await expect(page.getByTestId('recent-sessions')).toHaveCount(0)
  await expect(page.getByTestId('weak-theme-tip')).toHaveCount(0)
  await expect(page.getByTestId('activity-heatmap')).toHaveCount(0)
  await expect(page.getByTestId('dashboard-stats')).toHaveAttribute(
    'href',
    '#/stats'
  )

  await expect(page.getByTestId('module-row-puzzles')).toContainText(
    '22 résolus'
  )
  await expect(page.getByTestId('showcase-tag')).toHaveCount(0)

  // Gamification (docs/GAMIFICATION.md): level, streak, module level, trophies, weekly quest.
  await expect(page.getByTestId('level-title')).toHaveText('Niveau 12')
  await expect(page.getByTestId('level-xp')).toHaveText('2 340 / 3 000 XP')
  // The streak: in the header and its card, lit (alive), its freezes' days, the week.
  await expect(page.getByTestId('streak-count')).toHaveText('3')
  const card = page.getByTestId('streak-card')
  await expect(card.getByTestId('streak-card-count')).toHaveText('3')
  await expect(card.getByTestId('streak-card-flame')).not.toHaveClass(
    /streak-flame--out/
  )
  await expect(card.getByTestId('streak-card-freezes')).toContainText('2')
  await expect(card.getByTestId('streak-day-0')).toHaveAttribute(
    'data-done',
    'true'
  )
  await expect(card.getByTestId('streak-day-3')).not.toHaveAttribute(
    'data-done',
    'true'
  )
  await expect(page.getByTestId('streak-badges-count')).toHaveCount(0)

  await expect(
    page.getByTestId('module-row-puzzles').getByTestId('module-level')
  ).toHaveText('Niv. 6')
  await expect(
    page.getByTestId('module-row-analyse').getByTestId('module-level')
  ).toHaveCount(0)
  await expect(page.getByTestId('trophies-count')).toHaveText('2 / 3 débloqués')
  await expect(page.getByTestId('trophy-streak_3')).toHaveAttribute(
    'data-unlocked',
    'true'
  )
  await expect(page.getByTestId('trophy-first_step')).toHaveAttribute(
    'data-unlocked',
    'true'
  )
  // Only won trophies show; the others are only counted.
  await expect(page.getByTestId('trophy-centurion')).toHaveCount(0)
  await expect(page.getByTestId('quest-text')).toHaveText(
    'Résous 40 puzzles classés cette semaine.'
  )
  await expect(page.getByTestId('quest-progress')).toHaveText('22/40')

  // The path played last, cut short around « TU ES ICI »; the endgames' and défis' cards.
  const path = page.getByTestId('dashboard-path-current')
  await expect(path).toHaveAttribute('data-capacity', 'visualisation')
  await expect(path).toContainText('TU ES ICI')
  await expect(path.getByTestId('dashboard-path-continue')).toHaveText(
    'Continuer'
  )
  await expect(page.getByTestId('dashboard-path-other').first()).toBeVisible()
  await expect(page.getByTestId('dashboard-endgames')).toBeVisible()
  await expect(page.getByTestId('dashboard-challenges')).toBeVisible()

  // The store's deals: on sale only, the soonest to end first, what they hold read from the items.
  const deals = page.getByTestId('dashboard-deal')
  await expect(deals).toHaveCount(2)
  await expect(deals.nth(0)).toContainText('Gel d’automne')
  await expect(deals.nth(0)).toContainText('Gel 1 semaine')
  await expect(deals.nth(0)).toContainText('Fin dans 2 j')
  await expect(deals.nth(1)).toContainText('Pack Puzzle Streak')
  await expect(deals.nth(1)).toContainText('3 × Aide Puzzle Streak')

  if (SCREENSHOTS) {
    await page.screenshot({
      path: `${SCREENSHOTS}/dashboard-desktop.png`,
      fullPage: true
    })
    await page.setViewportSize({ width: 390, height: 844 })
    await page.screenshot({
      path: `${SCREENSHOTS}/dashboard-mobile.png`,
      fullPage: true
    })
    await page.setViewportSize({ width: 1280, height: 720 })
  }

  // « En voir plus »: the month, the badges, the next tier and its chest, the freezes on sale.
  await card.getByTestId('streak-card-more').click()
  const sheet = page.getByTestId('streak-sheet')
  await expect(sheet).toContainText('3 jours d’affilée')
  const cells = sheet.getByTestId('streak-calendar')
  const count = state => calendar.days.filter(d => d === state).length
  for (const state of ['played', 'frozen', 'missed'])
    await expect(cells.locator(`[data-state="${state}"]`)).toHaveCount(
      count(state)
    )
  await expect(sheet).toContainText(
    `${count('played')} joués · ${count('frozen')} gelés`
  )
  await expect(sheet).toContainText('Record : 21 jours')
  await expect(sheet).toContainText('PROCHAIN PALIER · 7 JOURS')
  await expect(sheet).toContainText('Encore 4 jours')
  await expect(sheet).toContainText('+70')
  // The freezes on sale, cheapest first; one opens the purchase in place.
  const freezes = sheet.getByTestId('streak-sheet-freeze')
  await expect(freezes).toHaveCount(2)
  await expect(freezes.nth(0)).toContainText('Gel du jour')
  await expect(freezes.nth(1)).toContainText('Gel d’automne')
  await page.keyboard.press('Escape')
  await expect(sheet).toHaveCount(0)

  // A deal opens its offer in the store; the path card, its path.
  await deals.nth(0).click()
  await expect(page).toHaveURL(/#\/boutique\?offer=gel-semaine$/)
  await page.goBack()
  await page.getByTestId('dashboard-path-continue').click()
  await expect(page).toHaveURL(/#\/cerveau\/visualization$/)
})
