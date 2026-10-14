<template>
  <div class="tour-level">
    <template v-if="lichess.linked && lichess.rating">
      <div
        class="tour-level__lichess tour-in"
        data-testid="tour-lichess-rating"
      >
        <div class="tour-level__lichess-text">
          <div class="tour-level__label">
            {{ $t('onboarding.level.lichessElo', { perf: perfLabel }) }}
          </div>
          <div class="tour-level__elo">{{ eloText }}</div>
        </div>
        <div v-if="suggested" class="tour-level__stamp">
          {{ suggested.name }} ✓
        </div>
      </div>
      <div class="tour-level__hint">
        {{ $t('onboarding.level.disagree') }}
      </div>
    </template>
    <template v-else-if="!lichess.linked">
      <button
        type="button"
        class="tour-level__link"
        :disabled="linking"
        data-testid="tour-link-lichess"
        @click="emit('link')"
      >
        <span class="tour-level__knight" aria-hidden="true">♞︎</span>
        {{
          linking ? $t('onboarding.level.linking') : $t('onboarding.level.link')
        }}
      </button>
      <div class="tour-level__or">
        <span />{{ $t('onboarding.level.or') }}<span />
      </div>
    </template>

    <div
      class="tour-level__grid"
      role="radiogroup"
      :aria-label="$t('onboarding.level.aria')"
      data-testid="tour-levels"
    >
      <button
        v-for="(level, i) in LEVELS"
        :key="level.id"
        type="button"
        role="radio"
        class="tour-choice tour-level__card tour-in"
        :class="{ 'tour-choice--on': modelValue === level.id }"
        :aria-checked="modelValue === level.id"
        :style="{ animationDelay: `${0.1 + i * 0.05}s` }"
        :data-testid="`tour-level-${level.id}`"
        @click="emit('update:modelValue', level.id)"
      >
        <span class="tour-level__glyph" aria-hidden="true">{{
          level.glyph
        }}</span>
        <span class="tour-level__name">{{ level.name }}</span>
        <span class="tour-choice__sub">{{ level.hint }}</span>
      </button>
    </div>
    <p v-if="note" class="tour-level__note" data-testid="tour-rating-note">
      {{ note }}
    </p>
  </div>
</template>

<script setup>
/**
 * The tour's level screen: the linked Lichess account's rating and the level it suggests, or a
 * button to link it; then the five levels (one choice) and what it does to the puzzle rating.
 */
import { computed } from 'vue'
import { LEVELS, levelOf, ratingNote } from '@/utils/onboarding'
import { intlLocale, t } from '@/i18n'

const PERFS = ['rapid', 'blitz', 'classical']

const props = defineProps({
  /** The level chosen. */
  modelValue: { type: String, default: null },
  /** @type {import('vue').PropType<import('@/utils/onboarding').OnboardingAnswers|null>} */
  answers: { type: Object, default: null },
  linking: { type: Boolean, default: false }
})

const emit = defineEmits({
  /** @param {string} id */
  'update:modelValue': id => typeof id === 'string',
  /** Link Lichess (and come back here). */
  link: () => true
})

const lichess = computed(
  () => props.answers?.lichess ?? { linked: false, perf: null, rating: null }
)
const perfLabel = computed(() => {
  const perf = lichess.value.perf ?? ''
  return (
    PERFS.includes(perf) ? t(`onboarding.perfs.${perf}`) : perf
  ).toUpperCase()
})
const eloText = computed(() =>
  new Intl.NumberFormat(intlLocale()).format(lichess.value.rating ?? 0)
)
const suggested = computed(() => levelOf(props.answers?.suggestedLevel))
const note = computed(() => ratingNote(props.modelValue, props.answers))
</script>

<style scoped lang="scss">
.tour-level {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tour-level__lichess {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 18px;
  background: var(--cm-page);
  border: 1px solid var(--cm-line);
}

.tour-level__lichess-text {
  flex: 1;
  min-width: 0;
}

.tour-level__label {
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-muted);
}

.tour-level__elo {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 34px;
  line-height: 1.05;
  font-variant-numeric: tabular-nums;
  color: var(--cm-ink);
}

.tour-level__stamp {
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  animation: tour-stamp 0.5s cubic-bezier(0.34, 1.8, 0.64, 1) 0.3s both;
}

.tour-level__hint {
  padding: 0 4px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-muted);
}

.tour-level__link {
  height: 54px;
  border: none;
  border-radius: 16px;
  background: #1b1530;
  color: #fff;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;
  box-shadow: 0 4px 0 #4c475e;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;

  &:active {
    transform: translateY(4px);
    box-shadow: none;
  }
}

.tour-level__knight {
  font-size: 20px;
}

.tour-level__or {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);

  span {
    flex: 1;
    height: 1px;
    background: var(--cm-line);
  }
}

.tour-level__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding-bottom: 4px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

.tour-level__card {
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  padding: 10px 12px 12px;
}

.tour-level__glyph {
  font-size: 20px;
  line-height: 1;
}

.tour-level__name {
  font-weight: 800;
  font-size: 14.5px;
}

.tour-level__note {
  margin: 0;
  padding: 0 4px;
  font-size: 12.5px;
  line-height: 1.4;
  color: var(--cm-ink-soft);
}

@keyframes tour-stamp {
  0% {
    transform: scale(2.2) rotate(-25deg);
    opacity: 0;
  }
  60% {
    transform: scale(0.9) rotate(-10deg);
    opacity: 1;
  }
  100% {
    transform: scale(1) rotate(-6deg);
    opacity: 1;
  }
}
</style>
