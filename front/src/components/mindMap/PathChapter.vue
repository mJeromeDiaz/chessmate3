<template>
  <section
    class="path-chapter"
    :class="`path-chapter--${chapter.status}`"
    :data-testid="`path-chapter-${chapter.number}`"
    :data-status="chapter.status"
  >
    <header class="path-chapter__head cm-card">
      <div class="path-chapter__text">
        <div class="path-chapter__kicker">
          {{ $t('mindMapPath.page.chapter', { n: chapter.number }) }}
          <q-icon v-if="chapter.status === 'done'" name="verified" />
          <q-icon v-else-if="chapter.status === 'locked'" name="lock" />
        </div>
        <h2 class="path-chapter__title">{{
          chapterTitle(capacity, chapter.key)
        }}</h2>
        <div v-if="chapter.status !== 'locked'" class="path-chapter__progress">
          <q-linear-progress
            :value="progress.total ? progress.done / progress.total : 0"
            rounded
            size="8px"
            class="path-chapter__bar"
          />
          <span>{{
            $t('mindMapPath.page.progress', {
              done: progress.done,
              total: progress.total
            })
          }}</span>
        </div>
        <p v-else class="path-chapter__locked">{{
          $t('mindMapPath.page.locked')
        }}</p>
      </div>
      <button
        type="button"
        class="path-chapter__boss"
        :data-testid="`path-chapter-boss-${chapter.number}`"
        @click="emit('select', chapter.boss)"
      >
        <BossPortrait
          :character="chapter.boss.character"
          :pose="chapter.boss.status === 'done' ? 'beaten' : 'idle'"
          :label="bossName(chapter.boss)"
          class="path-chapter__portrait"
        />
        <span class="path-chapter__boss-label">{{
          $t('mindMapPath.page.bossOfChapter')
        }}</span>
        <span class="path-chapter__boss-name">{{
          bossName(chapter.boss)
        }}</span>
      </button>
    </header>

    <div v-if="chapter.status !== 'locked'" class="path-chapter__nodes">
      <template v-for="(node, i) in nodes" :key="node.step.key">
        <div v-if="node.sectionStart" class="path-chapter__section">
          <span>{{ node.section.number }}</span>
          {{ sectionTitle(capacity, node.section) }}
        </div>
        <PathNode
          :step="node.step"
          :capacity="capacity"
          :offset="nodeOffset(i)"
          :current="node.step.key === currentKey"
          @select="emit('select', $event)"
        />
      </template>
    </div>
  </section>
</template>

<script setup>
/**
 * A chapter of a path (docs/MIND_MAP.md): its title, progress and boss (announced at the top,
 * fought at the end), then its steps winding down, each section under its title. A locked chapter
 * shows only its head.
 */
import { computed } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import PathNode from '@/components/mindMap/PathNode.vue'
import {
  bossName,
  chapterNodes,
  chapterProgress,
  chapterTitle,
  nodeOffset,
  sectionTitle
} from '@/utils/mindMapPath'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathChapter>} */
  chapter: { type: Object, required: true },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** The key of the step to play next. */
  currentKey: { type: String, default: null }
})

const emit = defineEmits({ select: null })

const nodes = computed(() => chapterNodes(props.chapter))
const progress = computed(() => chapterProgress(props.chapter))
</script>

<style scoped lang="scss">
.path-chapter {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.path-chapter__head {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 18px 20px;
  border-radius: 20px;
  border-top: 6px solid var(--capacity, var(--cm-brand));
}

.path-chapter--locked .path-chapter__head {
  border-top-color: var(--cm-dash);
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
  color: var(--cm-muted);
}

.path-chapter__title {
  margin: 2px 0 8px;
  font-family: var(--cm-heading);
  font-size: 22px;
  font-weight: 800;
  line-height: 1.15;
  color: var(--cm-ink);
}

.path-chapter__progress {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 800;
  color: var(--cm-muted);
}

.path-chapter__bar {
  flex: 1;
  color: var(--capacity-deep, var(--cm-brand));
}

.path-chapter__locked {
  margin: 0;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.path-chapter__boss {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  width: 96px;
  padding: 0;
  border: none;
  background: none;
  color: var(--cm-ink);
  cursor: pointer;
  text-align: center;

  @media (min-width: $breakpoint-md-min) {
    width: 120px;
  }
}

.path-chapter--locked .path-chapter__portrait {
  filter: grayscale(1);
  opacity: 0.6;
}

.path-chapter__portrait {
  width: 64px;

  @media (min-width: $breakpoint-md-min) {
    width: 80px;
  }
}

.path-chapter__boss-label {
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.path-chapter__boss-name {
  font-size: 13px;
  font-weight: 800;
  line-height: 1.15;
}

.path-chapter__nodes {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 22px;
  padding: 8px 0 24px;
}

.path-chapter__section {
  align-self: stretch;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
  font-size: 14px;
  font-weight: 800;
  color: var(--cm-ink-soft);

  span {
    padding: 2px 10px;
    border-radius: 999px;
    background: var(--capacity, var(--cm-brand));
    color: var(--capacity-ink, #fff);
    font-size: 12px;
  }

  &::after {
    content: '';
    flex: 1;
    height: 2px;
    background: var(--cm-line);
  }
}
</style>
