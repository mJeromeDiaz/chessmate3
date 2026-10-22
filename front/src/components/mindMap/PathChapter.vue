<template>
  <section
    class="path-chapter"
    :class="`path-chapter--${chapter.status}`"
    :data-testid="`path-chapter-${chapter.number}`"
    :data-status="chapter.status"
  >
    <div ref="sentinel" class="path-chapter__sentinel" aria-hidden="true" />
    <header
      ref="head"
      class="path-chapter__head cm-card"
      :class="{ 'path-chapter__head--stuck': stuck }"
      :style="headStyle"
      :data-stuck="stuck"
    >
      <div class="path-chapter__text">
        <div class="path-chapter__kicker">
          <q-icon v-if="chapter.status === 'done'" name="verified" />
          <q-icon v-else-if="chapter.status === 'locked'" name="lock" />
          {{ $t('mindMapPath.page.chapter', { n: chapter.number }) }} ·
          {{
            $t('mindMapPath.page.progress', {
              done: progress.done,
              total: progress.total
            })
          }}
        </div>
        <h2 class="path-chapter__title">{{
          chapterTitle(capacity, chapter.key)
        }}</h2>
      </div>
      <span class="path-chapter__boss">{{
        $t('mindMapPath.page.chapterBoss', { name: bossName(chapter.boss) })
      }}</span>
    </header>
    <p v-if="chapter.status === 'locked'" class="path-chapter__locked">{{
      $t('mindMapPath.page.locked')
    }}</p>

    <div class="path-chapter__nodes">
      <template v-for="node in nodes" :key="node.step.key">
        <div v-if="node.sectionStart" class="path-chapter__section">
          <span class="path-chapter__section-number">{{
            $t('mindMapPath.page.section', { n: node.section.number })
          }}</span>
          <span class="path-chapter__section-title">{{
            sectionTitle(capacity, node.section)
          }}</span>
        </div>
        <PathNode
          :step="node.step"
          :capacity="capacity"
          :offset="nodeOffset(node)"
          :current="node.step.key === currentKey"
          :chosen="node.step.key === chosenKey"
          @select="emit('select', $event)"
        />
      </template>
    </div>
  </section>
</template>

<script setup>
/**
 * A chapter of a path (design « Parcours zone »): its card (number, progress, title, boss), then
 * all its steps winding down, each section under its title; a locked chapter shows them greyed.
 * The card sticks under the app's header while its chapter scrolls under it, one line then (the
 * next chapter's card pushes it away); it keeps its place in the flow, so the path never jumps.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PathNode from '@/components/mindMap/PathNode.vue'
import {
  bossName,
  chapterNodes,
  chapterProgress,
  chapterTitle,
  nodeOffset,
  sectionTitle
} from '@/utils/mindMapPath'

/** The card's height once stuck (one line). */
const STUCK_HEIGHT = 52

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathChapter>} */
  chapter: { type: Object, required: true },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** The key of the step to play next. */
  currentKey: { type: String, default: null },
  /** The key of the step shown in the detail. */
  chosenKey: { type: String, default: null },
  /** Where the card sticks: the app header's height, in pixels. */
  stickTop: { type: Number, default: 0 }
})

const emit = defineEmits({ select: null })

const nodes = computed(() => chapterNodes(props.chapter))
const progress = computed(() => chapterProgress(props.chapter))

/** @type {import('vue').Ref<HTMLElement|null>} */
const sentinel = ref(null)
/** @type {import('vue').Ref<HTMLElement|null>} */
const head = ref(null)
const stuck = ref(false)
/** The card's height at rest, measured before it shrinks. */
const restHeight = ref(0)

/** Stuck: one line, and a bottom margin for the rest so that what follows does not move. */
const headStyle = computed(() => ({
  top: `${props.stickTop + 8}px`,
  ...(stuck.value && restHeight.value > STUCK_HEIGHT
    ? {
        height: `${STUCK_HEIGHT}px`,
        marginBottom: `${restHeight.value - STUCK_HEIGHT}px`
      }
    : {})
}))

/** @type {number} */
let frame = 0

/**
 * The sentinel sits just above the card: gone above the header's edge, the card is stuck (or
 * leaving with its chapter). Read on each scroll (once per frame): a jump of the page (the
 * current step centred, a step brought into view) must count too.
 */
function measure() {
  frame = 0
  if (!sentinel.value) return
  const nowStuck =
    sentinel.value.getBoundingClientRect().top < props.stickTop + 8
  if (nowStuck && !stuck.value && head.value)
    restHeight.value = head.value.offsetHeight
  stuck.value = nowStuck
}

function onScroll() {
  if (!frame) frame = requestAnimationFrame(measure)
}

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true })
  measure()
})
watch(() => props.stickTop, measure)
onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
  cancelAnimationFrame(frame)
})
</script>

<style scoped lang="scss">
.path-chapter {
  display: flex;
  flex-direction: column;
}

.path-chapter__sentinel {
  height: 1px;
  margin-bottom: -1px;
}

.path-chapter__head {
  position: sticky;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 20px;
  border-radius: 22px;
  border-left: 6px solid var(--capacity, var(--cm-brand));
  transition:
    box-shadow 0.2s,
    padding 0.2s;

  &--stuck {
    padding: 0 16px;
    box-shadow: 0 10px 24px rgba(27, 21, 48, 0.18);
  }
}

.path-chapter--locked .path-chapter__head {
  border-left-color: var(--cm-dash);
}

.path-chapter__text {
  flex: 1;
  min-width: 0;
}

.path-chapter__kicker {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--capacity-deep, var(--cm-brand));
  font-variant-numeric: tabular-nums;
}

.path-chapter--locked .path-chapter__kicker {
  color: var(--cm-faint);
}

.path-chapter__title {
  margin: 2px 0 0;
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.15;
  color: var(--cm-ink);
}

.path-chapter--locked .path-chapter__title {
  color: var(--cm-muted);
}

.path-chapter__boss {
  flex: none;
  max-width: 40%;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-muted);
  text-align: right;
}

// Stuck: « Chapitre 3 · 4 / 16 · Vision dynamique » on one line.
.path-chapter__head--stuck {
  .path-chapter__text {
    display: flex;
    align-items: baseline;
    gap: 10px;
    overflow: hidden;
    white-space: nowrap;
  }

  .path-chapter__kicker {
    flex: none;
  }

  .path-chapter__title {
    margin: 0;
    overflow: hidden;
    font-size: 16px;
    text-overflow: ellipsis;
  }

  .path-chapter__boss {
    display: none;
  }
}

.path-chapter__locked {
  margin: 10px 4px 0;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.path-chapter__nodes {
  display: flex;
  flex-direction: column;
  padding: 24px 0 32px;
}

.path-chapter__section {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 18px 4px 22px;

  &::after {
    content: '';
    flex: 1;
    min-width: 20px;
    height: 1px;
    background: var(--cm-line);
  }
}

.path-chapter__section-number {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--capacity-deep, var(--cm-brand));
}

.path-chapter__section-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--cm-ink);
}

.path-chapter--locked {
  .path-chapter__section-number {
    color: var(--cm-faint);
  }

  .path-chapter__section-title {
    color: var(--cm-muted);
  }
}
</style>
