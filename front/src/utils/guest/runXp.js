/**
 * The XP announced by each submission of a run (its preview, docs/GAMIFICATION.md), summed per run
 * in this tab: a guest's end-of-run popup (docs/GUEST.md) tells it at once, before the worker has
 * written the ledger that the run's review reads.
 */

/** @type {Map<string, number>} */
const byRun = new Map()

/**
 * @param {string} runId
 * @param {unknown} xp a submission's `xp` (null or missing: none)
 */
export function addRunXp(runId, xp) {
  if (typeof xp !== 'number' || xp <= 0) return
  byRun.set(runId, (byRun.get(runId) ?? 0) + xp)
}

/**
 * @param {string} runId
 * @returns {number} the XP announced so far in the run
 */
export function runXp(runId) {
  return byRun.get(runId) ?? 0
}
