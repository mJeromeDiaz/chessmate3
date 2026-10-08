/**
 * The 404 page's animation (design "Erreur 404"): a knight takes the page on e5, "BLUNDER!" falls
 * letter by letter, then the words and the way out. Pure: the frame of each step.
 */

/** Ms from the start at which each step (1 to 6) begins. */
export const STEPS_MS = [450, 750, 1180, 1480, 2250, 2650]

/** The last step: everything shown. */
export const LAST_STEP = STEPS_MS.length

const WORD = 'BLUNDER!'.split('')
const TILTS = [-14, 10, -8, 12, -10, 9, -12, 18]

/**
 * What the scene looks like at a step: 0 the knight waits, 1 it lifts, 2 it jumps, 3 the page is
 * captured, 4 the word falls, 5 it wobbles with the subtitle, 6 Aaron and the buttons.
 *
 * @param {number} p the step, 0 to LAST_STEP
 */
export function notFoundFrame(p) {
  return {
    boardTf: p === 3 ? 'translateY(6px) rotate(-1deg)' : 'none',
    fromOp: p >= 3 ? 0.45 : 0,
    toOp: p >= 3 ? 0.55 : 0,
    kLeft: p >= 2 ? '40%' : '0%',
    kTop: p >= 2 ? '50%' : '25%',
    kTf:
      p === 1
        ? 'translateY(-14%) scale(1.15) rotate(-8deg)'
        : p === 2
          ? 'translateY(-26%) scale(1.2) rotate(6deg)'
          : 'none',
    pageTf: p >= 3 ? 'translate(260%,-170%) rotate(400deg) scale(.5)' : 'none',
    pageOp: p >= 3 ? 0 : 1,
    badgeTf: p >= 3 ? 'scale(1) rotate(-10deg)' : 'scale(0)',
    trayTf: p >= 4 ? 'scale(1) rotate(-8deg)' : 'scale(0)',
    letters: WORD.map((ch, i) => ({
      ch,
      bang: ch === '!',
      delay: `${i * 0.06}s`,
      tf: p >= 4 ? 'none' : `translateY(-140%) rotate(${TILTS[i]}deg)`,
      op: p >= 4 ? 1 : 0
    })),
    titleTf:
      p === 5 ? 'rotate(-4deg) scale(1.04)' : p >= 6 ? 'rotate(-2deg)' : 'none',
    kickOp: p >= 4 ? 1 : 0,
    kickTf: p >= 4 ? 'none' : 'translateY(8px)',
    subOp: p >= 5 ? 1 : 0,
    subTf: p >= 5 ? 'none' : 'translateY(14px)',
    endOp: p >= 6 ? 1 : 0,
    endTf: p >= 6 ? 'none' : 'translateY(18px)'
  }
}
