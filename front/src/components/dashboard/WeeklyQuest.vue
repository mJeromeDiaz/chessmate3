<template>
  <div v-if="quest" class="quest" data-testid="weekly-quest">
    <img v-if="prof.image" :src="prof.image" alt="" class="quest__prof" />
    <div class="quest__bubble">
      <div class="quest__kicker">
        {{ prof.name.toUpperCase() }} · DÉFI DE LA SEMAINE
        <span
          v-if="quest.completed"
          class="quest__done"
          data-testid="quest-done"
          >✓ RÉUSSI</span
        >
      </div>
      <div class="quest__text" data-testid="quest-text">{{ text }}</div>
      <div class="quest__progress">
        <div class="quest__bar">
          <div :style="{ width: `${percent}%` }" />
        </div>
        <span data-testid="quest-progress"
          >{{ quest.current }}/{{ quest.goal }}</span
        >
        <span class="quest__reward">+{{ quest.reward }} XP</span>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * The weekly quest (docs/GAMIFICATION.md): drawn by the API each Monday, given by the professor
 * of its module, its reward gained once it is completed. Hidden when it cannot be loaded.
 */
import { computed, onMounted } from 'vue'
import { useGamificationStore } from '@/stores/gamification'
import { usePuzzleStore } from '@/stores/puzzle'
import { questProf, questText } from '@/utils/gamification'

const gamification = useGamificationStore()
const puzzles = usePuzzleStore()

const quest = computed(() => gamification.quest)
const prof = computed(() =>
  quest.value ? questProf(quest.value) : { name: '', image: '' }
)
const text = computed(() =>
  quest.value ? questText(quest.value, key => puzzles.themeLabel(key)) : ''
)
const percent = computed(() =>
  quest.value && quest.value.goal > 0
    ? Math.min(100, (quest.value.current / quest.value.goal) * 100)
    : 0
)

onMounted(() => puzzles.fetchThemes().catch(() => null))
</script>

<style scoped lang="scss">
.quest {
  display: flex;
  align-items: flex-end;
  gap: 10px;
}

.quest__prof {
  width: 66px;
  flex: none;

  @media (min-width: $breakpoint-md-min) {
    width: 76px;
  }
}

.quest__bubble {
  flex: 1;
  margin-bottom: 6px;
  padding: 10px 14px;
  border: 2px solid var(--cm-orange-line);
  border-radius: 18px 18px 18px 4px;
  background: var(--cm-surface);
}

.quest__kicker {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-orange-ink);
}

.quest__text {
  font-size: 14px;
  line-height: 1.4;
}

.quest__progress {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  font-size: 12px;
  font-weight: 700;
}

.quest__bar {
  flex: 1;
  height: 7px;
  border-radius: 7px;
  background: var(--cm-orange-soft);
  overflow: hidden;

  > div {
    height: 100%;
    background: #ff8a3d;
  }
}

.quest__reward {
  color: var(--cm-brand-deep);
}
.quest__done {
  color: var(--cm-lime-ink);
}
</style>
