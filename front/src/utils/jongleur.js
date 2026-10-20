/**
 * The Jongleur, boss of the visualisation path's chapter 1 (docs/MIND_MAP.md, § 8): its
 * distractions and the verdict on a question.
 */
import { answerText } from '@/utils/coordinates'
import { localSan } from '@/utils/notation'
import { t } from '@/i18n'

/**
 * The distraction of a question, drawn from its id (the same after a reload): about one in three,
 * the board wobbles (`shake`) or pieces fly over it (`drift`).
 *
 * @param {string} id
 * @returns {'shake'|'drift'|null}
 */
export function distractionOf(id) {
  let hash = 0
  for (const char of String(id)) hash = (hash * 31 + char.charCodeAt(0)) >>> 0
  const n = hash % 6
  return n === 0 ? 'shake' : n === 1 ? 'drift' : null
}

/**
 * What the verdict says: « Juste ! », « Trop tard ! » or the miss, with the right answer.
 *
 * @param {string} question
 * @param {string|undefined} square the square asked (find, name, color)
 * @param {{status: string, expected: string|{uci: string, san: string}}} result
 * @returns {string}
 */
export function jongleurVerdict(question, square, result) {
  if (result.status === 'right') return t('mindMapPath.jongleur.verdict.right')
  const expected = result.expected
  const answer =
    typeof expected === 'string'
      ? question === 'color'
        ? t('mindMapPath.jongleur.verdict.color', {
            square: square ?? '',
            color: answerText(expected)
          })
        : expected
      : localSan(expected?.san ?? '')
  return t(
    `mindMapPath.jongleur.verdict.${result.status === 'late' ? 'late' : 'wrong'}`,
    { answer }
  )
}
