<template>
  <section
    v-if="chapters.length"
    class="cm-card challenges-card"
    data-testid="dashboard-challenges"
  >
    <div class="challenges-card__head">
      <h2 class="cm-card__title">{{ $t('dashboard.challenges.title') }}</h2>
      <span class="challenges-card__count">{{
        $t('dashboard.challenges.done', { n: done })
      }}</span>
    </div>
    <div class="challenges-card__grid">
      <router-link
        v-for="chapter in chapters"
        :key="chapter.key"
        to="/defis"
        class="challenges-card__tile"
        data-testid="dashboard-challenge"
      >
        <span
          class="challenges-card__ring"
          :style="{
            background: `conic-gradient(#ff6fae ${chapter.percent}%, var(--cm-pink-soft) 0)`
          }"
          ><span class="challenges-card__glyph">{{ chapter.glyph }}</span></span
        >
        <span class="challenges-card__text">
          <span class="challenges-card__name">{{ chapter.name }}</span>
          <span class="challenges-card__ratio"
            >{{ chapter.done }} / {{ chapter.total }}</span
          >
        </span>
      </router-link>
    </div>
  </section>
</template>

<script setup>
/**
 * « Défis » (design « Dashboard v2 », docs/DEFIS.md): one tile per chapter of the défis' path,
 * the fixed ones and one per repertoire, as many as the API sends (more will come); a ring of its
 * tiers mastered and boss beaten.
 */
import { computed } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import { CHALLENGES, isOpening } from '@/utils/challengePath'
import { chapterTotals } from '@/utils/endgamePath'
import { pathChapterName } from '@/utils/mindMapPath'

const store = useDashboardStore()

/** The fixed chapters' glyphs; an opening's is a rook. */
const GLYPHS = /** @type {Record<string, string>} */ ({
  mates: '#',
  endgames: '♚︎',
  tactics: '♞︎'
})

const chapters = computed(() => {
  const path = store.paths[CHALLENGES]
  if (!path) return []
  return path.chapters.map(chapter => {
    const { done, total } = chapterTotals(chapter)
    return {
      key: chapter.key,
      name: pathChapterName(CHALLENGES, chapter),
      glyph: isOpening(chapter.key) ? '♜︎' : (GLYPHS[chapter.key] ?? '★'),
      done,
      total,
      percent: total ? Math.round((100 * done) / total) : 0
    }
  })
})
const done = computed(() => chapters.value.reduce((n, c) => n + c.done, 0))
</script>

<style scoped lang="scss">
.challenges-card {
  --cm-pink-soft: #ffe6f1;

  display: flex;
  flex-direction: column;
  gap: 12px;
}

.body--dark .challenges-card {
  --cm-pink-soft: #3d1f30;
}

.challenges-card__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.challenges-card__count {
  color: var(--cm-muted);
  font-size: 12.5px;
  font-weight: 700;
}

.challenges-card__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.challenges-card__tile {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border-radius: 16px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  text-decoration: none;
}

.challenges-card__ring {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
}

.challenges-card__glyph {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--cm-inset);
  color: #c02670;
  font-size: 17px;
  font-weight: 800;
}

.challenges-card__text {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.challenges-card__name {
  overflow: hidden;
  font-weight: 700;
  font-size: 13.5px;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.challenges-card__ratio {
  color: var(--cm-muted);
  font-size: 11.5px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
</style>
