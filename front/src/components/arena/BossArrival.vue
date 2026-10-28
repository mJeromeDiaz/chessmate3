<template>
  <q-dialog
    :model-value="open"
    maximized
    persistent
    transition-show="fade"
    transition-hide="fade"
  >
    <!-- A div: Quasar only lets a dialog's direct div children take the clicks. -->
    <div
      v-if="current"
      :key="current.character"
      class="arrival"
      :class="{ 'arrival--still': still }"
      :style="{
        '--dark': colors.dark,
        '--glow': colors.glow,
        '--accent': colors.accent
      }"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('arena.arrival.label', { name })"
      data-testid="arena-arrival"
      :data-boss="current.character"
    >
      <span class="arrival__rays" aria-hidden="true" />
      <span class="arrival__glow" aria-hidden="true" />

      <div class="arrival__kicker">
        {{
          queue.length > 1
            ? $t('arena.arrival.many', {
                n: index + 1,
                total: queue.length
              })
            : $t('arena.arrival.one')
        }}
      </div>

      <div class="arrival__stage">
        <img
          v-if="picture.picture"
          :src="picture.picture"
          alt=""
          class="arrival__boss"
          draggable="false"
        />
        <span v-else class="arrival__glyph" aria-hidden="true">{{
          picture.glyph
        }}</span>
      </div>

      <div class="arrival__plate">
        <h2 class="arrival__name" :aria-label="name">
          <span aria-hidden="true">{{ typed }}</span
          ><span
            v-if="typed.length < name.length"
            class="arrival__caret"
            aria-hidden="true"
          />
        </h2>
        <div class="arrival__details" :class="{ 'arrival__details--on': done }">
          <div class="arrival__chips">
            <span class="arrival__chip arrival__chip--level">{{
              $t('arena.level', { n: current.level })
            }}</span>
            <span class="arrival__chip">{{
              $t('arena.card.elo', { elo: current.elo })
            }}</span>
          </div>
          <p class="arrival__quote">
            « {{ bossText(current.character, 'taunt') }} »
          </p>
          <div class="arrival__actions">
            <button
              v-if="index < queue.length - 1"
              type="button"
              class="arrival__btn arrival__btn--main"
              data-testid="arena-arrival-next"
              @click="next"
            >
              {{ $t('arena.arrival.next') }}
            </button>
            <template v-else>
              <button
                type="button"
                class="arrival__btn arrival__btn--main"
                data-testid="arena-arrival-challenge"
                @click="challenge"
              >
                {{ $t('arena.arrival.challenge') }}
              </button>
              <button
                type="button"
                class="arrival__btn"
                data-testid="arena-arrival-close"
                @click="finish"
              >
                {{ $t('arena.arrival.later') }}
              </button>
            </template>
          </div>
        </div>
      </div>

      <span class="arrival__gate arrival__gate--left" aria-hidden="true" />
      <span class="arrival__gate arrival__gate--right" aria-hidden="true" />
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * A boss arriving in the player's arena (docs/ARENA.md, § 5), in the layout: the arena's gates
 * open, rays turn, the boss rises, its name is typed, then its level, its Elo and its taunt. Several
 * at once (the arena's opening): one modal that shows them one after the other. Never during a run
 * (but right after a path's boss victory and its chests) nor a game of the arena, nor over the
 * streak's celebration, the wheels, the chests, an admin's gifts or an announcement.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAnnouncementStore } from '@/stores/announcement'
import { useArenaStore } from '@/stores/arena'
import { useGamificationStore } from '@/stores/gamification'
import { useShopStore } from '@/stores/shop'
import { arrivalAllowed, bossText, cardPicture, paletteOf } from '@/utils/arena'

/** The name starts being typed once the boss has risen (ms), one letter after the other. */
const TYPE_FROM_MS = 1700
const TYPE_EVERY_MS = 55

const arena = useArenaStore()
const announcements = useAnnouncementStore()
const shop = useShopStore()
const gamification = useGamificationStore()
const route = useRoute()
const router = useRouter()

/** @type {import('vue').Ref<import('@/utils/arena').ArenaBoss[]>} the bosses of this modal */
const queue = ref([])
const index = ref(0)
const typed = ref('')
const still =
  typeof window !== 'undefined' &&
  !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

const busy = computed(
  () =>
    !arrivalAllowed(route.path, arena.afterRun) ||
    !!gamification.celebration ||
    shop.dailyChests.length > 0 ||
    !!shop.handWheel ||
    shop.ticketWheel ||
    (shop.state?.gifts?.length ?? 0) > 0 ||
    announcements.items.length > 0
)
const current = computed(() => queue.value[index.value] ?? null)
const open = computed(() => current.value !== null)
const name = computed(() =>
  current.value ? bossText(current.value.character, 'name') : ''
)
const done = computed(() => typed.value === name.value)
const colors = computed(() => paletteOf(current.value?.character ?? ''))
const picture = computed(() => cardPicture(current.value?.character ?? ''))

// The modal takes the bosses waiting when it may open, and keeps them until it closes.
watch(
  () => !busy.value && arena.pending.length > 0 && queue.value.length === 0,
  ready => {
    if (!ready) return
    queue.value = [...arena.pending]
    index.value = 0
  },
  { immediate: true }
)

// Leaving the run's page: its arrival waits for the next one allowed.
watch(
  () => route.path,
  () => {
    arena.afterRun = false
  }
)

/** @type {ReturnType<typeof setTimeout>|undefined} */
let typer

watch(
  name,
  value => {
    clearTimeout(typer)
    typed.value = still ? value : ''
    if (still || !value) return
    const type = () => {
      typed.value = value.slice(0, typed.value.length + 1)
      if (typed.value.length < value.length)
        typer = setTimeout(type, TYPE_EVERY_MS)
    }
    typer = setTimeout(type, TYPE_FROM_MS)
  },
  { immediate: true }
)

function next() {
  index.value++
}

/** Closed: every boss of the modal was shown. */
function finish() {
  const shown = queue.value.map(boss => boss.character)
  queue.value = []
  index.value = 0
  arena.announce(shown)
}

function challenge() {
  const boss = current.value?.character
  finish()
  if (boss) router.push({ path: '/arene', query: { boss } })
}

function onVisibility() {
  if (document.visibilityState === 'visible') arena.load()
}

onMounted(() => {
  arena.load()
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearTimeout(typer)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<style scoped lang="scss">
.arrival {
  position: relative;
  width: 100vw;
  height: 100dvh;
  overflow: hidden;
  background: #0e0a1a;
  color: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  padding: 24px 20px 32px;
  box-sizing: border-box;
}

.arrival__rays {
  position: absolute;
  left: 50%;
  top: 42%;
  width: 160vmax;
  height: 160vmax;
  transform: translate(-50%, -50%);
  background: repeating-conic-gradient(
    from 0deg,
    rgba(255, 255, 255, 0.09) 0deg 8deg,
    transparent 8deg 22deg
  );
  mask-image: radial-gradient(circle, #000 10%, transparent 55%);
  opacity: 0;
  animation:
    arrival-fade 0.8s ease-out 0.9s forwards,
    arrival-spin 30s linear infinite;
}

.arrival__glow {
  position: absolute;
  left: 50%;
  top: 42%;
  width: 640px;
  height: 640px;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle, var(--glow), transparent 64%);
  opacity: 0;
  animation: arrival-fade 0.8s ease-out 0.8s forwards;
}

.arrival__kicker {
  position: absolute;
  top: 24px;
  left: 0;
  right: 0;
  z-index: 3;
  text-align: center;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.12em;
  color: var(--accent);
}

.arrival__stage {
  position: relative;
  z-index: 1;
  flex: 1;
  min-height: 0;
  width: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.arrival__boss {
  max-height: min(56vh, 520px);
  max-width: 92vw;
  object-fit: contain;
  object-position: bottom;
  opacity: 0;
  transform: translateY(40%) scale(0.85);
  animation: arrival-rise 0.8s cubic-bezier(0.34, 1.4, 0.64, 1) 1s forwards;
  filter: drop-shadow(0 18px 40px rgba(0, 0, 0, 0.55));
}

.arrival__glyph {
  font-size: min(36vh, 260px);
  line-height: 1;
  color: var(--accent);
  opacity: 0;
  transform: translateY(40%) scale(0.85);
  animation: arrival-rise 0.8s cubic-bezier(0.34, 1.4, 0.64, 1) 1s forwards;
  text-shadow: 0 18px 40px rgba(0, 0, 0, 0.55);
}

.arrival__plate {
  position: relative;
  z-index: 2;
  width: 100%;
  max-width: 520px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
}

.arrival__name {
  margin: 0;
  min-height: 1em;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: clamp(34px, 8vw, 56px);
  line-height: 1;
  text-shadow: 0 4px 0 rgba(0, 0, 0, 0.35);
}

.arrival__caret {
  display: inline-block;
  width: 0.08em;
  height: 0.9em;
  margin-left: 0.06em;
  background: var(--accent);
  vertical-align: -0.08em;
  animation: arrival-blink 0.7s steps(1) infinite;
}

.arrival__details {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: 100%;
  opacity: 0;
  transform: translateY(12px);
  transition:
    opacity 0.4s ease-out,
    transform 0.4s ease-out;
  pointer-events: none;
}

.arrival__details--on {
  opacity: 1;
  transform: none;
  pointer-events: auto;
}

.arrival__chips {
  display: flex;
  gap: 6px;
}

.arrival__chip {
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 0.06em;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
}

.arrival__chip--level {
  background: var(--accent);
  color: #1b1530;
}

.arrival__quote {
  margin: 0;
  background: #fff;
  color: #1b1530;
  border-radius: 18px;
  padding: 10px 16px;
  font-size: 15px;
  line-height: 1.4;
  font-style: italic;
  text-wrap: pretty;
}

.arrival__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
  width: 100%;
}

.arrival__btn {
  min-width: 150px;
  height: 52px;
  padding: 0 22px;
  border: none;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font: inherit;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;
}

.arrival__btn--main {
  background: var(--cm-lime);
  color: #1b1530;
  box-shadow: 0 4px 0 #8fb21a;
}

.arrival__gate {
  position: absolute;
  top: 0;
  bottom: 0;
  z-index: 4;
  width: 50%;
  background:
    repeating-linear-gradient(
      0deg,
      rgba(0, 0, 0, 0.18) 0 2px,
      transparent 2px 46px
    ),
    linear-gradient(90deg, #2a2340, #3a3158 60%, #2a2340);
  animation: arrival-gate 0.9s cubic-bezier(0.7, 0, 0.3, 1) 0.3s forwards;
  pointer-events: none;
}

.arrival__gate--left {
  left: 0;
  border-right: 3px solid #1b1530;
  --gate-to: -100%;
}

.arrival__gate--right {
  right: 0;
  border-left: 3px solid #1b1530;
  --gate-to: 100%;
}

// Reduced motion: everything in place at once.
.arrival--still {
  .arrival__rays,
  .arrival__glow,
  .arrival__boss,
  .arrival__glyph {
    animation: none;
    opacity: 1;
    transform: none;
  }
  .arrival__rays {
    transform: translate(-50%, -50%);
  }
  .arrival__glow {
    transform: translate(-50%, -50%);
  }
  .arrival__gate {
    display: none;
  }
  .arrival__details {
    transition: none;
  }
}

@keyframes arrival-gate {
  to {
    transform: translateX(var(--gate-to));
  }
}

@keyframes arrival-fade {
  to {
    opacity: 1;
  }
}

@keyframes arrival-spin {
  to {
    rotate: 360deg;
  }
}

@keyframes arrival-rise {
  to {
    opacity: 1;
    transform: none;
  }
}

@keyframes arrival-blink {
  50% {
    opacity: 0;
  }
}
</style>
