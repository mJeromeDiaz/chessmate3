import { profImage } from '@/utils/prof/images'

/**
 * The design's gamification, shown as a showcase with its static values (levels, XP, streak,
 * weekly quest, trophies): none of it is computed yet. The page labels each of these blocks
 * "Aperçu"; the gamification phase replaces this file with real data.
 */
export const SHOWCASE = {
  level: 12,
  rank: 'Tacticien',
  nextRank: 'Stratège',
  xp: 2340,
  xpMax: 3000,
  streak: 12,
  bestStreak: 21,
  mascot: profImage('aaron-laugh'),
  quest: {
    prof: 'Lizy',
    image: profImage('lizy-think'),
    text: 'Réussis 5 finales de tours sans aide.',
    done: 3,
    goal: 5,
    reward: 150
  },
  /** "Niv." of each catalogue module. */
  moduleLevels: {
    puzzles: 7,
    woodpecker: 4,
    finales: 5,
    repertoire: 6,
    evaluation: 3,
    analyse: 2
  },
  badges: [
    {
      icon: '🔥︎',
      name: 'En feu',
      desc: '7 jours d’affilée',
      bg: '#FFEBDD',
      ink: '#B84A0E',
      unlocked: true
    },
    {
      icon: '♝︎',
      name: 'Pivert',
      desc: 'Cycle Woodpecker fini',
      bg: '#F0FBCF',
      ink: '#5A7A00',
      unlocked: true
    },
    {
      icon: '♚︎',
      name: 'Lucena',
      desc: 'Finale de tour maîtrisée',
      bg: '#FFEBDD',
      ink: '#B84A0E',
      unlocked: true
    },
    {
      icon: '♞︎',
      name: 'Fourchette d’or',
      desc: '100 fourchettes',
      bg: '#FFE6F1',
      ink: '#C02670',
      unlocked: true
    },
    {
      icon: '♜︎',
      name: 'Mémoire d’acier',
      desc: '95 % de rétention',
      bg: '#E2F2FF',
      ink: '#0F6BBA',
      unlocked: false
    },
    {
      icon: '♘︎',
      name: 'Les yeux fermés',
      desc: '1re partie à l’aveugle',
      bg: '#E7E4F2',
      ink: '#4A3D86',
      unlocked: false
    }
  ]
}
