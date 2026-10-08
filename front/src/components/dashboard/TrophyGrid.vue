<template>
  <section class="cm-card trophies" data-testid="trophies">
    <div class="trophies__head">
      <h2 class="cm-card__title">Trophées</h2>
      <span
        v-if="cards.length"
        class="trophies__count"
        data-testid="trophies-count"
        >{{ won.length }} / {{ cards.length }} débloqués</span
      >
    </div>
    <p v-if="error" class="cm-muted q-my-none">{{ error }}</p>
    <p
      v-else-if="won.length === 0"
      class="cm-muted q-my-none"
      data-testid="trophies-empty"
    >
      Aucun trophée pour l’instant : ils se débloquent en jouant, la surprise
      est au bout.
    </p>
    <div v-else class="trophies__grid">
      <div
        v-for="card in won"
        :key="card.key"
        class="trophies__badge"
        :data-testid="`trophy-${card.key}`"
        data-unlocked="true"
        :title="card.desc"
      >
        <div
          class="trophies__icon"
          :class="{ 'trophies__icon--days': card.streak }"
          :style="{ background: card.bg, color: card.ink }"
        >
          {{ card.icon }}
        </div>
        <div class="trophies__name">{{ card.name }}</div>
        <div class="trophies__desc">{{ card.desc }}</div>
        <div class="trophies__progress">{{ card.progress }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
/**
 * The trophies (docs/GAMIFICATION.md): only the won ones, with the date of the feat (the streak
 * badges last, shortest first); the others stay hidden, only counted.
 */
import { computed } from 'vue'
import { useGamificationStore } from '@/stores/gamification'
import { trophyCards } from '@/utils/gamification'

const gamification = useGamificationStore()
const cards = computed(() => trophyCards(gamification.trophies))
const won = computed(() => cards.value.filter(c => c.unlocked))
const error = computed(() => gamification.errors.trophies ?? '')
</script>

<style scoped lang="scss">
.trophies {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.trophies__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.trophies__count {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

.trophies__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  gap: 8px;
}

.trophies__badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  padding: 10px 6px;
  border-radius: 16px;
  background: var(--cm-page);
  text-align: center;
}

.trophies__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  font-size: 24px;

  // A streak badge shows its number of days.
  &--days {
    border-radius: 50%;
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 15px;
    font-variant-numeric: tabular-nums;
  }
}

.trophies__name {
  font-weight: 700;
  font-size: 12px;
  line-height: 1.2;
}

.trophies__desc {
  font-size: 10.5px;
  line-height: 1.2;
  color: var(--cm-muted);
}

.trophies__progress {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--cm-ink-soft);
  font-variant-numeric: tabular-nums;
}
</style>
