import { profImage } from '@/utils/prof/images'
import { t } from '@/i18n'

/**
 * @typedef {object} ProfColors
 * @property {string} bg main colour (hero, cards)
 * @property {string} deep darker shade (gradient, bars, accents)
 * @property {string} soft pale tint (chips, picture wells)
 * @property {string} accentInk readable accent on white (labels, percentages)
 * @property {string} ink text colour on `bg`
 *
 * @typedef {object} Prof
 * @property {string} slug URL key (/prof/<slug>)
 * @property {string} name
 * @property {string} title chess piece persona, e.g. "La Reine"
 * @property {string} glyph the piece, text presentation
 * @property {string} kicker e.g. "PROF · FINALES"
 * Texts (title, kicker, tagline, traits, motto, stats, specialties, method, cta) are getters
 * reading `profs.<prof>.*` in the current language (docs/I18N.md).
 * @property {string} tagline
 * @property {string[]} traits
 * @property {string} motto
 * @property {string} mottoImage illustration next to the motto
 * @property {string} full full-length illustration (hero)
 * @property {string} bust bust illustration (avatars)
 * @property {{value: string, label: string}[]} stats
 * @property {{name: string, desc: string, pct: number}[]} specialties
 * @property {{image: string, title: string, desc: string}[]} method
 * @property {{label: string, module: string}} cta adds this module to the session
 * @property {ProfColors} colors
 */

/**
 * The professors' cards (design "Prof Lizy / Albert Stein / Aaron"). Their figures are the
 * design's showcase values, not the user's statistics.
 *
 * @type {Record<string, Prof>}
 */
export const PROFS = {
  lizy: {
    slug: 'lizy',
    name: 'Lizy',
    get title() {
      return t('profs.lizy.title')
    },
    glyph: '♛︎',
    get kicker() {
      return t('profs.lizy.kicker')
    },
    get tagline() {
      return t('profs.lizy.tagline')
    },
    get traits() {
      return [0, 1, 2].map(i => t(`profs.lizy.traits.${i}`))
    },
    get motto() {
      return t('profs.lizy.motto')
    },
    mottoImage: profImage('lizy-board'),
    full: profImage('lizy-full'),
    bust: profImage('lizy-bust'),
    stats: [0, 1, 2].map(i => ({
      get value() {
        return t(`profs.lizy.stats.${i}.value`)
      },
      get label() {
        return t(`profs.lizy.stats.${i}.label`)
      }
    })),
    specialties: [88, 67, 94, 41].map((pct, i) => ({
      get name() {
        return t(`profs.lizy.specialties.${i}.name`)
      },
      get desc() {
        return t(`profs.lizy.specialties.${i}.desc`)
      },
      pct
    })),
    method: ['lizy-think', 'lizy-cool', 'lizy-joy'].map((image, i) => ({
      image: profImage(image),
      get title() {
        return t(`profs.lizy.method.${i}.title`)
      },
      get desc() {
        return t(`profs.lizy.method.${i}.desc`)
      }
    })),
    cta: {
      get label() {
        return t('profs.lizy.cta')
      },
      module: 'finales'
    },
    colors: {
      bg: '#FF8A3D',
      deep: '#E0601A',
      soft: '#FFEBDD',
      accentInk: '#B84A0E',
      ink: '#1B1530'
    }
  },
  'albert-stein': {
    slug: 'albert-stein',
    name: 'Albert Stein',
    get title() {
      return t('profs.albert.title')
    },
    glyph: '♝︎',
    get kicker() {
      return t('profs.albert.kicker')
    },
    get tagline() {
      return t('profs.albert.tagline')
    },
    get traits() {
      return [0, 1, 2].map(i => t(`profs.albert.traits.${i}`))
    },
    get motto() {
      return t('profs.albert.motto')
    },
    mottoImage: profImage('albert-idea'),
    full: profImage('albert-full'),
    bust: profImage('albert-bust'),
    stats: [0, 1, 2].map(i => ({
      get value() {
        return t(`profs.albert.stats.${i}.value`)
      },
      get label() {
        return t(`profs.albert.stats.${i}.label`)
      }
    })),
    specialties: [82, 74, 61, 45].map((pct, i) => ({
      get name() {
        return t(`profs.albert.specialties.${i}.name`)
      },
      get desc() {
        return t(`profs.albert.specialties.${i}.desc`)
      },
      pct
    })),
    method: ['albert-think', 'albert-board', 'albert-joy'].map((image, i) => ({
      image: profImage(image),
      get title() {
        return t(`profs.albert.method.${i}.title`)
      },
      get desc() {
        return t(`profs.albert.method.${i}.desc`)
      }
    })),
    cta: {
      get label() {
        return t('profs.albert.cta')
      },
      module: 'puzzles'
    },
    colors: {
      bg: '#FF6FAE',
      deep: '#E03C86',
      soft: '#FFE6F1',
      accentInk: '#C02670',
      ink: '#1B1530'
    }
  },
  aaron: {
    slug: 'aaron',
    name: 'Aaron',
    get title() {
      return t('profs.aaron.title')
    },
    glyph: '♚︎',
    get kicker() {
      return t('profs.aaron.kicker')
    },
    get tagline() {
      return t('profs.aaron.tagline')
    },
    get traits() {
      return [0, 1, 2].map(i => t(`profs.aaron.traits.${i}`))
    },
    get motto() {
      return t('profs.aaron.motto')
    },
    mottoImage: profImage('aaron-wink'),
    full: profImage('aaron-full'),
    bust: profImage('aaron-bust'),
    stats: [0, 1, 2].map(i => ({
      get value() {
        return t(`profs.aaron.stats.${i}.value`)
      },
      get label() {
        return t(`profs.aaron.stats.${i}.label`)
      }
    })),
    specialties: [72, 58, 49, 63].map((pct, i) => ({
      get name() {
        return t(`profs.aaron.specialties.${i}.name`)
      },
      get desc() {
        return t(`profs.aaron.specialties.${i}.desc`)
      },
      pct
    })),
    method: ['aaron-cross', 'aaron-sing', 'aaron-laugh'].map((image, i) => ({
      image: profImage(image),
      get title() {
        return t(`profs.aaron.method.${i}.title`)
      },
      get desc() {
        return t(`profs.aaron.method.${i}.desc`)
      }
    })),
    cta: {
      get label() {
        return t('profs.aaron.cta')
      },
      module: 'repertoire'
    },
    colors: {
      bg: '#4FB2FF',
      deep: '#1C86E0',
      soft: '#E2F2FF',
      accentInk: '#0F6BBA',
      ink: '#1B1530'
    }
  }
}

/** URL slugs of the professors, for the route's `requirements`-like check. */
export const PROF_SLUGS = Object.keys(PROFS)

/**
 * The professors' expressions (src/assets/profs): neutral while playing, pleased after a success,
 * thoughtful after a miss.
 *
 * @type {Record<string, {neutral: string, happy: string, sad: string}>}
 */
export const PROF_MOODS = {
  'albert-stein': {
    neutral: 'albert-face',
    happy: 'albert-joy',
    sad: 'albert-think'
  },
  aaron: { neutral: 'aaron-bust', happy: 'aaron-laugh', sad: 'aaron-wow' },
  lizy: { neutral: 'lizy-cool', happy: 'lizy-joy', sad: 'lizy-think' }
}

/**
 * A professor's picture for a mood, '' when there is none (no card, or a missing file).
 *
 * @param {string|null} slug
 * @param {'neutral'|'happy'|'sad'} mood
 * @returns {string}
 */
export function moodImage(slug, mood) {
  const moods = slug ? PROF_MOODS[slug] : null
  return moods ? profImage(moods[mood]) : ''
}
