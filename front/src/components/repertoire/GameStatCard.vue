<template>
  <div class="game-stat" data-testid="game-stat">
    <div class="game-stat__head">
      <q-icon name="auto_awesome" size="16px" class="game-stat__icon" />
      <span class="game-stat__title">Dans tes parties</span>
      <span class="game-stat__figures"
        >{{ summary.games }} partie{{ summary.games > 1 ? 's' : '' }} · score
        {{ summary.score }} %</span
      >
    </div>
    <div v-if="summary.games > 0" class="game-stat__bar">
      <div
        v-for="o in summary.outcomes"
        :key="o.key"
        :style="{ width: `${o.share}%`, background: o.color }"
        :title="`${o.label} ${o.share} %`"
      />
    </div>
    <div v-for="(fact, i) in summary.facts" :key="i" class="game-stat__fact">
      {{ fact }}
    </div>
  </div>
</template>

<script setup>
/**
 * What the user's games said of the selected move, in the editor of a repertoire built from them
 * (docs/REPERTOIRE.md, "Repertoire from my games"): frozen at the build.
 */
import { computed } from 'vue'
import { gameStatFacts } from '@/utils/repertoireAuto'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').GameStat>} */
  stat: { type: Object, required: true },
  /** The repertoire's color. */
  color: { type: String, required: true }
})

const summary = computed(() => gameStatFacts(props.stat, props.color))
</script>

<style scoped lang="scss">
.game-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  border-radius: 14px;
  background: var(--cm-brand-soft);
  color: var(--cm-ink);
}

.game-stat__head {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.game-stat__icon {
  color: var(--cm-brand);
}

.game-stat__title {
  font-weight: 700;
  font-size: 13px;
}

.game-stat__figures {
  font-size: 13px;
  color: var(--cm-muted);
}

.game-stat__bar {
  display: flex;
  height: 8px;
  border-radius: 8px;
  overflow: hidden;
  gap: 2px;
}

.game-stat__fact {
  font-size: 13px;
  line-height: 1.4;
  color: var(--cm-ink-soft);
}
</style>
