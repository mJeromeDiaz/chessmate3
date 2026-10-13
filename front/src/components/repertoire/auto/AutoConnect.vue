<template>
  <div class="ra-screen ra-up">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <div class="ra-albert">
          <img
            :src="profImage('albert-v2-pointer')"
            alt="Albert"
            class="ra-albert__img ra-bob"
          />
          <div class="ra-bubble">
            Montre-moi tes parties. J’observe ce que tu joues vraiment, puis je
            te construis un répertoire qui te ressemble.
          </div>
        </div>
        <h1 class="ra-title">Ton répertoire, tiré de tes vraies parties</h1>
        <div class="ra-actions">
          <button
            type="button"
            class="ra-cta ra-cta--accent ra-cta--big"
            :disabled="!identity || !settings.perfs.length || busy"
            data-testid="auto-start"
            @click="emit('start')"
            >Analyser mes {{ settings.count }} parties</button
          >
        </div>
      </div>

      <div class="ra-cols__main">
        <div class="ra-card">
          <div class="ra-kicker">COMPTE LICHESS</div>
          <div
            v-if="identity"
            class="ra-account ra-fade"
            data-testid="auto-account"
          >
            <div class="ra-account__tile">♞︎</div>
            <div class="ra-account__text">
              <span class="ra-account__name">@{{ identity.username }}</span>
              <span v-if="ratings" class="ra-account__sub">{{ ratings }}</span>
            </div>
            <span class="ra-account__on"
              ><span class="ra-account__dot" />Connecté</span
            >
          </div>
          <template v-else>
            <div class="ra-text">
              Connecte ton compte pour que je puisse lire tes parties. Je ne
              publie rien.
            </div>
            <button
              type="button"
              class="ra-cta ra-cta--night"
              :disabled="linking"
              data-testid="auto-link"
              @click="emit('link')"
            >
              <span class="ra-glyph">♞︎</span
              >{{ linking ? 'Connexion à Lichess…' : 'Connecter Lichess' }}
            </button>
          </template>
        </div>

        <div class="ra-card" :class="{ 'ra-card--off': !identity }">
          <div class="ra-kicker">PARTIES À ANALYSER</div>
          <div class="ra-segment">
            <button
              v-for="n in GAME_COUNTS"
              :key="n"
              type="button"
              class="ra-segment__btn"
              :class="{ 'ra-segment__btn--on': settings.count === n }"
              :data-testid="`auto-count-${n}`"
              @click="emit('count', n)"
              >{{ n }}</button
            >
          </div>
          <div class="ra-kicker q-mt-xs">CADENCES</div>
          <div class="ra-chips">
            <button
              v-for="speed in SPEEDS"
              :key="speed.value"
              type="button"
              class="ra-chip"
              :class="{ 'ra-chip--on': settings.perfs.includes(speed.value) }"
              :data-testid="`auto-speed-${speed.value}`"
              @click="emit('speed', speed.value)"
              >{{ speed.label }}</button
            >
          </div>
        </div>

        <div v-if="error" class="ra-error" data-testid="auto-error">{{
          error
        }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * First step of the "repertoire from my games" page: the linked Lichess account (or a button to
 * link it), how many games and which speeds to read.
 */
import { computed } from 'vue'
import { profImage } from '@/utils/prof/images'
import { GAME_COUNTS, SPEEDS } from '@/utils/repertoireAuto'

const props = defineProps({
  /** The user's Lichess identity (profile), or null when none is linked. */
  identity: { type: Object, default: null },
  /** @type {import('vue').PropType<{count: number, perfs: string[]}>} */
  settings: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  linking: { type: Boolean, default: false },
  error: { type: String, default: '' }
})
const emit = defineEmits({
  link: null,
  start: null,
  /** A number of games chosen. */
  count: n => typeof n === 'number',
  /** A speed checked or unchecked. */
  speed: value => typeof value === 'string'
})

/** "Blitz 1842 · Rapide 1910", from the ratings the profile knows. */
const ratings = computed(() => {
  const labels = Object.fromEntries(SPEEDS.map(s => [s.value, s.label]))
  return Object.entries(props.identity?.ratings ?? {})
    .filter(([perf]) => labels[perf])
    .map(
      ([perf, r]) => `${labels[perf]} ${r.rating}${r.provisional ? '?' : ''}`
    )
    .join(' · ')
})
</script>

<style scoped lang="scss">
.ra-account {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ra-account__tile {
  width: 48px;
  height: 48px;
  flex: none;
  border-radius: 16px;
  background: var(--ra-night);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 28px;
}

.ra-account__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ra-account__name {
  font-weight: 800;
  font-size: 16px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ra-account__sub {
  font-size: 13px;
  color: var(--ra-muted);
}

.ra-account__on {
  height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  background: var(--ra-accent);
  color: var(--ra-night);
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 6px;
  flex: none;
}

.ra-account__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--ra-night);
}

.ra-segment {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 6px;
  background: var(--ra-subtle);
  border-radius: 16px;
  padding: 4px;

  // Six counts: two rows of three on phones.
  @media (max-width: $breakpoint-xs-max) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

.ra-segment__btn {
  height: 42px;
  border-radius: 12px;
  border: none;
  background: transparent;
  color: var(--ra-ink);
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
  cursor: pointer;
  transition:
    background 0.25s,
    color 0.25s;

  &--on {
    background: var(--ra-accent);
    color: var(--ra-night);
  }
}

.ra-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.ra-chip {
  height: 38px;
  padding: 0 14px;
  border-radius: 999px;
  border: 2px solid var(--ra-line);
  background: var(--ra-card);
  color: var(--ra-ink);
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;

  &--on {
    border-color: var(--ra-night);
    background: var(--ra-accent);
    color: var(--ra-night);
  }
}
</style>
