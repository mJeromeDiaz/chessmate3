<template>
  <div class="tour-map tour-in" style="animation-delay: 0.15s">
    <div class="tour-map__paths" aria-hidden="true">
      <span
        v-for="path in PATHS"
        :key="path.key"
        class="tour-map__path"
        :style="{ background: path.color }"
        >{{ $t(`onboarding.bosses.paths.${path.key}`) }}</span
      >
    </div>

    <div ref="box" class="tour-map__box" data-testid="tour-map">
      <section
        v-for="chapter in SAMPLE_PATH"
        :key="chapter.key"
        class="tour-map__chapter"
      >
        <header class="tour-map__head">
          <span class="tour-map__kicker">
            <q-icon v-if="chapter.status === 'done'" name="verified" />
            {{ $t('mindMapPath.page.chapter', { n: chapter.number }) }}
          </span>
          <span class="tour-map__title">{{
            chapterTitle(CAPACITY, chapter.key)
          }}</span>
        </header>
        <template v-for="node in chapterNodes(chapter)" :key="node.step.key">
          <div v-if="node.sectionStart" class="tour-map__section">
            <span class="tour-map__section-number">{{
              $t('mindMapPath.page.section', { n: node.section.number })
            }}</span>
            <span class="tour-map__section-title">{{
              sectionTitle(CAPACITY, node.section)
            }}</span>
          </div>
          <PathNode
            :step="node.step"
            :capacity="CAPACITY"
            :offset="nodeOffset(node)"
            :current="node.step.key === SAMPLE_PATH_CURRENT"
            :chosen="node.step.key === chosen.key"
            :data-key="node.step.key"
            @select="chosen = $event"
          />
        </template>
      </section>
    </div>

    <div
      class="tour-map__detail"
      aria-live="polite"
      data-testid="tour-map-detail"
    >
      <span class="tour-map__detail-kind">{{ kindOf(chosen) }}</span>
      <span class="tour-map__detail-title">{{ titleOf(chosen) }}</span>
      <span class="tour-map__facts">
        <span
          v-for="fact in tourStepFacts(chosen)"
          :key="fact"
          class="tour-map__fact"
          >{{ fact }}</span
        >
      </span>
    </div>
  </div>
</template>

<script setup>
/**
 * The tour's map (design « Visite guidée », « Ta carte d'aventure », redrawn as the real path):
 * a piece of the Visualisation path as the brain's page draws it, its steps winding down
 * (`PathNode`), the boss of chapter 1 beaten, chapter 2 under way with its mini-bosses, a hidden
 * one and the Cyclope. It scrolls by hand, opened on the step to play next; a step touched shows
 * what it asks and earns underneath. Nothing here is played: it is an example.
 */
import { nextTick, onMounted, ref } from 'vue'
import PathNode from '@/components/mindMap/PathNode.vue'
import {
  bossName,
  chapterNodes,
  chapterTitle,
  isBoss,
  nodeOffset,
  sectionTitle,
  stepTitle
} from '@/utils/mindMapPath'
import {
  SAMPLE_PATH,
  SAMPLE_PATH_CURRENT,
  tourStepFacts
} from '@/utils/onboarding'
import { t } from '@/i18n'

/** The sample's path: its texts. */
const CAPACITY = 'visualisation'

/** The three paths, named above the map. */
const PATHS = [
  { key: 'visualisation', color: '#B3A1FF' },
  { key: 'calculation', color: '#FFD43B' },
  { key: 'endgames', color: '#FFB27A' }
]

/** @type {import('vue').Ref<HTMLElement|null>} */
const box = ref(null)

/** @param {string} key */
function stepOf(key) {
  for (const chapter of SAMPLE_PATH) {
    const node = chapterNodes(chapter).find(n => n.step.key === key)
    if (node) return node.step
  }
  return SAMPLE_PATH[0].boss
}

/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathStep>} */
const chosen = ref(stepOf(SAMPLE_PATH_CURRENT))

/** @param {import('@/utils/mindMapPath').PathStep} step */
function kindOf(step) {
  if (step.kind === 'exercise')
    return t('mindMapPath.page.step', { n: step.number })
  return t(`mindMapPath.kinds.${step.kind}`)
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
function titleOf(step) {
  if (step.status === 'hidden') return '???'
  if (isBoss(step.kind) && step.character) return bossName(step)
  return stepTitle(CAPACITY, step.key)
}

/** Opens on the step to play next, a little below the top. */
onMounted(async () => {
  await nextTick()
  const node = box.value?.querySelector(
    `[data-key="${SAMPLE_PATH_CURRENT}"]`
  )
  if (box.value && node instanceof HTMLElement)
    box.value.scrollTop = Math.max(0, node.offsetTop - 70)
})
</script>

<style scoped lang="scss">
.tour-map {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.tour-map__paths {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.tour-map__path {
  height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  color: #1b1530;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.06em;
  display: flex;
  align-items: center;
}

.tour-map__box {
  --capacity-deep: #5a3be0;
  position: relative;
  height: 300px;
  flex: none;
  overflow-y: auto;
  overscroll-behavior: contain;
  border-radius: 20px;
  background: var(--cm-inset);
  padding: 0 8px;

  @media (min-width: $breakpoint-md-min) {
    height: 360px;
  }
}

.body--dark .tour-map__box {
  --capacity-deep: #b3a1ff;
}

.tour-map__chapter {
  padding-bottom: 16px;
}

.tour-map__head {
  position: sticky;
  top: 0;
  z-index: 2;
  margin: 0 -8px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 1px;
  background: var(--cm-inset);
  border-bottom: 1px solid var(--cm-line);
}

.tour-map__kicker {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--capacity-deep);
}

.tour-map__title {
  font-weight: 800;
  font-size: 15px;
}

.tour-map__section {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 14px 4px 6px;

  &::after {
    content: '';
    flex: 1;
    min-width: 20px;
    height: 1px;
    background: var(--cm-line);
  }
}

.tour-map__section-number {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--capacity-deep);
}

.tour-map__section-title {
  font-size: 14px;
  font-weight: 700;
}

.tour-map__detail {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 14px;
  border-radius: 16px;
  border: 1.5px solid var(--cm-line);
}

.tour-map__detail-kind {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.tour-map__detail-title {
  font-weight: 800;
  font-size: 15px;
}

.tour-map__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.tour-map__fact {
  height: 22px;
  padding: 0 9px;
  border-radius: 999px;
  background: var(--cm-inset);
  font-size: 11.5px;
  font-weight: 800;
  display: flex;
  align-items: center;
}
</style>
