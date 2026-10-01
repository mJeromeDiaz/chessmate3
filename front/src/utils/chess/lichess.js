/**
 * Display helpers of the Lichess panels (opening explorer, cloud evaluation).
 */

/**
 * An engine evaluation as written by Lichess, from White's point of view: "+0.25", "−1.30",
 * "#3" (White mates in 3), "#−2" (Black mates in 2).
 *
 * @param {{cp: number|null, mate: number|null}} line
 * @returns {string}
 */
export function formatEval({ cp, mate }) {
  if (mate !== null && mate !== undefined) {
    return mate < 0 ? `#−${-mate}` : `#${mate}`
  }
  if (cp === null || cp === undefined) return '?'
  const pawns = (Math.abs(cp) / 100).toFixed(2)
  if (cp === 0) return '0.00'
  return cp > 0 ? `+${pawns}` : `−${pawns}`
}

/**
 * Which side an evaluation favours, for its colour.
 *
 * @param {{cp: number|null, mate: number|null}} line
 * @returns {'white'|'black'|'equal'}
 */
export function evalSide({ cp, mate }) {
  if (mate) return mate > 0 ? 'white' : 'black'
  if (!cp || Math.abs(cp) < 30) return 'equal'
  return cp > 0 ? 'white' : 'black'
}

/**
 * White wins, draws and Black wins in whole percents that add up to 100 (largest remainders),
 * or null without games.
 *
 * @param {{white: number, draws: number, black: number}} counts
 * @returns {{white: number, draws: number, black: number}|null}
 */
export function resultShares({ white, draws, black }) {
  const total = white + draws + black
  if (total <= 0) return null
  const raw = [white, draws, black].map(n => (n * 100) / total)
  const floors = raw.map(Math.floor)
  let missing = 100 - floors.reduce((a, b) => a + b, 0)
  const order = raw
    .map((value, i) => ({ i, rest: value - floors[i] }))
    .sort((a, b) => b.rest - a.rest)
  for (const { i } of order) {
    if (missing <= 0) break
    floors[i]++
    missing--
  }
  return { white: floors[0], draws: floors[1], black: floors[2] }
}

/**
 * A number of games, short: 950, 12.3k, 1.2M (French decimal comma).
 *
 * @param {number} count
 * @returns {string}
 */
export function formatGames(count) {
  const short = (value, unit) =>
    `${value
      .toFixed(value < 10 ? 1 : 0)
      .replace(/\.0$/, '')
      .replace('.', ',')}${unit}`
  if (count >= 1_000_000) return short(count / 1_000_000, 'M')
  if (count >= 10_000) return short(count / 1_000, 'k')
  return count.toLocaleString('fr-FR')
}

/**
 * The first moves of an engine line with their numbers, from a position: "3…e6 4.Nf3 c5".
 *
 * @param {string[]} san
 * @param {{turn: 'w'|'b', depth: number}} position where the line starts
 * @returns {string}
 */
export function numberedLine(san, position) {
  let ply = position.depth
  let white = position.turn === 'w'
  return san
    .map((move, i) => {
      const number = Math.floor(ply / 2) + 1
      const text = white
        ? `${number}.${move}`
        : i === 0
          ? `${number}…${move}`
          : move
      ply++
      white = !white
      return text
    })
    .join(' ')
}
