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

      <ol class="path-card__track" :aria-label="$t('dashboard.path.track')">
        <li
          v-for="node in nodes"
          :key="node.key"
          class="path-card__node"
          :class="`path-card__node--${node.role}`"
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
            :style="
              other.percent
                ? { background: other.color, color: other.ink }
                : undefined
            "
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
  color: #c9b8ff;
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
  color: var(--cm-lime);
  font-size: 12px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

// Before, current, next, ···, boss: a short line of dots.
.path-card__track {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 0;
  padding: 34px 128px 14px 16px;
  list-style: none;

  &::before {
    content: '';
    position: absolute;
    left: 30px;
    right: 150px;
    top: calc(34px + 15px);
    height: 4px;
    margin-top: -2px;
    border-radius: 4px;
    background: #3a3450;
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 22px;
    padding-left: 22px;
  }
}

.path-card__node {
  position: relative;
  flex: none;
  display: flex;
  justify-content: center;
  width: 30px;
}

.path-card__dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #2b2640;
  box-shadow: 0 3px 0 #1f1a2c;
  color: #8a85a0;
  font-size: 11px;
  font-weight: 800;

  .path-card__node--previous & {
    background: #8b6bff;
    box-shadow: 0 3px 0 #5a3be0;
    color: #fff;
  }

  .path-card__node--current &,
  .path-card__node--current-boss & {
    background: #8b6bff;
    box-shadow:
      0 0 0 2px #231b3a,
      0 0 0 5px var(--cm-lime);
    color: #fff;
  }

  .path-card__node--boss &,
  .path-card__node--current-boss & {
    width: 36px;
    height: 36px;
    font-size: 16px;
    color: #ffa552;
  }
}

.path-card__gap {
  color: #8a85a0;
  font-weight: 800;
  letter-spacing: 1px;
}

.path-card__here {
  position: absolute;
  bottom: calc(100% + 9px);
  left: 50%;
  transform: translateX(-50%);
  padding: 3px 7px;
  border-radius: 8px;
  background: #fff;
  color: #5a3be0;
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
  background: var(--cm-lime);
  box-shadow: 0 3px 0 #8fb800;
  color: #1b1530;
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
    opacity: 0.6;
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
