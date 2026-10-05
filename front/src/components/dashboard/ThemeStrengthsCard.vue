<template>
  <section class="cm-card themes-card" data-testid="theme-strengths">
    <div class="themes-card__head">
      <h2 class="cm-card__title">Thèmes de puzzles</h2>
      <span v-if="themes.attempts" class="cm-muted"
        >{{ formatPercent(themes.successCount / themes.attempts) }} sur
        {{ themes.attempts }} puzzles</span
      >
    </div>

    <p
      v-if="!themes.attempts"
      class="cm-muted q-my-md"
      data-testid="themes-empty"
    >
      Aucun puzzle classé sur la période.
      <router-link to="/puzzle">Jouer des puzzles →</router-link>
    </p>
    <p
      v-else-if="!themes.themes.length"
      class="cm-muted q-my-md"
      data-testid="themes-few"
    >
      Pas encore assez d’essais par thème ({{ themes.minAttempts }} minimum).
    </p>

    <div v-else class="themes-card__columns">
      <div data-testid="themes-strong">
        <h3 class="themes-card__h3">Points forts</h3>
        <div v-for="t in themes.strong" :key="t.key" class="themes-card__row">
          <div class="themes-card__line">
            <span class="themes-card__name">{{ label(t.key) }}</span>
            <strong>{{ formatPercent(t.successRate) }}</strong>
          </div>
          <div class="themes-card__track">
            <div
              class="themes-card__fill"
              :style="{ width: `${t.successRate * 100}%` }"
            />
          </div>
          <div class="themes-card__meta"
            >{{ t.successCount }} / {{ t.attempts }}</div
          >
        </div>
      </div>
      <div data-testid="themes-weak">
        <h3 class="themes-card__h3">À travailler</h3>
        <p v-if="!themes.weak.length" class="cm-muted">Rien à signaler.</p>
        <button
          v-for="t in themes.weak"
          :key="t.key"
          type="button"
          class="themes-card__row themes-card__row--action"
          :data-testid="`theme-train-${t.key}`"
          @click="train(t.key)"
        >
          <div class="themes-card__line">
            <span class="themes-card__name">{{ label(t.key) }}</span>
            <strong>{{ formatPercent(t.successRate) }}</strong>
          </div>
          <div class="themes-card__track">
            <div
              class="themes-card__fill"
              :style="{ width: `${t.successRate * 100}%` }"
            />
          </div>
          <div class="themes-card__meta">
            {{ t.successCount }} / {{ t.attempts }}
            <span class="themes-card__go">S’entraîner ›</span>
          </div>
        </button>
      </div>
    </div>

    <p class="themes-card__note">
      Puzzles classés résolus sans aide (ni erreur, ni indice, ni solution) ;
      {{ themes.minAttempts }} essais minimum par thème.
    </p>
  </section>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { usePuzzleStore } from '@/stores/puzzle'
import { formatPercent } from '@/utils/format'

/**
 * Strong and weak puzzle themes of the period (statistics page). A weak theme opens the rated
 * puzzles with that theme as filter.
 */
defineProps({
  /** @type {import('vue').PropType<import('@/stores/dashboard').Themes>} */
  themes: { type: Object, required: true }
})

const router = useRouter()
const puzzles = usePuzzleStore()

/** @param {string} key */
const label = key => puzzles.themeLabel(key)

/** @param {string} key */
function train(key) {
  puzzles.setThemes([key])
  router.push('/puzzle')
}

onMounted(() => puzzles.fetchThemes().catch(() => null))
</script>

<style scoped lang="scss">
.themes-card__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  font-size: 13px;
}

.themes-card__columns {
  display: grid;
  gap: 16px;
  margin-top: 12px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.themes-card__h3 {
  margin: 0 0 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.themes-card__row {
  display: block;
  width: 100%;
  padding: 8px 0;
  border: none;
  border-top: 1px solid var(--cm-line);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;

  &--action {
    cursor: pointer;

    &:hover .themes-card__go {
      text-decoration: underline;
    }
  }
}

.themes-card__line {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 14px;

  strong {
    font-variant-numeric: tabular-nums;
  }
}

.themes-card__name {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.themes-card__track {
  height: 6px;
  margin: 6px 0 4px;
  background: var(--cm-subtle);
  border-radius: 999px;
  overflow: hidden;
}

.themes-card__fill {
  height: 100%;
  background: var(--cm-brand);
  border-radius: 999px;
}

.themes-card__meta {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--cm-muted);
}

.themes-card__go {
  color: var(--cm-brand);
  font-weight: 700;
}

.themes-card__note {
  margin: 12px 0 0;
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
