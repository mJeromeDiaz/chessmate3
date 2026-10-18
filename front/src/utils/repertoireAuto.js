import { Chess } from 'chess.js'
import { intlLocale, t } from '@/i18n'

/**
 * Repertoire from my games (design "Repertoire auto", docs/REPERTOIRE.md): the wording and the
 * figures of the pages, computed from what the API returns. Pure functions.
 *
 * @typedef {{key: string, family: string, color: 'white'|'black', games: number, wins: number, draws: number, losses: number, analysed: number}} Family
 * @typedef {{games: number, analysed: number, wins: number, draws: number, losses: number, families: Family[]}} Synthesis
 * @typedef {{uci: string, san: string, games: number, wins: number, draws: number, losses: number}} Alternative
 * @typedef {{path: string, parent: string, depth: number, uci: string, san: string, fen: string, user: boolean, games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, think: number|null, moveAnalysed: number, moveErrors: number, suggested: boolean, suggestion: {uci: string, san: string}|null, alternatives: Alternative[]}} TreeNode
 * @typedef {{kind: 'reply_uncovered'|'no_user_move'|'no_popular_reply'|'unexplored'|'unplayed'|'depth', san: string|null, path: string|null}} Stop why a line ends
 * @typedef {{path: string, cut: string, name: string, nodes: string[], games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, fen: string, depth: number, short: boolean, stop: Stop, confirmed: boolean, number: number, tag: Tag, evaluated: boolean, evalCp: number|null, evalMate: number|null, locked: boolean}} Line short: kept only once confirmed (confirmed: kept)
 * @typedef {{path: string, parent: string, depth: number, fen: string, uci: string, san: string, sans: string[], reason: string, games: number|null, share: number|null, score: number|null}} PendingNode a position left without the user's move (the user to move), reached by a popular reply
 * @typedef {{key: string, family: string, color: 'white'|'black', games: number, wins: number, draws: number, losses: number, trunk: number, nodes: TreeNode[], lines: Line[], pending: PendingNode[]}} Tree
 * @typedef {'risk'|'weapon'|'draws'|'solid'} Tag
 * @typedef {{kind: 'error'|'key'|'good'|'alt', ply: number, text: string}} LineEvent
 * @typedef {{fen: string, uci: string, games: number, wins: number, draws: number, losses: number, analysed: number, errored: number, thinkCs: number|null, moveAnalysed: number, moveErrors: number, bestUci: string|null, evalCp: number|null, evalMate: number|null}} GameStat
 */

/** Games a build may read (the API's choices). */
export const GAME_COUNTS = [50, 100, 200, 500, 1000, 2000]

/** Lichess speeds offered. */
export const SPEEDS = ['bullet', 'blitz', 'rapid', 'classical'].map(value => ({
  value,
  get label() {
    return t(`repertoireAuto.speeds.${value}`)
  }
}))

/** The page's accent, aqua green (buttons, choices on, the "weapon" tag, wins). */
export const ACCENT = '#5EE6B0'

/** Tags of a line: label and colour (design). */
export const TAGS = {
  risk: tag('risk', '#FF6FAE'),
  weapon: tag('weapon', ACCENT),
  draws: tag('draws', '#DCD8E8'),
  solid: tag('solid', '#4FB2FF')
}

/**
 * @param {Tag} key
 * @param {string} color
 * @returns {{label: string, color: string}}
 */
function tag(key, color) {
  return {
    get label() {
      return t(`repertoireAuto.tags.${key}`)
    },
    color
  }
}

/** Wins, draws and losses: label and colour (the line sheet's bar). */
export const OUTCOMES = [
  ['wins', ACCENT],
  ['draws', '#B4AFC6'],
  ['losses', '#FF6FAE']
].map(([key, color]) => ({
  key,
  get label() {
    return t(`repertoireAuto.outcomes.${key}`)
  },
  color
}))

/** Steps of the page, with the header's labels. */
export const STEPS = ['connect', 'scan', 'select', 'gen', 'tree']
export const STEP_LABELS = Object.defineProperties(
  {},
  Object.fromEntries(
    STEPS.map(step => [
      step,
      { get: () => t(`repertoireAuto.steps.${step}`), enumerable: true }
    ])
  )
)

/**
 * The score of a set of games: wins plus half the draws, in whole percents.
 *
 * @param {{games: number, wins: number, draws: number}} counts
 * @returns {number}
 */
export function score({ games, wins, draws }) {
  return games > 0 ? Math.round((100 * (wins + draws / 2)) / games) : 0
}

/**
 * A share in whole percents.
 *
 * @param {number} part
 * @param {number} total
 * @returns {number}
 */
export function percent(part, total) {
  return total > 0 ? Math.round((100 * part) / total) : 0
}

/**
 * A move with its number: "5.d4", "5…a6" (black, forced) or "a6" (black, after white's move).
 *
 * @param {number} ply 1 for White's first move
 * @param {string} san
 * @param {boolean} [force] number a black move too
 * @returns {string}
 */
export function moveLabel(ply, san, force = false) {
  const number = Math.ceil(ply / 2)
  if (ply % 2 === 1) return `${number}.${san}`
  return force ? `${number}…${san}` : san
}

/**
 * Moves from a ply on, numbered: "5.d4 cxd4 6.Nxd4".
 *
 * @param {{san: string, depth: number}[]} nodes the moves (depth = ply)
 * @returns {string}
 */
export function sequence(nodes) {
  return nodes.map((n, i) => moveLabel(n.depth, n.san, i === 0)).join(' ')
}

/**
 * The user's result in a game: letter and colour of the live feed.
 *
 * @param {number} result 1, 0, -1
 * @returns {{letter: string, color: string}}
 */
export function resultBadge(result) {
  if (result > 0)
    return { letter: t('repertoireAuto.letters.win'), color: ACCENT }
  if (result === 0)
    return { letter: t('repertoireAuto.letters.draw'), color: '#B4AFC6' }
  return { letter: t('repertoireAuto.letters.loss'), color: '#FF6FAE' }
}

/**
 * A thinking time: "45 s", "1 min 20", "3 min".
 *
 * @param {number|null} centiseconds
 * @returns {string}
 */
export function formatThink(centiseconds) {
  if (centiseconds === null || centiseconds === undefined) return ''
  const seconds = Math.round(centiseconds / 100)
  if (seconds < 60) return t('repertoireAuto.think.seconds', { n: seconds })
  const minutes = Math.floor(seconds / 60)
  const rest = seconds % 60
  return rest
    ? t('repertoireAuto.think.minutesSeconds', {
        m: minutes,
        s: String(rest).padStart(2, '0')
      })
    : t('repertoireAuto.think.minutes', { m: minutes })
}

/**
 * The cloud evaluation of a line's end, the user's point of view.
 *
 * @param {Line} line
 * @returns {string}
 */
export function evalText(line) {
  const e = 'repertoireAuto.eval.'
  if (!line.evaluated) return t(`${e}pending`)
  if (line.evalMate !== null) {
    return line.evalMate > 0
      ? t(`${e}mateFor`, { n: line.evalMate })
      : t(`${e}mateAgainst`, { n: -line.evalMate })
  }
  if (line.evalCp === null) return t(`${e}none`)
  const pawns = (Math.abs(line.evalCp) / 100).toLocaleString(intlLocale(), {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1
  })
  if (Math.abs(line.evalCp) < 20) return t(`${e}equal`)
  return t(line.evalCp > 0 ? `${e}plus` : `${e}minus`, { n: pawns })
}

/**
 * The families checked at first: the most played one with white and the most played one with
 * black (the families come most played first).
 *
 * @param {Synthesis} synthesis
 * @returns {string[]} family keys
 */
export function defaultFamilies(synthesis) {
  return ['white', 'black']
    .map(color => synthesis.families.find(f => f.color === color)?.key)
    .filter(key => key !== undefined)
}

/** The live feed's pace: a reading's feed lasts about this long at most... */
const FEED_TOTAL_MS = 60_000
/** ...at a pace between these two (one game every so many ms). */
const FEED_PACE_MIN_MS = 30
const FEED_PACE_MAX_MS = 260

/**
 * One game every so many ms in the live feed: slow enough to be read for a short reading, fast
 * enough for 2000 games not to make the user wait for the animation (the reading itself is faster).
 *
 * @param {number} count games asked
 * @returns {number}
 */
export function feedPace(count) {
  return Math.round(
    Math.min(
      FEED_PACE_MAX_MS,
      Math.max(FEED_PACE_MIN_MS, FEED_TOTAL_MS / (count > 0 ? count : 1))
    )
  )
}

/**
 * Albert's words while the games are read.
 *
 * @param {number} progress 0 to 1
 * @param {boolean} done
 * @param {number} games games read
 * @param {number} families families found
 * @returns {string}
 */
export function scanSay(progress, done, games, families) {
  if (done) {
    if (games === 0) return t('repertoireAuto.scan.none')
    return families > 0
      ? t('repertoireAuto.scan.found', { games, n: families }, families)
      : t('repertoireAuto.scan.noFamily', { games })
  }
  if (progress < 0.35) return t('repertoireAuto.scan.reading')
  if (progress < 0.75) return t('repertoireAuto.scan.habits')
  return t('repertoireAuto.scan.counting')
}

/**
 * A word on an opening family, from where it stands among the user's: their first with a color,
 * their best score, a fragile one.
 *
 * @param {Family} family
 * @param {Synthesis} synthesis
 * @returns {string}
 */
export function familyNote(family, synthesis) {
  const f = 'repertoireAuto.family.'
  const sameColor = synthesis.families.filter(x => x.color === family.color)
  if (sameColor[0]?.key === family.key)
    return t(family.color === 'white' ? `${f}firstWhite` : `${f}firstBlack`)
  const best = [...synthesis.families].sort((x, y) => score(y) - score(x))[0]
  if (best?.key === family.key) return t(`${f}best`)
  const value = score(family)
  if (value < 40) return t(`${f}fragile`)
  if (percent(family.draws, family.games) >= 30) return t(`${f}draws`)
  return t(`${f}share`, { n: percent(family.games, synthesis.games) })
}

/**
 * Albert's summary of what the user plays, from the most played families of each color.
 *
 * @param {Synthesis} synthesis
 * @returns {string}
 */
export function selectSay(synthesis) {
  const first = color => synthesis.families.find(f => f.color === color)
  const white = first('white')
  const black = first('black')
  const parts = []
  if (white)
    parts.push(t('repertoireAuto.select.white', { family: white.family }))
  if (black)
    parts.push(t('repertoireAuto.select.black', { family: black.family }))
  if (!parts.length) return t('repertoireAuto.select.keep')
  return t('repertoireAuto.select.summary', { parts: parts.join(' ; ') })
}

/**
 * Sentences shown while the repertoire is built, from the real figures of the trees.
 *
 * @param {Tree[]} trees
 * @returns {string[]}
 */
export function tickerPhrases(trees) {
  const phrases = []
  for (const tree of trees) {
    phrases.push(
      t('repertoireAuto.ticker.family', {
        family: tree.family,
        games: tree.games,
        score: score(tree)
      })
    )
    const worst = [...tree.lines].sort(
      (a, b) => b.losses / b.games - a.losses / a.games
    )[0]
    if (worst && worst.losses > 0) {
      phrases.push(
        t('repertoireAuto.ticker.worst', {
          name: worst.name,
          n: percent(worst.losses, worst.games)
        })
      )
    }
    const best = [...tree.lines].sort(
      (a, b) => b.wins / b.games - a.wins / a.games
    )[0]
    if (best && best !== worst && best.wins > 0) {
      phrases.push(
        t('repertoireAuto.ticker.best', {
          name: best.name,
          n: percent(best.wins, best.games)
        })
      )
    }
  }
  return phrases.length ? phrases : [t('repertoireAuto.ticker.rebuilding')]
}

/**
 * Albert's words over the finished repertoire.
 *
 * @param {Line[]} lines
 * @param {number} games
 * @returns {string}
 */
export function treeSay(lines, games) {
  const risk = lines.filter(l => l.tag === 'risk').length
  const weapons = lines.filter(l => l.tag === 'weapon').length
  const riskText = risk
    ? t('repertoireAuto.tree.risk', { n: risk }, risk)
    : t('repertoireAuto.tree.noRisk')
  const weaponText = weapons
    ? t('repertoireAuto.tree.weapon', { n: weapons }, weapons)
    : t('repertoireAuto.tree.noWeapon')
  return t('repertoireAuto.tree.say', {
    lines: t('repertoireAuto.tree.lines', { n: lines.length }, lines.length),
    games,
    risk: `${riskText.charAt(0).toUpperCase()}${riskText.slice(1)}`,
    weapon: weaponText
  })
}

/**
 * The critical moments of the repertoire: the line that costs the most, the best one, the most
 * played one (each once), among the lines shown in full.
 *
 * @param {Line[]} lines
 * @returns {{kind: 'risk'|'weapon'|'played', title: string, line: Line, text: string}[]}
 */
export function criticalMoments(lines) {
  const open = lines.filter(l => !l.locked && l.games > 0)
  const moments = []
  const worst = [...open].sort(
    (a, b) => b.losses / b.games - a.losses / a.games || b.games - a.games
  )[0]
  if (worst && worst.losses > 0) {
    moments.push({
      kind: 'risk',
      title: t('repertoireAuto.moments.risk'),
      line: worst,
      text: t('repertoireAuto.moments.riskText', {
        n: percent(worst.losses, worst.games),
        games: worst.games
      })
    })
  }
  const best = [...open].sort(
    (a, b) => b.wins / b.games - a.wins / a.games || b.games - a.games
  )[0]
  if (best && best !== worst && best.wins > 0) {
    moments.push({
      kind: 'weapon',
      title: t('repertoireAuto.moments.weapon'),
      line: best,
      text: t('repertoireAuto.moments.weaponText', {
        n: percent(best.wins, best.games),
        games: best.games
      })
    })
  }
  const played = [...open].sort((a, b) => b.games - a.games)[0]
  if (played && played !== worst && played !== best) {
    moments.push({
      kind: 'played',
      title: t('repertoireAuto.moments.played'),
      line: played,
      text: t('repertoireAuto.moments.playedText', { games: played.games })
    })
  }
  return moments
}

/**
 * What happened along a line, move by move: the user's mistakes Lichess found (with its best
 * move), the moves they think long over, the moves they played safely, the other moves they
 * played.
 *
 * @param {TreeNode[]} nodes the line's nodes, in order
 * @returns {LineEvent[]}
 */
export function lineEvents(nodes) {
  const events = []
  const thinks = nodes
    .filter(n => n.user && n.think !== null)
    .map(n => /** @type {number} */ (n.think))
    .sort((a, b) => a - b)
  const median = thinks.length ? thinks[Math.floor(thinks.length / 2)] : 0
  for (const node of nodes) {
    if (!node.user) continue
    const label = moveLabel(node.depth, node.san, true)
    if (node.suggested) {
      events.push({
        kind: 'good',
        ply: node.depth,
        text: t('repertoireAuto.events.suggested', { move: label })
      })
      continue
    }
    if (node.moveErrors > 0) {
      const advice = node.suggestion
        ? t('repertoireAuto.events.advice', {
            move: moveLabel(node.depth, node.suggestion.san, true)
          })
        : ''
      events.push({
        kind: 'error',
        ply: node.depth,
        text: t(
          'repertoireAuto.events.error',
          {
            move: label,
            n: node.moveErrors,
            analysed: node.moveAnalysed,
            advice
          },
          node.moveErrors
        )
      })
    } else if (node.moveAnalysed >= 3) {
      events.push({
        kind: 'good',
        ply: node.depth,
        text: t('repertoireAuto.events.clean', {
          move: label,
          n: node.moveAnalysed
        })
      })
    }
    if (node.think !== null && node.think >= 3000 && node.think >= 2 * median) {
      events.push({
        kind: 'key',
        ply: node.depth,
        text: t('repertoireAuto.events.think', {
          move: label,
          time: formatThink(node.think)
        })
      })
    }
    if (node.alternatives.length) {
      const others = node.alternatives
        .map(alt =>
          t('repertoireAuto.events.alternative', {
            move: moveLabel(node.depth, alt.san, true),
            games: alt.games,
            score: score(alt)
          })
        )
        .join(', ')
      events.push({
        kind: 'alt',
        ply: node.depth,
        text: t('repertoireAuto.events.alternatives', { list: others })
      })
    }
  }
  return events
}

/**
 * A failure of the API, worded for the page.
 *
 * @param {any} error an axios error
 * @returns {string}
 */
export function autoErrorText(error) {
  const response = error?.response
  const detail = response?.data?.detail
  const e = 'repertoireAuto.errors.'
  const known = [
    'lichess_not_linked',
    'invalid_settings',
    'invalid_selection',
    'not_ready',
    'already_applied',
    'nothing_selected',
    'unconfirmed_lines',
    'limit_repertoires'
  ]
  if (typeof detail === 'string' && known.includes(detail))
    return t(`${e}${detail}`)
  if (response?.status === 404) return t(`${e}expired`)
  if (response?.status === 429) return t(`${e}tooMany`)
  if (response?.status === 503) return t(`${e}lichess`)
  if (!response) return t(`${e}offline`)
  return t(`${e}generic`)
}

/**
 * The SAN moves from the initial position, numbered: "1.e4 c5 2.Nf3".
 *
 * @param {string[]} sans
 * @returns {string}
 */
export function sanPath(sans) {
  return sans.map((san, i) => moveLabel(i + 1, san)).join(' ')
}

/**
 * Why a position is pending, worded (App\Enum\Repertoire\PendingReason).
 *
 * @param {string} reason
 * @returns {string}
 */
export function pendingReasonText(reason) {
  return ['reply_uncovered', 'no_user_move', 'short_line'].includes(reason)
    ? t(`repertoireAuto.pending.reasons.${reason}`)
    : reason
}

/**
 * The pending positions of the trees (the user to move, a popular reply never answered), with
 * their opening, the most played reply first.
 *
 * @param {Tree[]} trees
 * @returns {(PendingNode & {family: string, key: string})[]}
 */
export function pendingItems(trees) {
  return trees
    .flatMap(tree =>
      (tree.pending ?? []).map(p => ({
        ...p,
        family: tree.family,
        key: tree.key
      }))
    )
    .sort((a, b) => (b.games ?? -1) - (a.games ?? -1))
}

/**
 * The short lines neither kept nor set aside yet.
 *
 * @param {Tree[]} trees
 * @returns {(Line & {key: string})[]}
 */
export function unconfirmedLines(trees) {
  return trees.flatMap(tree =>
    tree.lines
      .filter(line => line.short && !line.confirmed)
      .map(line => ({ ...line, key: tree.key }))
  )
}

/**
 * Where a line stops, worded: the popular reply that cuts it, or why nothing comes after it.
 *
 * @param {Line} line
 * @returns {string}
 */
export function stopText(line) {
  const stop = line.stop
  const s = 'repertoireAuto.stop.'
  const reply = stop?.san ? moveLabel(line.depth + 1, stop.san, true) : ''
  switch (stop?.kind) {
    case 'reply_uncovered':
      return t(`${s}uncovered`, { reply })
    case 'no_user_move':
      return t(`${s}noUserMove`, { reply })
    case 'no_popular_reply':
      return t(`${s}noPopular`)
    case 'unplayed':
      return t(`${s}unplayed`)
    case 'depth':
      return t(`${s}depth`)
    default:
      return t(`${s}unexplored`)
  }
}

/**
 * Where the exploration of the opponents' replies stands, worded (null: nothing to say).
 *
 * @param {{calls: number, budget: number, capped: boolean, frontier: number}|null|undefined} exploration
 * @returns {string|null}
 */
export function explorationSay(exploration) {
  if (!exploration) return null
  if (exploration.capped)
    return t('repertoireAuto.explore.capped', { n: exploration.calls })
  if (exploration.frontier > 0)
    return t('repertoireAuto.explore.left', exploration.frontier)
  return null
}

/**
 * A move in UCI as SAN from a position, or the UCI when it cannot be played there.
 *
 * @param {string} fen normalized FEN (no move counters)
 * @param {string} uci
 * @returns {string}
 */
export function uciToSan(fen, uci) {
  try {
    const move = new Chess(`${fen} 0 1`).move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
    return move?.san ?? uci
  } catch {
    return uci
  }
}

/**
 * What the user's games said of a move of the repertoire (editor): how often and how it went,
 * the thinking time, Lichess' judgments, the evaluation at the end of a line.
 *
 * @param {GameStat} stat
 * @param {'white'|'black'} color the repertoire's
 * @returns {{games: number, score: number, outcomes: {key: string, label: string, color: string, share: number}[], facts: string[]}}
 */
export function gameStatFacts(stat, color) {
  const facts = []
  if (stat.thinkCs !== null && stat.thinkCs !== undefined) {
    facts.push(
      t('repertoireAuto.facts.think', { time: formatThink(stat.thinkCs) })
    )
  }
  if (stat.moveAnalysed > 0) {
    const errors = stat.moveErrors
    facts.push(
      errors > 0
        ? stat.bestUci
          ? t('repertoireAuto.facts.errorsAdvice', {
              n: errors,
              analysed: stat.moveAnalysed,
              move: uciToSan(stat.fen, stat.bestUci)
            })
          : t('repertoireAuto.facts.errors', {
              n: errors,
              analysed: stat.moveAnalysed
            })
        : t('repertoireAuto.facts.clean', { analysed: stat.moveAnalysed })
    )
  }
  if (stat.evalCp !== null || stat.evalMate !== null) {
    const sign = color === 'white' ? 1 : -1
    facts.push(
      t('repertoireAuto.facts.end', {
        value: evalText(
          /** @type {any} */ ({
            evaluated: true,
            evalCp: stat.evalCp === null ? null : sign * stat.evalCp,
            evalMate: stat.evalMate === null ? null : sign * stat.evalMate
          })
        )
      })
    )
  }
  return {
    games: stat.games,
    score: score(stat),
    outcomes: OUTCOMES.map(o => ({
      key: o.key,
      label: o.label,
      color: o.color,
      share: percent(stat[o.key], stat.games)
    })),
    facts
  }
}
