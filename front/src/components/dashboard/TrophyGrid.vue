<template>
  <section class="cm-card trophies" data-testid="trophies">
    <div class="trophies__head">
      <h2 class="cm-card__title">{{ $t('dashboard.trophies.title') }}</h2>
      <span
        v-if="cards.length"
        class="trophies__count"
        data-testid="trophies-count"
        >{{
          $t('dashboard.trophies.count', {
            won: won.length,
            total: cards.length
          })
        }}</span
      >
    </div>
    <p v-if="error" class="cm-muted q-my-none">{{ error }}</p>
    <p
      v-else-if="won.length === 0"
      class="cm-muted q-my-none"
      data-testid="trophies-empty"
    >
      {{ $t('dashboard.trophies.empty') }}
    </p>
    <template v-else>
      <template v-for="group in groups" :key="group.key">
        <h3
          v-if="group.title"
          class="trophies__group"
          :data-testid="`trophies-${group.key}`"
        >
          {{ group.title }}
        </h3>
        <div class="trophies__grid">
          <div
            v-for="card in group.cards"
            :key="card.key"
            class="trophies__badge"
            :class="{ 'trophies__badge--exceptional': card.exceptional }"
            :data-testid="`trophy-${card.key}`"
            data-unlocked="true"
            :title="card.desc"
          >
            <div
              class="trophies__icon"
              :class="{ 'trophies__icon--days': card.streak || card.puzzles }"
              :style="{ background: card.bg, color: card.ink }"
            >
              {{ card.icon }}
            </div>
            <div class="trophies__name">{{ card.name }}</div>
            <div class="trophies__desc">{{ card.desc }}</div>
            <div class="trophies__progress">{{ card.progress }}</div>
          </div>
        </div>
      </template>
    </template>
  </section>
</template>

<script setup>
/**
 * The trophies (docs/GAMIFICATION.md): only the won ones, with the date of the feat — the
 * exceptional badges (the arena's professors) in their own row first, then the others (the streak
 * badges last, shortest first, then the puzzle streak badges); the others stay hidden, only counted.
 */
import { computed } from 'vue'
import { t } from '@/i18n'
import { useGamificationStore } from '@/stores/gamification'
import { trophyCards } from '@/utils/gamification'

const gamification = useGamificationStore()
const cards = computed(() => trophyCards(gamification.trophies))
const won = computed(() => cards.value.filter(c => c.unlocked))
const groups = computed(() => {
  const exceptional = won.value.filter(c => c.exceptional)
  const others = won.value.filter(c => !c.exceptional)
  return [
    ...(exceptional.length
      ? [
          {
            key: 'exceptional',
            title: t('dashboard.trophies.exceptional'),
            cards: exceptional
          }
        ]
      : []),
    ...(others.length ? [{ key: 'others', title: '', cards: others }] : [])
  ]
})
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

.trophies__group {
  margin: 0;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #c9971c;
}

.trophies__badge--exceptional {
  background: linear-gradient(
    160deg,
    rgba(242, 201, 76, 0.22),
    var(--cm-inset)
  );
  box-shadow: inset 0 0 0 1.5px #f2c94c;
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
  background: var(--cm-inset);
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
