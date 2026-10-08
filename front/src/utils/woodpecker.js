/**
 * Woodpecker set page helpers (docs/WOODPECKER.md): the cycle lengths, the last day of a
 * deadline and the explanation of the method, worded from the set's own settings.
 */

/**
 * Length of cycle `number` in days: first × factor^(number − 1), rounded up, never below the
 * minimum. Mirrors the server's DeadlineCalculator::cycleDays().
 *
 * @param {{firstCycleDays: number, reductionFactor: number, minCycleDays: number}} config
 * @param {number} number 1-based
 * @returns {number}
 */
export function cycleDays(config, number) {
  const days = config.firstCycleDays * config.reductionFactor ** (number - 1)
  return Math.max(1, config.minCycleDays, Math.ceil(days - 1e-9))
}

/**
 * The length of every cycle of a classic set, in days: [28, 14, 7, 4, 2].
 *
 * @param {{cycleCount: number|null, firstCycleDays: number|null, reductionFactor: number|null, minCycleDays: number|null}} set
 * @returns {number[]}
 */
export function cycleSchedule(set) {
  if (
    !set.cycleCount ||
    !set.firstCycleDays ||
    !set.reductionFactor ||
    set.minCycleDays === null
  )
    return []
  const config = {
    firstCycleDays: set.firstCycleDays,
    reductionFactor: set.reductionFactor,
    minCycleDays: set.minCycleDays
  }
  return Array.from({ length: set.cycleCount }, (_, i) =>
    cycleDays(config, i + 1)
  )
}

/**
 * The last day of a deadline (the end of a local day, i.e. the next local midnight), in words:
 * "mardi 21 octobre".
 *
 * @param {string} deadlineAt ISO 8601
 * @param {string} timeZone the user's timezone
 * @returns {string}
 */
export function deadlineDay(deadlineAt, timeZone) {
  return new Intl.DateTimeFormat('fr-FR', {
    timeZone,
    weekday: 'long',
    day: 'numeric',
    month: 'long'
  }).format(new Date(new Date(deadlineAt).getTime() - 1000))
}

/**
 * How the cycles work, in a few short points worded from the set's settings.
 *
 * @param {{mode: 'classic'|'light', puzzleCount: number, cycleCount: number|null, firstCycleDays: number|null, reductionFactor: number|null, minCycleDays: number|null, restDays: number|null, shuffle: boolean}} set
 * @returns {{title: string, text: string}[]}
 */
export function explainCycles(set) {
  if (set.mode === 'light') {
    return [
      {
        title: 'Toujours les mêmes puzzles',
        text: `Votre set compte ${set.puzzleCount} puzzles. Chaque séance chronométrée repart du premier : vous revoyez les mêmes motifs jusqu’à les reconnaître d’un coup d’œil.`
      },
      {
        title: 'Un seul essai',
        text: 'Une erreur, et la solution s’affiche : retenez-la, le puzzle reviendra.'
      },
      {
        title: 'Un set qui grandit',
        text: 'Quand une séance arrive au bout des puzzles, le set s’agrandit de nouveaux puzzles du même niveau.'
      },
      {
        title: 'Sur mesure',
        text: 'Un puzzle ne vous plaît pas ? Remplacez-le : un autre du même niveau prend sa place.'
      }
    ]
  }

  const schedule = cycleSchedule(set)
  const days = schedule.map(d => `${d} j`).join(' → ')
  const rest = set.restDays
    ? `${set.restDays} jour${set.restDays > 1 ? 's' : ''} de repos`
    : 'aucun repos'
  return [
    {
      title: 'Toujours les mêmes puzzles',
      text: `Un cycle, c’est jouer les ${set.puzzleCount} puzzles du set une fois chacun${set.shuffle ? ', dans un ordre mélangé à chaque cycle' : ', toujours dans le même ordre'}.`
    },
    {
      title: 'De plus en plus vite',
      text: `${set.cycleCount} cycles, chacun plus court que le précédent : ${days}. Entre deux cycles, ${rest}.`
    },
    {
      title: 'Un seul essai',
      text: 'Une erreur, et la solution s’affiche : le puzzle compte comme raté pour ce cycle. Retenez le motif, il reviendra.'
    },
    {
      title: 'L’échéance compte',
      text: 'Un cycle non terminé à temps est perdu et recommence avec le même délai (« essai 2 »). Les échéances tombent en fin de journée.'
    },
    {
      title: 'Le but',
      text: 'Gagner en précision et en vitesse sur les mêmes motifs, jusqu’à les jouer par réflexe en partie.'
    },
    {
      title: 'Sur mesure',
      text: 'Un puzzle ne vous plaît pas ? Remplacez-le : un autre du même niveau prend sa place, sans rien changer aux échéances.'
    }
  ]
}
