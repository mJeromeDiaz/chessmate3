<template>
  <section class="cm-card path-card" data-testid="dashboard-path">
    <div class="path-card__head">
      <h2 class="cm-card__title">{{ $t('dashboard.path.title') }}</h2>
      <router-link to="/cerveau" class="path-card__all">{{
        $t('dashboard.path.all')
      }}</router-link>
    </div>

    <div
      v-if="proposed && track"
      class="path-card__hero"
      :style="heroColours"
      :data-capacity="proposed.capacity"
      data-testid="dashboard-path-current"
    >
      <div v-if="track.boss" class="path-card__boss" aria-hidden="true">
        <img :src="bossPicture" alt="" />
      </div>
      <div v-if="track.boss" class="path-card__boss-text">
        <span class="path-card__kicker path-card__kicker--boss">{{
          $t('dashboard.path.nextBoss')
        }}</span>
        <span class="path-card__boss-name">{{ bossName(track.boss) }}</span>
        <span v-if="track.boss.crystals" class="path-card__crystals"
          ><CrystalGem :size="16" />+{{ track.boss.crystals }}</span
        >
      </div>

      <div class="path-card__text">
        <span class="path-card__kicker">{{ chapterLine }}</span>
        <span class="path-card__name">{{
          capacityName(mapKey(proposed.capacity))
        }}</span>
        <span class="path-card__step">{{
          $t('dashboard.path.step', progress)
        }}</span>
      </div>

      <!-- The steps wind on a horizontal curve, as on the path's page. -->
      <div class="path-card__track" :aria-label="$t('dashboard.path.track')">
        <svg
          class="path-card__curve"
          :viewBox="`0 0 100 ${TRACK_HEIGHT}`"
          preserveAspectRatio="none"
          aria-hidden="true"
        >
          <path class="path-card__curve-rest" :d="curve.all" />
          <path class="path-card__curve-done" :d="curve.done" />
        </svg>
        <ol class="path-card__nodes">
          <li
            v-for="node in placed"
            :key="node.key"
            class="path-card__node"
            :class="`path-card__node--${node.role}`"
            :style="{ left: `${node.x}%`, top: `${node.y}px` }"
            :title="node.title"
          >
            <span
              v-if="node.role.startsWith('current')"
              class="path-card__here"
              >{{ $t('dashboard.path.here') }}</span
            >
            <span v-if="node.role === 'gap'" class="path-card__gap">···</span>
            <span v-else class="path-card__dot">{{ node.glyph }}</span>
          </li>
        </ol>
      </div>

      <div class="path-card__foot">
        <router-link
          :to="pathPage(proposed.capacity)"
          class="path-card__go"
          data-testid="dashboard-path-continue"
          >{{
            started ? $t('dashboard.path.continue') : $t('dashboard.path.start')
          }}</router-link
        >
        <span class="path-card__next">{{
          $t('dashboard.path.now', {
            title: stepTitle(proposed.capacity, track.current.key)
          })
        }}</span>
      </div>
    </div>

    <div class="path-card__others">
      <component
        :is="other.to ? 'router-link' : 'div'"
        v-for="other in others"
        :key="other.key"
        :to="other.to ?? undefined"
        class="path-card__other"
        :class="{ 'path-card__other--soon': !other.to }"
        data-testid="dashboard-path-other"
      >
        <span
          class="path-card__ring"
          :style="{
            background: `conic-gradient(${other.color} ${other.percent}%, var(--cm-subtle) 0)`
          }"
          ><span
            class="path-card__tile"
            :style="{ background: other.color, color: other.ink }"
            >{{ other.glyph }}</span
          ></span
        >
        <span class="path-card__other-name">{{ other.name }}</span>
        <span class="path-card__other-state">{{ other.state }}</span>
      </component>
    </div>
  </section>
</template>

<script setup>
/**
 * « Parcours » (design « Dashboard v2 », docs/DASHBOARD.md): the brain's path played last, cut
 * short (chosen 2026-10-25: the step before, the current one, the next one, then the next boss),
 * and every capacity of the brain with its progress (« Bientôt » without a path yet).
 */
import { computed } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { useDashboardStore } from '@/stores/dashboard'
import { pathPercent, pathTrack, proposedPath } from '@/utils/dashboard/home'
import { CAPACITIES, capacityName } from '@/utils/mindMap'
import {
  apiCapacity,
  bossImage,
  bossName,
  chapterTitle,
  currentChapter,
  isBoss,
  mapKey,
  pathPage,
  pathProgress,
  standingImage,
  stepTitle
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

const store = useDashboardStore()

/** The capacities' glyphs (design « Dashboard v2 »). */
const GLYPHS = /** @type {Record<string, string>} */ ({
  calculation: '∑',
  visualization: '♘︎',
  patterns: '♞︎',
  speed: '⚡︎',
  strategy: '♛︎',
  composure: '♥︎'
})

/** The brain's paths that loaded, in the map's order. */
const brainPaths = computed(() =>
  CAPACITIES.map(c => store.paths[apiCapacity(c.key) ?? ''] ?? null).filter(
    p => p !== null
  )
)
const proposed = computed(() => proposedPath(brainPaths.value))
const track = computed(() =>
  proposed.value ? pathTrack(proposed.value) : null
)
const started = computed(
  () => !!proposed.value && pathProgress(proposed.value).done > 0
)
const progress = computed(() => {
  const { done, total } = proposed.value
    ? pathProgress(proposed.value)
    : { done: 0, total: 0 }
  return { done, total }
})
const chapterLine = computed(() => {
  const chapter = proposed.value ? currentChapter(proposed.value) : null
  return chapter
    ? t('dashboard.path.chapter', {
        n: chapter.number,
        title: chapterTitle(proposed.value.capacity, chapter.key)
      })
    : ''
})
/** The path's own colours, as its page draws them (pink for calculation, blue for visualisation). */
const heroColours = computed(() => {
  const info = CAPACITIES.find(c => c.key === mapKey(proposed.value?.capacity))
  return info
    ? { '--path': info.color, '--path-deep': info.deep, '--path-ink': info.ink }
    : undefined
})
const bossPicture = computed(() => {
  const character = track.value?.boss?.character ?? null
  return standingImage(character) || bossImage(character)
})

/**
 * The track's nodes: before, current, next, a gap when the boss is further, the boss.
 *
 * @type {import('vue').ComputedRef<{key: string, role: string, glyph: string, title: string}[]>}
 */
const nodes = computed(() => {
  const tr = track.value
  const path = proposed.value
  if (!tr || !path) return []
  const node = (step, role) => ({
    key: step.key,
    role,
    glyph:
      role === 'boss'
        ? '♛︎'
        : step.status === 'done' || step.status === 'skipped'
          ? '✓'
          : (step.number ?? ''),
    title: isBoss(step.kind)
      ? bossName(step)
      : stepTitle(path.capacity, step.key)
  })
  const list = []
  if (tr.previous) list.push(node(tr.previous, 'previous'))
  list.push(
    node(tr.current, isBoss(tr.current.kind) ? 'current-boss' : 'current')
  )
  if (tr.next) list.push(node(tr.next, 'next'))
  if (tr.boss && tr.boss !== tr.current) {
    list.push({ key: 'gap', role: 'gap', glyph: '', title: '' })
    list.push(node(tr.boss, 'boss'))
  }
  return list
})

/** The track's height in px, and each node's height on it (a wave, the boss in the middle). */
const TRACK_HEIGHT = 112
const WAVE = [70, 44, 74, 50, 60]

/**
 * The nodes placed on the track: x in % of its width, y in px.
 *
 * @type {import('vue').ComputedRef<{key: string, role: string, glyph: string, title: string, x: number, y: number}[]>}
 */
const placed = computed(() => {
  const list = nodes.value
  const step = list.length > 1 ? 88 / (list.length - 1) : 0
  return list.map((node, i) => ({
    ...node,
    x: list.length > 1 ? 6 + i * step : 50,
    y: node.role === 'boss' ? TRACK_HEIGHT / 2 : WAVE[i % WAVE.length]
  }))
})

/**
 * The curve through the nodes (horizontal tangents at each node), and its part already walked:
 * up to the current step.
 */
const curve = computed(() => {
  const points = placed.value
  /** @param {typeof points} list */
  const draw = list =>
    list
      .map((p, i) => {
        if (i === 0) return `M${p.x},${p.y}`
        const q = list[i - 1]
        const mid = (q.x + p.x) / 2
        return `C${mid},${q.y} ${mid},${p.y} ${p.x},${p.y}`
      })
      .join(' ')
  const here = points.findIndex(p => p.role.startsWith('current'))
  return { all: draw(points), done: draw(points.slice(0, here + 1)) }
})

/** Every capacity but the proposed one: its progress, or « Bientôt ». */
const others = computed(() =>
  CAPACITIES.filter(c => apiCapacity(c.key) !== proposed.value?.capacity).map(
    c => {
      const api = apiCapacity(c.key)
      const path = api ? (store.paths[api] ?? null) : null
      const percent = path ? pathPercent(path) : 0
      return {
        key: c.key,
        color: c.color,
        ink: c.ink,
        glyph: GLYPHS[c.key] ?? '',
        name: capacityName(c.key),
        to: api ? pathPage(api) : null,
        percent,
        state: !api
          ? t('dashboard.path.soon')
          : percent >= 100
            ? t('dashboard.path.finished')
            : percent > 0
              ? t('dashboard.path.percent', { n: percent })
              : t('dashboard.path.discover')
      }
    }
  )
)
</script>

<style scoped lang="scss">
.path-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.path-card__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.path-card__all {
  color: var(--cm-brand);
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;
}

.path-card__hero {
  --path: #8b6bff;
  --path-deep: #5a3be0;
  --path-ink: #fff;
  position: relative;
  overflow: hidden;
  min-height: 206px;
  display: flex;
  flex-direction: column;
  border-radius: 20px;
  background: #231b3a;
  color: #fff;
}

.path-card__boss {
  position: absolute;
  inset: 0 0 0 auto;
  z-index: 2;
  width: 160px;
  mask-image: linear-gradient(90deg, transparent 0, #000 45%);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 50% 12%;
  }

  &::after {
    content: '';
    position: absolute;
    inset: auto 0 0;
    height: 55%;
    background: linear-gradient(
      180deg,
      rgba(35, 27, 58, 0),
      rgba(35, 27, 58, 0.95)
    );
  }

  @media (min-width: $breakpoint-md-min) {
    width: 220px;
  }
}

.path-card__boss-text {
  position: absolute;
  right: 12px;
  bottom: 14px;
  z-index: 3;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
  text-align: right;
}

.path-card__kicker {
  color: color-mix(in srgb, var(--path) 55%, #fff);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;

  &--boss {
    color: #ffa552;
    font-size: 10px;
  }
}

.path-card__boss-name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
  line-height: 1;
}

.path-card__crystals {
  display: flex;
  align-items: center;
  gap: 4px;
  font-weight: 800;
  font-size: 12.5px;
}

.path-card__text {
  position: relative;
  z-index: 3;
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 16px 128px 0 16px;

  @media (min-width: $breakpoint-md-min) {
    padding: 20px 220px 0 22px;
  }
}

.path-card__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  line-height: 1.05;
}

.path-card__step {
  color: var(--path);
  font-size: 12px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

// Before, current, next, ···, boss on a wave: an SVG curve under absolutely placed nodes.
.path-card__track {
  position: relative;
  z-index: 1;
  height: 112px;
  margin: 28px 128px 8px 16px;

  @media (min-width: $breakpoint-md-min) {
    margin: 30px 236px 10px 22px;
  }
}

.path-card__curve {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  overflow: visible;

  path {
    fill: none;
    stroke-width: 6;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
  }
}

.path-card__curve-rest {
  stroke: #3a3450;
}

.path-card__curve-done {
  stroke: var(--path);
}

.path-card__nodes {
  margin: 0;
  padding: 0;
  list-style: none;
}

.path-card__node {
  position: absolute;
  display: flex;
  justify-content: center;
  transform: translate(-50%, -50%);
}

.path-card__dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: #2b2640;
  box-shadow: 0 4px 0 #1f1a2c;
  color: #8a85a0;
  font-size: 15px;
  font-weight: 800;

  .path-card__node--previous & {
    background: var(--path);
    box-shadow: 0 4px 0 var(--path-deep);
    color: var(--path-ink);
  }

  .path-card__node--current &,
  .path-card__node--current-boss & {
    width: 52px;
    height: 52px;
    background: var(--path);
    box-shadow:
      0 4px 0 var(--path-deep),
      0 0 0 3px #231b3a,
      0 0 0 6px color-mix(in srgb, var(--path) 55%, #fff);
    color: var(--path-ink);
    font-size: 17px;
  }

  .path-card__node--boss &,
  .path-card__node--current-boss & {
    width: 56px;
    height: 56px;
    font-size: 24px;
    color: #ffa552;
  }

  @media (min-width: $breakpoint-md-min) {
    width: 50px;
    height: 50px;
    font-size: 17px;

    .path-card__node--current &,
    .path-card__node--current-boss & {
      width: 58px;
      height: 58px;
      font-size: 19px;
    }

    .path-card__node--boss &,
    .path-card__node--current-boss & {
      width: 64px;
      height: 64px;
      font-size: 28px;
    }
  }
}

.path-card__gap {
  padding: 0 4px;
  border-radius: 8px;
  background: #231b3a;
  color: #8a85a0;
  font-weight: 800;
  letter-spacing: 1px;
}

.path-card__here {
  position: absolute;
  bottom: calc(100% + 10px);
  left: 50%;
  transform: translateX(-50%);
  padding: 3px 7px;
  border-radius: 8px;
  background: #fff;
  color: var(--path-deep);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

.path-card__foot {
  position: relative;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: auto;
  padding: 0 128px 16px 16px;

  @media (min-width: $breakpoint-md-min) {
    padding: 0 220px 20px 22px;
  }
}

.path-card__go {
  flex: none;
  display: flex;
  align-items: center;
  height: 40px;
  padding: 0 16px;
  border-radius: 13px;
  background: var(--path);
  box-shadow: 0 3px 0 var(--path-deep);
  color: var(--path-ink);
  font-weight: 800;
  font-size: 13.5px;
  text-decoration: none;
}

.path-card__next {
  min-width: 0;
  color: #b4afc6;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
}

.path-card__others {
  display: flex;
  gap: 8px;
  margin: 0 -4px;
  padding: 0 4px 2px;
  overflow-x: auto;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    overflow: visible;
  }
}

.path-card__other {
  flex: none;
  width: 96px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 7px;
  padding: 12px 6px 10px;
  border-radius: 18px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  text-align: center;
  text-decoration: none;

  @media (min-width: $breakpoint-md-min) {
    width: auto;
    min-width: 0;
  }

  &--soon {
    opacity: 0.75;
  }
}

.path-card__ring {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
}

.path-card__tile {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: var(--cm-subtle);
  color: var(--cm-muted);
  font-size: 20px;
  font-weight: 800;
}

.path-card__other-name {
  min-height: 29px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
  text-wrap: balance;
}

.path-card__other-state {
  color: var(--cm-muted);
  font-size: 11px;
  font-weight: 800;
}
</style>
