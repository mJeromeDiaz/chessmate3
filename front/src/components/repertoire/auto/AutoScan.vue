<template>
  <div class="ra-screen ra-screen--light-text">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <div class="ra-albert">
          <img
            :src="profImage(done ? 'albert-v2-idea' : 'albert-v2-book')"
            alt="Albert"
            class="ra-albert__img ra-bob ra-bob--fast"
          />
          <div class="ra-bubble" data-testid="auto-scan-say">{{ say }}</div>
        </div>

        <div v-if="done" class="ra-actions">
          <button
            v-if="hasFamilies"
            type="button"
            class="ra-cta ra-cta--accent ra-cta--big ra-up"
            data-testid="auto-to-select"
            @click="emit('next')"
            >Voir la synthèse</button
          >
          <button
            v-else
            type="button"
            class="ra-cta ra-cta--accent ra-cta--big ra-up"
            data-testid="auto-restart"
            @click="emit('restart')"
            >Changer de réglages</button
          >
        </div>

        <div class="ra-odo">
          <div
            class="ra-odo__digits"
            :aria-label="`${shown} parties analysées`"
          >
            <div v-for="(digit, i) in digits" :key="i" class="ra-odo__wheel">
              <div
                class="ra-odo__strip"
                :style="{ transform: `translateY(${-digit.value * 84}px)` }"
              >
                <div
                  v-for="n in 10"
                  :key="n"
                  class="ra-odo__num"
                  :class="{ 'ra-odo__num--lead': digit.lead }"
                  >{{ n - 1 }}</div
                >
              </div>
            </div>
          </div>
          <div class="ra-odo__legend">
            <span>parties analysées</span>
            <span class="ra-odo__of" data-testid="auto-scan-count"
              >{{ shown }} sur {{ maxGames }}</span
            >
          </div>
          <div class="ra-odo__track">
            <div
              class="ra-odo__fill"
              :style="{ width: `${progress * 100}%` }"
            />
          </div>
        </div>
      </div>

      <div class="ra-cols__main">
        <div>
          <div class="ra-kicker ra-kicker--light"
            >OUVERTURES REPÉRÉES · {{ tags.length }}</div
          >
          <div class="ra-found">
            <span
              v-for="tag in tags"
              :key="tag.key"
              class="ra-found__tag ra-pop"
              >{{ tag.family }}<b>×{{ tag.n }}</b></span
            >
          </div>
        </div>

        <div class="ra-feed">
          <div class="ra-kicker ra-kicker--light ra-feed__title"
            ><span class="ra-feed__live" />EN DIRECT DE LICHESS</div
          >
          <div
            v-for="(game, i) in reversed"
            :key="game.key"
            class="ra-feed__item"
            :style="{ opacity: 1 - i * 0.2 }"
            data-testid="auto-feed-item"
          >
            <span
              class="ra-feed__badge"
              :style="{ background: badge(game).color }"
              >{{ badge(game).letter }}</span
            >
            <span class="ra-feed__text">
              <span class="ra-feed__who"
                >vs {{ game.opponent ?? 'Anonyme'
                }}{{ game.rating ? ` · ${game.rating}` : '' }}</span
              >
              <span class="ra-feed__opening">{{ game.family }}</span>
            </span>
          </div>
          <div v-if="waiting" class="ra-feed__wait">{{ waiting }}</div>
        </div>

        <div v-if="error" class="ra-error">{{ error }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Second step: the user's games read batch by batch, with a rolling counter, the openings met so
 * far and the games scrolling by (the real ones, as Lichess sends them).
 */
import { computed } from 'vue'
import { profImage } from '@/utils/prof/images'
import { resultBadge, scanSay } from '@/utils/repertoireAuto'

const props = defineProps({
  /** Games revealed so far. */
  shown: { type: Number, required: true },
  maxGames: { type: Number, required: true },
  /** Every game is in, and revealed. */
  done: { type: Boolean, default: false },
  /** Opening families the synthesis kept (played often enough). */
  families: { type: Number, default: 0 },
  /** @type {import('vue').PropType<any[]>} the live feed, newest last */
  feed: { type: Array, required: true },
  /** @type {import('vue').PropType<Record<string, {family: string, color: string, n: number}>>} */
  found: { type: Object, required: true },
  waiting: { type: String, default: '' },
  error: { type: String, default: '' }
})
const emit = defineEmits(['next', 'restart'])

const progress = computed(() =>
  props.done ? 1 : Math.min(1, props.shown / Math.max(1, props.maxGames))
)
const hasFamilies = computed(() => props.families > 0)
const say = computed(() =>
  scanSay(progress.value, props.done, props.shown, props.families)
)

/** One wheel per digit of the number of games wanted; leading zeros dimmed. */
const digits = computed(() => {
  const width = String(props.maxGames).length
  const text = String(props.shown).padStart(width, '0')
  const firstSignificant = text.search(/[1-9]/)
  return [...text].map((d, i) => ({
    value: Number(d),
    lead: firstSignificant === -1 || i < firstSignificant
  }))
})

/** The openings met, most played first (a handful). */
const tags = computed(() =>
  Object.entries(props.found)
    .map(([key, f]) => ({ key, ...f }))
    .sort((a, b) => b.n - a.n)
    .slice(0, 8)
)

const reversed = computed(() => [...props.feed].reverse())

/** @param {{result: number}} game */
const badge = game => resultBadge(game.result)
</script>

<style scoped lang="scss">
.ra-odo {
  background: var(--ra-night);
  border-radius: 26px;
  padding: 16px 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  color: #fff;
}

.ra-odo__digits {
  display: flex;
  gap: 4px;
}

.ra-odo__wheel {
  width: 58px;
  height: 84px;
  border-radius: 14px;
  background: #2e2650;
  overflow: hidden;
  position: relative;
}

.ra-odo__strip {
  position: absolute;
  inset: 0 0 auto;
  display: flex;
  flex-direction: column;
  transition: transform 0.22s cubic-bezier(0.3, 1.4, 0.6, 1);
}

.ra-odo__num {
  height: 84px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 64px;
  line-height: 1;
  color: #fff;

  &--lead {
    color: #6e6a80;
  }
}

.ra-odo__legend {
  display: flex;
  justify-content: space-between;
  align-self: stretch;
  font-size: 13px;
  font-weight: 700;
}

.ra-odo__of {
  color: var(--ra-accent);
}

.ra-odo__track {
  align-self: stretch;
  height: 8px;
  border-radius: 8px;
  background: #4c475e;
  overflow: hidden;
}

.ra-odo__fill {
  height: 100%;
  background: var(--ra-accent);
  border-radius: 8px;
  transition: width 0.3s;
}

.ra-found {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-height: 72px;
  align-content: flex-start;
  margin-top: 8px;
}

.ra-found__tag {
  height: 30px;
  padding: 0 10px;
  border-radius: 999px;
  background: #fff;
  color: var(--ra-night);
  font-size: 13px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;

  b {
    font-family: var(--cm-heading);
    color: var(--ra-brand);
  }
}

.ra-feed {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-height: 210px;
}

.ra-feed__title {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 2px;
}

.ra-feed__live {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--ra-accent);
  animation: ra-dot 1s infinite;
}

.ra-feed__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.14);
  animation: ra-in 0.4s cubic-bezier(0.34, 1.5, 0.64, 1) both;
}

.ra-feed__badge {
  width: 30px;
  height: 30px;
  flex: none;
  border-radius: 10px;
  color: var(--ra-night);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}

.ra-feed__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.ra-feed__who {
  font-weight: 700;
  font-size: 13px;
}

.ra-feed__opening {
  font-size: 12px;
}

.ra-feed__wait {
  font-size: 13px;
  font-weight: 600;
  color: var(--ra-accent);
}
</style>
