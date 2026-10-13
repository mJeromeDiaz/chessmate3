<template>
  <div class="cs-screen cs-screen--light-text">
    <div class="cs-cols">
      <div class="cs-cols__side">
        <div class="cs-albert cs-albert--medium">
          <img
            :src="profImage(done ? 'albert-v2-idea' : 'albert-v2-book')"
            alt="Albert"
            class="cs-albert__img cs-bob-fast"
          />
          <div class="cs-bubble" data-testid="coach-scan-says">{{
            scanSays(progress, done ? diagnosis : null)
          }}</div>
        </div>

        <div class="cs-meter">
          <div class="cs-meter__odo" aria-hidden="true">
            <div
              v-for="(digit, i) in digits"
              :key="i"
              class="cs-meter__digit"
              :class="{ 'cs-meter__digit--dim': i < firstSignificant }"
            >
              <div
                class="cs-meter__roll"
                :style="{ transform: `translateY(${-digit * 64}px)` }"
              >
                <div v-for="n in 10" :key="n" class="cs-meter__num">{{
                  n - 1
                }}</div>
              </div>
            </div>
          </div>
          <div class="cs-meter__side">
            <div class="cs-meter__line">
              <span>parties lues</span
              ><span class="cs-meter__of">sur {{ maxGames }}</span>
            </div>
            <div class="cs-meter__track">
              <div
                class="cs-meter__fill"
                :style="{ width: `${Math.round(progress * 100)}%` }"
              />
            </div>
            <div class="cs-meter__state">
              <span class="cs-meter__dot" />{{
                done ? 'Analyse terminée' : 'Lecture en cours…'
              }}
            </div>
          </div>
          <span class="sr-only" data-testid="coach-scan-count">{{
            shown
          }}</span>
        </div>

        <div v-if="waiting" class="cs-notice cs-notice--dark">{{
          waiting
        }}</div>
        <div v-if="error" class="cs-error" role="alert">
          {{ error }}
          <button type="button" class="cs-link" @click="emit('restart')"
            >Recommencer</button
          >
        </div>
        <div v-if="done" class="cs-actions">
          <button
            type="button"
            class="cs-cta cs-cta--accent cs-cta--big cs-up"
            data-testid="coach-compose"
            @click="emit('compose')"
            >Composer ma séance</button
          >
        </div>
      </div>

      <div class="cs-cols__main">
        <div class="cs-kicker cs-kicker--light">OUVERTURES REPÉRÉES</div>
        <div
          v-for="row in openingRows"
          :key="row.color"
          class="cs-openings"
          :data-testid="`coach-openings-${row.color}`"
        >
          <span
            class="cs-openings__piece"
            :class="`cs-openings__piece--${row.color}`"
            >{{ row.color === 'white' ? '♔︎' : '♚︎' }}</span
          >
          <span v-if="!row.list.length" class="cs-openings__none">…</span>
          <span
            v-for="(o, i) in row.list"
            :key="o.family"
            class="cs-chip cs-pop"
            :class="{ 'cs-chip--top': done && i === 0 }"
            >{{ o.family }}<b>×{{ o.games }}</b></span
          >
        </div>

        <div class="cs-kicker cs-kicker--light q-mt-sm"
          >FINALES DÉTECTÉES · {{ endgameCards.length }}</div
        >
        <div v-if="!endgameCards.length" class="cs-pending">
          <span class="cs-spinner" />{{
            done
              ? 'Aucune finale dans ces parties.'
              : 'En attente des premières finales…'
          }}
        </div>
        <div v-else class="cs-endgames">
          <div
            v-for="e in endgameCards"
            :key="e.type"
            class="cs-endgame cs-in"
            :class="{ 'cs-endgame--weak': e.weak }"
            :data-testid="`coach-endgame-${e.type}`"
          >
            <div class="cs-endgame__head">
              <span>{{ e.label }}</span>
              <span v-if="e.weak" class="cs-endgame__badge">POINT FAIBLE</span>
            </div>
            <span class="cs-endgame__sub">{{
              e.games > 1 ? `${e.games} parties` : `${e.games} partie`
            }}</span>
            <span v-if="e.slip" class="cs-endgame__slip">{{ e.slip }}</span>
          </div>
        </div>
        <p v-if="done && diagnosis" class="cs-footnote">
          Avantage : +2 selon l’analyse Lichess quand la partie en a une ({{
            diagnosis.analysed
          }}
          sur {{ diagnosis.games }}), sinon 2 points de matériel de plus à
          l’entrée en finale. Laissé filer : la partie n’a pas été gagnée.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Step 2 of the tailored session (design "Session perso"): the games read live (counter, openings
 * by colour, endgames met), then what the diagnosis says (advantages let slip, the weakest kind).
 * The design's accuracy by game phase is left out: nothing measures it.
 */
import { computed } from 'vue'
import { ENDGAME_LABELS, scanSays, slipText } from '@/utils/coach'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  shown: { type: Number, required: true },
  maxGames: { type: Number, required: true },
  progress: { type: Number, required: true },
  done: { type: Boolean, default: false },
  /** @type {import('vue').PropType<{white: Record<string, number>, black: Record<string, number>}>} families met so far */
  openings: { type: Object, required: true },
  /** @type {import('vue').PropType<Record<string, number>>} endgame kinds met so far */
  endgames: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/utils/coach').Diagnosis|null>} */
  diagnosis: { type: Object, default: null },
  waiting: { type: String, default: '' },
  error: { type: String, default: '' }
})
const emit = defineEmits(['compose', 'restart'])

const digits = computed(() =>
  String(Math.min(999, props.shown)).padStart(3, '0').split('').map(Number)
)
const firstSignificant = computed(() => {
  const i = digits.value.findIndex(d => d > 0)
  return i === -1 ? 3 : i
})

/** The three most played families of each colour: the diagnosis once in, the live counts before. */
const openingRows = computed(() =>
  ['white', 'black'].map(color => ({
    color,
    list:
      props.done && props.diagnosis
        ? props.diagnosis[color]
        : Object.entries(props.openings[color] ?? {})
            .map(([family, games]) => ({ family, games }))
            .sort(
              (a, b) => b.games - a.games || a.family.localeCompare(b.family)
            )
            .slice(0, 3)
  }))
)

const endgameCards = computed(() => {
  if (props.done && props.diagnosis) {
    const weakest = props.diagnosis.weakest?.type
    return props.diagnosis.endgames.types.map(e => ({
      type: e.type,
      label: e.label,
      games: e.games,
      slip: e.advantages ? slipText(e) : '',
      weak: e.type === weakest
    }))
  }
  return Object.entries(props.endgames)
    .map(([type, games]) => ({
      type,
      label: ENDGAME_LABELS[type] ?? type,
      games,
      slip: '',
      weak: false
    }))
    .sort((a, b) => b.games - a.games)
})
</script>

<style scoped lang="scss">
.cs-meter {
  background: var(--cs-night);
  border-radius: 24px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  gap: 14px;

  &__odo {
    display: flex;
    gap: 4px;
  }

  &__digit {
    width: 44px;
    height: 64px;
    border-radius: 12px;
    background: #2e2650;
    overflow: hidden;
    position: relative;
    color: #fff;

    &--dim {
      color: #6e6a80;
    }
  }

  &__roll {
    position: absolute;
    inset: 0 0 auto;
    transition: transform 0.22s cubic-bezier(0.3, 1.4, 0.6, 1);
  }

  &__num {
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 48px;
    line-height: 1;
  }

  &__side {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  &__line {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    font-weight: 700;
  }

  &__of {
    color: var(--cs-accent);
  }

  &__track {
    height: 8px;
    border-radius: 8px;
    background: #4c475e;
    overflow: hidden;
  }

  &__fill {
    height: 100%;
    background: var(--cs-accent);
    border-radius: 8px;
    transition: width 0.3s;
  }

  &__state {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
  }

  &__dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--cs-accent);
    animation: csDot 1s infinite;
  }
}

.cs-openings {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-height: 30px;

  &__piece {
    width: 30px;
    height: 30px;
    flex: none;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
    font-size: 18px;

    &--white {
      background: #fff;
      color: var(--cs-night);
    }

    &--black {
      background: var(--cs-night);
      color: #fff;
    }
  }

  &__none {
    font-weight: 700;
    opacity: 0.6;
  }
}

.cs-chip {
  height: 30px;
  padding: 0 10px;
  border-radius: 999px;
  background: #fff;
  color: var(--cs-night);
  font-size: 13px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  transition: background 0.3s;

  b {
    font-family: var(--cm-heading);
    color: #6a4cff;
  }

  &--top {
    background: var(--cs-accent);
  }
}

.cs-pending {
  height: 44px;
  border-radius: 14px;
  border: 2px dashed rgba(255, 255, 255, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
}

.cs-endgames {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}

.cs-endgame {
  padding: 8px 10px;
  border-radius: 14px;
  background: #fff;
  color: var(--cs-night);
  display: flex;
  flex-direction: column;
  gap: 2px;
  transition: background 0.4s;

  &--weak {
    background: #ff6fae;
  }

  &__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 800;
  }

  &__badge {
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    background: var(--cs-night);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    display: flex;
    align-items: center;
    flex: none;
  }

  &__sub {
    font-size: 11px;
    font-weight: 700;
    color: #4c475e;
  }

  &__slip {
    font-size: 12px;
    font-weight: 800;
  }
}

.cs-footnote {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: 1.45;
  opacity: 0.85;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
</style>
