import { t } from '@/i18n'

/**
 * The mind map (design "Carte mentale"): six cognitive capacities a chess player trains, each one
 * a zone of the brain and a card, with the training modules it relies on. Texts are messages
 * (mindMap.capacities.<key>, mindMap.modules.<key>); the chapters and their activities will come
 * later. Pure, so that it can be tested alone.
 *
 * @typedef {object} MindModule a training module, as the map names it
 * @property {string} key
 * @property {string} to the module's page
 *
 * @typedef {object} Capacity
 * @property {string} key
 * @property {number} num 1 to 6, the number on the brain
 * @property {string} color the zone's colour
 * @property {string} deep a darker shade (shadows)
 * @property {string} ink text on `color`
 * @property {string[]} primary modules mainly used (keys of MODULES)
 * @property {string[]} associated modules that help
 * @property {string} region the zone's outline, in the brain image's coordinates
 * @property {[number, number]} badge where its number sits
 */

/** The modules the map points to, and their pages. */
export const MODULES = {
  puzzles: '/puzzle',
  streak: '/streak',
  rush: '/puzzle',
  woodpecker: '/woodpecker',
  repertoire: '/repertoire',
  coordinates: '/coordinates',
  notation: '/notation',
  blindfold: '/blindfold',
  recall: '/reconstitution',
  evaluation: '/evaluation',
  parcours: '/parcours',
  free: '/session/new'
}

/** The brain image's size (assets/mindMap/brain-pink.png): the zones are drawn on it. */
export const IMAGE = { width: 1374, height: 1145 }

/** The part of the image shown: [x, y, width, height]. */
export const VIEW_BOX = [90, 90, 1214, 1014]

/** The brain's outline: the zones are clipped to it. */
export const OUTLINE = closedCurve([
  [112, 560],
  [150, 400],
  [260, 262],
  [460, 150],
  [700, 110],
  [920, 140],
  [1080, 222],
  [1200, 340],
  [1272, 480],
  [1284, 640],
  [1244, 780],
  [1190, 850],
  [1180, 960],
  [1090, 1012],
  [935, 1012],
  [925, 1084],
  [830, 1084],
  [700, 905],
  [560, 866],
  [400, 806],
  [230, 726],
  [132, 646]
])

/** @type {Capacity[]} */
export const CAPACITIES = [
  {
    key: 'calculation',
    num: 1,
    color: '#FF6FAE',
    deep: '#C02670',
    ink: '#1B1530',
    primary: ['streak'],
    associated: ['blindfold', 'woodpecker', 'puzzles'],
    region:
      'M480,0 L720,0 L720,100 C690,300 660,440 650,598 C600,590 520,600 440,700 C450,480 470,300 480,100 Z',
    badge: [570, 340]
  },
  {
    key: 'visualization',
    num: 2,
    color: '#4FC3F7',
    deep: '#1E88C8',
    ink: '#1B1530',
    primary: ['coordinates', 'blindfold', 'recall'],
    associated: ['notation', 'repertoire'],
    region:
      'M720,0 L1000,0 L1000,140 C980,300 1000,420 1040,548 C900,570 800,590 650,598 C660,440 690,300 720,100 Z',
    badge: [850, 360]
  },
  {
    key: 'patterns',
    num: 3,
    color: '#C6F432',
    deep: '#8FB800',
    ink: '#1B1530',
    primary: ['woodpecker', 'puzzles'],
    associated: ['repertoire', 'streak', 'evaluation'],
    region:
      'M440,700 C520,600 600,590 650,598 C800,590 900,570 1040,548 C1100,545 1180,550 1260,560 L1260,820 C1150,822 1000,842 860,880 C760,900 640,880 560,862 L440,822 Z',
    badge: [860, 700]
  },
  {
    key: 'speed',
    num: 4,
    color: '#FFD43B',
    deep: '#C99A00',
    ink: '#1B1530',
    primary: ['rush'],
    associated: ['coordinates', 'puzzles', 'streak'],
    region:
      'M1000,0 L1374,0 L1374,1145 L1260,1145 L1260,560 C1180,550 1100,545 1040,548 C1000,420 980,300 1000,140 Z',
    badge: [1150, 430]
  },
  {
    key: 'strategy',
    num: 5,
    color: '#8B6BFF',
    deep: '#5A3BE0',
    ink: '#FFFFFF',
    primary: ['evaluation'],
    associated: ['parcours'],
    region: 'M0,0 L480,0 L480,100 C470,300 450,480 440,700 L440,1145 L0,1145 Z',
    badge: [280, 520]
  },
  {
    key: 'composure',
    num: 6,
    color: '#FF8A3D',
    deep: '#C2531A',
    ink: '#1B1530',
    primary: ['free'],
    associated: ['streak', 'rush'],
    region:
      'M780,872 C850,826 1000,822 1160,842 C1210,900 1160,994 1050,1012 C950,1022 820,994 780,924 Z',
    badge: [990, 925]
  }
]

/** The cards on each side of the brain, as in the design. */
export const LEFT = ['calculation', 'strategy', 'patterns']
export const RIGHT = ['visualization', 'speed', 'composure']

/** @param {string} key */
export function capacity(key) {
  return CAPACITIES.find(c => c.key === key) ?? null
}

/** @param {string} key */
export function capacityName(key) {
  return t(`mindMap.capacities.${key}.name`)
}

/** @param {string} key */
export function capacityShort(key) {
  return t(`mindMap.capacities.${key}.short`)
}

/** @param {string} key */
export function capacityDesc(key) {
  return t(`mindMap.capacities.${key}.desc`)
}

/** @param {string} key a module's */
export function moduleName(key) {
  return t(`mindMap.modules.${key}.name`)
}

/** @param {string} key a module's */
export function moduleWhy(key) {
  return t(`mindMap.modules.${key}.why`)
}

/**
 * The modules of a capacity, primary first, with their role and page.
 *
 * @param {Capacity} c
 * @returns {{key: string, primary: boolean, to: string}[]}
 */
export function capacityModules(c) {
  return [
    ...c.primary.map(key => ({ key, primary: true, to: MODULES[key] })),
    ...c.associated.map(key => ({ key, primary: false, to: MODULES[key] }))
  ]
}

/**
 * A CSS mask showing one zone of the image (an SVG of its outline, stretched on the image).
 *
 * @param {string} path
 */
export function zoneMask(path) {
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${IMAGE.width} ${IMAGE.height}' preserveAspectRatio='none'><path d='${path}'/></svg>`
  return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`
}

/**
 * A smooth closed curve through points (Catmull-Rom as cubic Béziers), as an SVG path.
 *
 * @param {[number, number][]} points
 */
export function closedCurve(points) {
  const n = points.length
  const p = (/** @type {[number, number]} */ q) =>
    `${q[0].toFixed(1)},${q[1].toFixed(1)}`
  let d = `M${p(points[0])}`
  for (let i = 0; i < n; i++) {
    const p0 = points[(i - 1 + n) % n]
    const p1 = points[i]
    const p2 = points[(i + 1) % n]
    const p3 = points[(i + 2) % n]
    /** @type {[number, number]} */
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6]
    /** @type {[number, number]} */
    const c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6]
    d += ` C${p(c1)} ${p(c2)} ${p(p2)}`
  }
  return `${d} Z`
}
