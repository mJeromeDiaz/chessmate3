import { profImage } from '@/utils/prof/images'

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
    title: 'La Reine',
    glyph: '♛︎',
    kicker: 'PROF · FINALES',
    tagline: 'La championne des finales, et une stratège qui voit loin.',
    traits: ['Finales', 'Stratégie', 'Planification'],
    motto:
      'Une finale se gagne avant même d’y arriver : choisis bien tes échanges.',
    mottoImage: profImage('lizy-board'),
    full: profImage('lizy-full'),
    bust: profImage('lizy-bust'),
    stats: [
      { value: '86', label: 'Finales maîtrisées' },
      { value: '1 690', label: 'Elo finales' },
      { value: '5 j', label: 'Série en cours' }
    ],
    specialties: [
      {
        name: 'Finales de pions',
        desc: 'Opposition, carré, pions passés',
        pct: 88
      },
      {
        name: 'Finales de tours',
        desc: 'Lucena, Philidor, tour active',
        pct: 67
      },
      { name: 'Mats élémentaires', desc: 'Dame, tour, deux fous', pct: 94 },
      {
        name: 'Pièces mineures',
        desc: 'Bon et mauvais fou, cavalier',
        pct: 41
      }
    ],
    method: [
      {
        image: profImage('lizy-think'),
        title: 'Planifier',
        desc: 'Évaluer la structure et choisir où mener la partie.'
      },
      {
        image: profImage('lizy-cool'),
        title: 'Simplifier',
        desc: 'Échanger au bon moment vers une finale gagnante.'
      },
      {
        image: profImage('lizy-joy'),
        title: 'Conclure',
        desc: 'Appliquer la technique sans laisser de chance.'
      }
    ],
    cta: { label: 'Ajouter un module Finales', module: 'finales' },
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
    title: 'Le Fou',
    glyph: '♝︎',
    kicker: 'PROF · PUZZLES & WOODPECKER',
    tagline: 'Une vision de génie pour des coups en diagonale.',
    traits: ['Calcul', 'Stratégie', 'Patience'],
    motto:
      'Avant de jouer, regarde chaque diagonale ouverte. La solution s’y cache souvent.',
    mottoImage: profImage('albert-idea'),
    full: profImage('albert-full'),
    bust: profImage('albert-bust'),
    stats: [
      { value: '1 284', label: 'Puzzles résolus' },
      { value: '1 742', label: 'Elo tactique' },
      { value: '12 j', label: 'Série en cours' }
    ],
    specialties: [
      {
        name: 'Enfilade',
        desc: 'Attaquer deux pièces alignées',
        pct: 82
      },
      {
        name: 'Clouage',
        desc: 'Immobiliser une pièce devant le roi',
        pct: 74
      },
      {
        name: 'Attaque à la découverte',
        desc: 'Démasquer une pièce à longue portée',
        pct: 61
      },
      {
        name: 'Sacrifice sur f7',
        desc: 'Ouvrir la diagonale vers le roi',
        pct: 45
      }
    ],
    method: [
      {
        image: profImage('albert-think'),
        title: 'Observer',
        desc: 'Repérer échecs, prises et menaces avant de calculer.'
      },
      {
        image: profImage('albert-board'),
        title: 'Calculer',
        desc: 'Suivre chaque variante forcée jusqu’au bout.'
      },
      {
        image: profImage('albert-joy'),
        title: 'Ancrer',
        desc: 'Revoir les motifs ratés pour qu’ils deviennent réflexes.'
      }
    ],
    cta: { label: 'Ajouter un module Puzzles', module: 'puzzles' },
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
    title: 'Le Roi',
    glyph: '♚︎',
    kicker: 'PROF · RÉPERTOIRE & ÉVALUATION',
    tagline: 'Des leçons qui ont du rythme : attaque, confiance et style.',
    traits: ['Attaque', 'Tactique', 'Confiance'],
    motto:
      'Prends l’initiative et ne la rends jamais. Le roi adverse, c’est ta scène.',
    mottoImage: profImage('aaron-wink'),
    full: profImage('aaron-full'),
    bust: profImage('aaron-bust'),
    stats: [
      { value: '214', label: 'Attaques réussies' },
      { value: '1 810', label: 'Elo attaque' },
      { value: '3 j', label: 'Série en cours' }
    ],
    specialties: [
      {
        name: 'Attaque sur le roi',
        desc: 'Ouvrir les lignes vers le roque',
        pct: 72
      },
      {
        name: 'Gambits',
        desc: 'Sacrifier un pion pour l’initiative',
        pct: 58
      },
      { name: 'Sacrifices', desc: 'Donner du matériel pour mater', pct: 49 },
      {
        name: 'Jeu d’initiative',
        desc: 'Garder les menaces coup après coup',
        pct: 63
      }
    ],
    method: [
      {
        image: profImage('aaron-cross'),
        title: 'Oser',
        desc: 'Repérer le moment où l’attaque devient possible.'
      },
      {
        image: profImage('aaron-sing'),
        title: 'Garder le rythme',
        desc: 'Enchaîner les coups forcés sans laisser respirer.'
      },
      {
        image: profImage('aaron-laugh'),
        title: 'Finir en beauté',
        desc: 'Convertir l’attaque en mat ou en gain décisif.'
      }
    ],
    cta: { label: 'Créer une session avec Aaron', module: 'repertoire' },
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
