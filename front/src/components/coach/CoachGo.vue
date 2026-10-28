<template>
  <div class="cs-screen cs-up">
    <div class="cs-cols">
      <div class="cs-cols__side">
        <span
          class="cs-pill cs-pill--tag"
          :style="{ background: moduleColor(module) }"
          data-testid="coach-go-kicker"
          ><span v-if="card.glyph" class="cs-glyph">{{ card.glyph }}</span
          >{{ $t('coach.go.kicker', { total, n: module.minutes }) }}</span
        >
        <h1 class="cs-title">{{ heading }}</h1>
        <div class="cs-albert cs-albert--medium">
          <img
            :src="profImage('albert-v2-pointer')"
            alt="Albert"
            class="cs-albert__img cs-bob"
          />
          <div class="cs-bubble">{{ says }}</div>
        </div>
        <div class="cs-actions">
          <button
            type="button"
            class="cs-cta cs-cta--night cs-cta--big"
            :disabled="counting || launching"
            data-testid="coach-launch"
            @click="countdown"
            >{{
              launching ? $t('coach.go.launching') : $t('coach.go.launch')
            }}</button
          >
        </div>
        <slot name="notice" />
      </div>

      <div class="cs-cols__main">
        <template v-if="module.module === 'woodpecker'">
          <div class="cs-card cs-card--night">
            <div class="cs-kicker cs-kicker--light">{{
              $t('coach.go.method')
            }}</div>
            <ol class="cs-steps">
              <li v-for="(line, i) in woodpeckerMethod()" :key="i">
                <span class="cs-steps__n">{{ i + 1 }}</span
                ><span>{{ line }}</span>
              </li>
            </ol>
            <div class="cs-text cs-text--on-night">
              {{ $t('coach.go.methodText', { n: module.facts.puzzleCount }) }}
            </div>
          </div>
          <div class="cs-card">
            <div class="cs-kicker">{{ $t('coach.go.why') }}</div>
            <div
              v-for="(reason, i) in WOODPECKER_REASONS"
              :key="i"
              class="cs-reason"
            >
              <span class="cs-reason__n">{{ i + 1 }}</span>
              <span class="cs-reason__text"
                ><b>{{ $t('coach.go.reason', { title: reason.title }) }}</b>
                {{ reason.text }}</span
              >
            </div>
          </div>
        </template>
        <div v-else class="cs-card">
          <div class="cs-kicker">{{ $t('coach.go.program') }}</div>
          <div class="cs-text">{{ card.text }}</div>
        </div>
      </div>
    </div>

    <div v-if="counting" class="cs-count" data-testid="coach-countdown">
      <div class="cs-count__label">{{
        $t('coach.go.countLabel', { title: card.title.toUpperCase() })
      }}</div>
      <div class="cs-count__ring">
        <svg viewBox="0 0 220 220">
          <circle cx="110" cy="110" r="98" class="cs-count__track" />
          <circle
            cx="110"
            cy="110"
            r="98"
            class="cs-count__progress"
            pathLength="1"
          />
        </svg>
        <div
          :key="count"
          class="cs-count__n"
          :class="{ 'cs-count__n--go': !count }"
          >{{ count || $t('coach.go.go') }}</div
        >
      </div>
      <div class="cs-count__sub">{{ countSub }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * Step 5 of the tailored session (design "Session perso"): the first module before it starts
 * (the White repertoire, unless it is missing).
 * Woodpecker: the light method the session plays (no cycles: each run starts from the first
 * puzzle) and why it helps; then 3, 2, 1, go and the session is launched.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import {
  WOODPECKER_REASONS,
  moduleCard,
  moduleColor,
  woodpeckerMethod
} from '@/utils/coach'
import { t } from '@/i18n'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/coach').CoachModule>} the first module played */
  module: { type: Object, required: true },
  /** Modules played in the session. */
  total: { type: Number, required: true },
  launching: { type: Boolean, default: false }
})
const emit = defineEmits(['launch'])

const card = computed(() => moduleCard(props.module))
const heading = computed(() =>
  props.module.module === 'woodpecker' && props.module.facts.opening
    ? t('coach.card.woodpeckerNamed', {
        family: props.module.facts.opening.family
      })
    : card.value.title
)
const says = computed(
  () =>
    ({
      woodpecker: t('coach.go.woodpeckerSays'),
      repertoire: t('coach.go.repertoireSays')
    })[props.module.module] ?? t('coach.go.says')
)
const countSub = computed(() => {
  const { module, facts, minutes } = props.module
  if (module === 'woodpecker')
    return t('coach.go.countWoodpecker', {
      family: facts.opening?.family ?? '',
      n: facts.puzzleCount
    })
  if (module === 'repertoire' && facts.repertoires?.[0])
    return t('coach.go.countRepertoire', {
      name: facts.repertoires[0].name,
      n: minutes
    })
  return t('coach.compose.minutes', { n: minutes })
})

const counting = ref(false)
const count = ref(3)
/** @type {ReturnType<typeof setTimeout>[]} */
let timers = []
onBeforeUnmount(() => timers.forEach(clearTimeout))

/** 3, 2, 1, Go !, then the session is launched. */
function countdown() {
  if (counting.value) return
  counting.value = true
  count.value = 3
  ;[2, 1, 0].forEach((n, i) =>
    timers.push(setTimeout(() => (count.value = n), (i + 1) * 1000))
  )
  timers.push(
    setTimeout(() => {
      counting.value = false
      emit('launch')
    }, 3600)
  )
}
</script>

<style scoped lang="scss">
.cs-card--night {
  background: var(--cs-night);
  color: #fff;
}

.cs-steps {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;

  li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    font-size: 14px;
    line-height: 1.45;
  }

  &__n {
    width: 24px;
    height: 24px;
    flex: none;
    border-radius: 8px;
    background: #ffd43b;
    color: var(--cs-night);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 12px;
  }
}

.cs-text--on-night {
  color: #cfc9e2;
}

.cs-reason {
  display: flex;
  gap: 12px;
  align-items: flex-start;

  &__n {
    width: 30px;
    height: 30px;
    flex: none;
    border-radius: 10px;
    background: var(--cs-accent);
    color: var(--cs-night);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 14px;
  }

  &__text {
    font-size: 14px;
    line-height: 1.45;
    text-wrap: pretty;
  }
}

.cs-count {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: var(--cs-night);
  color: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 22px;
  animation: csFade 0.25s both;

  &__label {
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--cs-accent);
    text-align: center;
    padding: 0 16px;
  }

  &__ring {
    position: relative;
    width: 220px;
    height: 220px;

    svg {
      position: absolute;
      inset: 0;
      transform: rotate(-90deg);
    }
  }

  &__track {
    fill: none;
    stroke: rgba(255, 255, 255, 0.12);
    stroke-width: 10;
  }

  &__progress {
    fill: none;
    stroke: var(--cs-accent);
    stroke-width: 10;
    stroke-linecap: round;
    stroke-dasharray: 1;
    animation: csRing 3s linear forwards;
  }

  &__n {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 120px;
    line-height: 1;
    animation: csCount 0.95s cubic-bezier(0.34, 1.4, 0.64, 1) both;

    &--go {
      font-size: 52px;
      color: var(--cs-accent);
    }
  }

  &__sub {
    font-size: 15px;
    font-weight: 700;
  }
}

@media (prefers-reduced-motion: reduce) {
  .cs-count__n,
  .cs-count__progress {
    animation: none;
  }
}
</style>
