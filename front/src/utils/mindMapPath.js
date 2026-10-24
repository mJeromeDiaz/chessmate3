import { t } from '@/i18n'

/**
 * The mind map's paths (docs/MIND_MAP.md): what the API sends (chapters, sections, steps and their
 * status) made ready to show, and their texts (messages `mindMapPath.<capacity>.*`, the steps by
 * their key). Pure, so that it can be tested alone.
 *
 * @typedef {object} PathChest
 * @property {string} id
 * @property {string} step
 * @property {number} crystals
 * @property {boolean} opened
 *
 * @typedef {object} PathStep
 * @property {string} key stable, e.g. "2-1-3", "1-chest", "2-boss"
 * @property {string|null} number what the path shows, e.g. "2.1.3"
 * @property {'exercise'|'chest'|'mini_boss'|'hidden_boss'|'boss'} kind
 * @property {'locked'|'open'|'soon'|'hidden'|'done'|'skipped'} status
 * @property {number} passes
 * @property {number} required
 * @property {number} crystals
 * @property {number} xp
 * @property {string|null} character
 * @property {string[]} badges
 * @property {boolean} skippable
 * @property {PathChest|null} chest
 *
 * @typedef {object} PathSection
 * @property {string} key
 * @property {string|null} number
 * @property {'locked'|'open'|'done'} status
 * @property {PathStep[]} steps
 * @property {PathStep[]} miniBosses
 * @property {PathStep[]} hidden
 *
 * @typedef {object} PathChapter
 * @property {string} key
 * @property {number} number
 * @property {'locked'|'open'|'done'} status
 * @property {PathSection[]} sections
 * @property {PathStep} boss
 *
 * @typedef {object} MindPath
 * @property {string} capacity
 * @property {PathChapter[]} chapters
 * @property {{badge: string, step: string, earnedAt: string}[]} badges
 * @property {PathChest[]} unopenedChests
 *
 * @typedef {object} PathNode a step as the path draws it
 * @property {PathStep} step
 * @property {PathSection} section
 * @property {boolean} sectionStart the first node of a numbered section (its title above it)
 * @property {number} index its place in its section (where the path winds)
 */

/** The capacities of the map that have a path: the map's key → the API's. */
export const PATH_CAPACITIES = {
  visualization: 'visualisation',
  calculation: 'calculation'
}

/** @param {string} key a capacity of the map */
export function apiCapacity(key) {
  return /** @type {Record<string, string>} */ (PATH_CAPACITIES)[key] ?? null
}

/** @param {string} key a capacity of the map */
export function hasPath(key) {
  return apiCapacity(key) !== null
}

/** The kinds fought with lives. */
export const BOSS_KINDS = ['mini_boss', 'hidden_boss', 'boss']

/** @param {string} kind */
export function isBoss(kind) {
  return BOSS_KINDS.includes(kind)
}

/** A boss's lives (docs/MIND_MAP.md: 3 failures and the boss wins). */
export const BOSS_LIVES = 3

/**
 * A step's key as a message key: "2-1-3" → "s2_1_3".
 *
 * @param {string} key
 */
export function messageKey(key) {
  return `s${key.replaceAll('-', '_')}`
}

/**
 * @param {string} capacity the API's
 * @param {string} key a step's
 */
export function stepTitle(capacity, key) {
  return t(`mindMapPath.${capacity}.steps.${messageKey(key)}.title`)
}

/**
 * @param {string} capacity the API's
 * @param {string} key a step's
 */
export function stepDesc(capacity, key) {
  return t(`mindMapPath.${capacity}.steps.${messageKey(key)}.desc`)
}

/**
 * @param {string} capacity the API's
 * @param {string} key a chapter's ("c2")
 */
export function chapterTitle(capacity, key) {
  return t(`mindMapPath.${capacity}.chapters.${key}`)
}

/**
 * The title of a numbered section, '' for the one of a chapter without sections.
 *
 * @param {string} capacity the API's
 * @param {PathSection} section
 */
export function sectionTitle(capacity, section) {
  return section.number
    ? t(`mindMapPath.${capacity}.sections.${messageKey(section.key)}`)
    : ''
}

/** The characters that have words (and soon their own picture). */
export const CHARACTERS = [
  'jongleur',
  'cyclope',
  'gargouille',
  'fantome',
  'cameleon',
  'sith',
  'sablier',
  'flash',
  'metronome',
  'compteur',
  'avocat'
]

/**
 * A boss's name: its character's, else its kind's ("Mini-boss").
 *
 * @param {PathStep} step
 */
export function bossName(step) {
  return step.character && CHARACTERS.includes(step.character)
    ? t(`mindMapPath.characters.${step.character}.name`)
    : t(`mindMapPath.kinds.${step.kind}`)
}

/**
 * A character's line: `tagline` (who it is), `taunt` (before the fight), `defeat` (beaten), `win`
 * (it won). Without character, the kind's.
 *
 * @param {PathStep} step
 * @param {'tagline'|'taunt'|'defeat'|'win'} line
 */
export function bossLine(step, line) {
  return step.character && CHARACTERS.includes(step.character)
    ? t(`mindMapPath.characters.${step.character}.${line}`)
    : t(`mindMapPath.generic.${line}`)
}

/**
 * The bosses' pictures: a character sheet (src/assets/mindMap/boss/<character>.png, as
 * cyclope.png), or a folder of expressions (src/assets/mindMap/boss/<character>/<character>-<mood
 * in French>.png, and <character>-pose.png standing). Until each one has its own, the cyclops'
 * sheet stands in for all.
 */
const BOSS_URLS = import.meta.glob('@/assets/mindMap/boss/**/*.png', {
  eager: true,
  import: 'default',
  query: '?url'
})

/** @param {string} name a file's name without extension */
function bossFile(name) {
  const key = Object.keys(BOSS_URLS).find(path => path.endsWith(`/${name}.png`))
  return key ? /** @type {string} */ (BOSS_URLS[key]) : ''
}

/**
 * The expressions of a boss in a fight, and their files' names (Claude Design « Fiches
 * miniboss »).
 */
export const MOODS = {
  joy: 'joie',
  surprise: 'surprise',
  doubt: 'doute',
  sadness: 'tristesse',
  pride: 'fierte',
  anger: 'colere',
  focus: 'concentration',
  anxiety: 'angoisse',
  neutral: 'neutre'
}

/** @typedef {keyof typeof MOODS} Mood */

/** The mood that stands for a pose of the sheet, for a boss drawn by expressions. */
export const POSE_MOODS = /** @type {Record<string, Mood>} */ ({
  idle: 'neutral',
  cheer: 'joy',
  angry: 'anger',
  search: 'focus',
  beaten: 'sadness'
})

/**
 * A boss's expression, '' when it has none (it is drawn from a sheet).
 *
 * @param {string|null} character
 * @param {Mood} mood
 */
export function moodImage(character, mood) {
  return character
    ? bossFile(`${character}-${MOODS[mood] ?? MOODS.neutral}`)
    : ''
}

/**
 * A boss standing (its card), '' when it has no such picture.
 *
 * @param {string|null} character
 */
export function standingImage(character) {
  return character ? bossFile(`${character}-pose`) : ''
}

/** @param {string|null} character */
export function bossImage(character) {
  return (character ? bossFile(character) : '') || bossFile('cyclope')
}

/**
 * The poses of a character sheet (1536 × 1024, as cyclope.png): [x, y, width, height] in pixels.
 * `idle` before the fight, `angry` while it lasts, `cheer` when it wins, `beaten` when it loses.
 */
export const SHEET = { width: 1536, height: 1024 }
export const POSES = {
  idle: [20, 70, 780, 940],
  cheer: [720, 70, 400, 480],
  angry: [1095, 110, 440, 440],
  search: [690, 580, 380, 400],
  beaten: [1075, 580, 400, 400]
}

/**
 * The crop of a pose: the box's aspect ratio and the picture's place in it (percentages of the
 * box), so that only that pose shows.
 *
 * @param {keyof typeof POSES} pose
 */
export function poseCrop(pose) {
  const [x, y, w, h] = POSES[pose] ?? POSES.idle
  return {
    aspectRatio: `${w} / ${h}`,
    width: `${(SHEET.width / w) * 100}%`,
    left: `${(-x / w) * 100}%`,
    top: `${(-y / h) * 100}%`
  }
}

/**
 * The nodes of a chapter in the order of the path: each section's exercises and chests, its
 * mini-bosses, its hidden bosses; then the chapter's boss (in its own last section).
 *
 * @param {PathChapter} chapter
 * @returns {PathNode[]}
 */
export function chapterNodes(chapter) {
  /** @type {PathNode[]} */
  const nodes = []
  for (const section of chapter.sections) {
    const steps = [...section.steps, ...section.miniBosses, ...section.hidden]
    steps.forEach((step, i) =>
      nodes.push({
        step,
        section,
        sectionStart: i === 0 && !!section.number,
        index: i
      })
    )
  }
  const last = chapter.sections[chapter.sections.length - 1]
  nodes.push({
    step: chapter.boss,
    section: last,
    sectionStart: false,
    index: 0
  })
  return nodes
}

/**
 * The step to play next: the first open one of the path (an exercise, a mini-boss, a boss), else
 * the first « soon » one; a hidden boss never (it waits aside).
 *
 * @param {MindPath} path
 * @returns {PathStep|null}
 */
export function currentStep(path) {
  /** @type {PathStep|null} */
  let soon = null
  for (const chapter of path.chapters) {
    for (const { step } of chapterNodes(chapter)) {
      if (step.kind === 'hidden_boss' || step.kind === 'chest') continue
      if (step.status === 'open') return step
      if (step.status === 'soon' && !soon) soon = step
    }
  }
  return soon
}

/**
 * The chapter where the player is: the first one not done, else the last.
 *
 * @param {MindPath} path
 */
export function currentChapter(path) {
  return (
    path.chapters.find(c => c.status !== 'done') ??
    path.chapters[path.chapters.length - 1] ??
    null
  )
}

/**
 * A chapter's progress: its exercises and bosses passed or skipped, out of all of them (chests and
 * hidden bosses aside).
 *
 * @param {PathChapter} chapter
 */
export function chapterProgress(chapter) {
  const counted = chapterNodes(chapter)
    .map(n => n.step)
    .filter(s => s.kind !== 'chest' && s.kind !== 'hidden_boss')
  return {
    done: counted.filter(s => s.status === 'done' || s.status === 'skipped')
      .length,
    total: counted.length
  }
}

/**
 * A whole path's progress: its chapters' summed (exercises and bosses passed or skipped).
 *
 * @param {MindPath} path
 */
export function pathProgress(path) {
  return path.chapters
    .map(chapterProgress)
    .reduce(
      (sum, p) => ({ done: sum.done + p.done, total: sum.total + p.total }),
      { done: 0, total: 0 }
    )
}

/**
 * A boss's face on the path (design « Parcours zone »): proud when it waits for the player,
 * sad once beaten, neutral otherwise.
 *
 * @param {PathStep} step
 * @returns {Mood}
 */
export function pathMood(step) {
  if (step.status === 'done') return 'sadness'
  return step.status === 'open' ? 'pride' : 'neutral'
}

/**
 * The path winds (design « Parcours zone »): each node's offset from the middle in pixels, by its
 * place in its section (scaled down on a phone by the page).
 */
const WIND = [-50, 30, 90, 30, -50, 0]

/**
 * A node's offset from the middle, in pixels: bosses stand in the middle.
 *
 * @param {PathNode} node
 */
export function nodeOffset(node) {
  return isBoss(node.step.kind) ? 0 : WIND[node.index % WIND.length]
}

/**
 * What a run's verdict says (`mindMapPath.outcome.<key>`).
 *
 * @param {{kind: string, outcome: string, completed: boolean}} outcome
 * @returns {'pending'|'validated'|'pass'|'failed'|'victory'|'defeat'|'practice'}
 */
export function outcomeKey(outcome) {
  if (outcome.outcome === 'pending') return 'pending'
  const boss = isBoss(outcome.kind)
  if (outcome.outcome === 'failed') return boss ? 'defeat' : 'failed'
  if (!outcome.completed) return boss ? 'practice' : 'pass'
  return boss ? 'victory' : 'validated'
}

/**
 * Lives a boss run left: 3 minus its failures (never below 0). The failures are its items failed,
 * or what its module counts (a recall's pieces wrong or missing), as the API gives them.
 *
 * @param {{itemCount: number, successCount: number, failures?: number, lives: number|null}} outcome
 */
export function livesLeft(outcome) {
  const lives = outcome.lives ?? BOSS_LIVES
  const failures = outcome.failures ?? outcome.itemCount - outcome.successCount
  return Math.max(0, lives - failures)
}

/**
 * Why the API refused to play or skip a step, as the player reads it.
 *
 * @param {unknown} error
 * @returns {string} '' when it is not a step's refusal
 */
export function refusalText(error) {
  const detail = String(
    /** @type {any} */ (error)?.response?.data?.detail ?? ''
  )
  if (detail.includes('no_repertoire'))
    return t('mindMapPath.refusals.noRepertoire')
  if (detail.startsWith('step_'))
    return t(`mindMapPath.refusals.${detail.slice(5)}`)
  if (/** @type {any} */ (error)?.response?.status === 409)
    return t('mindMapPath.refusals.runInProgress')
  return ''
}

/**
 * A badge's name: a chapter's or a section's by its number, a boss's by its message.
 *
 * @param {string} key e.g. "chapter_2", "section_2_1", "hidden_5_1_2", "boss_cyclope"
 */
export function badgeName(key) {
  const chapter = /^chapter_(\d+)$/.exec(key)
  if (chapter) return t('mindMapPath.badges.chapter', { n: chapter[1] })
  const section = /^section_([\d_]+)$/.exec(key)
  if (section)
    return t('mindMapPath.badges.section', {
      n: section[1].replaceAll('_', '.')
    })
  const hidden = /^hidden_([\d_]+)$/.exec(key)
  if (hidden)
    return t('mindMapPath.badges.hidden', { n: hidden[1].replaceAll('_', '.') })
  return t(`mindMapPath.badges.${key}`)
}

/**
 * A path's chest as the treasure chest screen takes a reward (`TreasureChest`): its crystals, the
 * best tier's last title.
 *
 * @param {PathChest} chest
 */
export function chestReward(chest) {
  return {
    id: chest.id,
    crowns: chest.crystals,
    paidMinutes: 0,
    tier: 'perfect',
    capped: false,
    opened: chest.opened
  }
}

/**
 * The line under a path chest's amount: « Carte mentale · Le Jongleur ».
 *
 * @param {string} capacity the API's
 * @param {PathChest} chest
 */
export function chestNote(capacity, chest) {
  return t('mindMapPath.chestNote', { title: stepTitle(capacity, chest.step) })
}

/**
 * The map's key of an API capacity (`visualisation` → `visualization`), for the path's page.
 *
 * @param {string} capacity the API's
 */
export function mapKey(capacity) {
  return (
    Object.entries(PATH_CAPACITIES).find(([, api]) => api === capacity)?.[0] ??
    null
  )
}

/**
 * The page of a path.
 *
 * @param {string} capacity the API's
 */
export function pathPage(capacity) {
  const key = mapKey(capacity)
  return key ? `/cerveau/${key}` : '/cerveau'
}

/**
 * A step of the path by its key.
 *
 * @param {MindPath} path
 * @param {string} key
 * @returns {PathStep|null}
 */
export function findStep(path, key) {
  for (const chapter of path.chapters)
    for (const { step } of chapterNodes(chapter))
      if (step.key === key) return step
  return null
}

/**
 * Where a step stands: its chapter and its section.
 *
 * @param {MindPath} path
 * @param {string} key
 * @returns {{chapter: PathChapter, section: PathSection}|null}
 */
export function findPlace(path, key) {
  for (const chapter of path.chapters)
    for (const { step, section } of chapterNodes(chapter))
      if (step.key === key) return { chapter, section }
  return null
}
