<template>
  <div class="cs-screen cs-screen--light-text">
    <div class="cs-cols">
      <div class="cs-cols__side">
        <div class="cs-forge">
          <div class="cs-orb" aria-hidden="true">
            <svg viewBox="0 0 160 160" class="cs-orb__rings">
              <g class="cs-orb__spin cs-orb__spin--slow">
                <circle
                  v-for="i in 6"
                  :key="i"
                  :cx="80 + 70 * Math.cos((i * Math.PI) / 3)"
                  :cy="80 + 70 * Math.sin((i * Math.PI) / 3)"
                  r="2.5"
                  fill="#fff"
                  opacity="0.7"
                />
              </g>
              <circle
                class="cs-orb__spin"
                cx="80"
                cy="80"
                r="62"
                :stroke="orbColor"
                stroke-width="2"
                stroke-dasharray="4 10"
                fill="none"
                opacity="0.6"
              />
              <circle
                class="cs-orb__spin cs-orb__spin--back"
                cx="80"
                cy="80"
                r="50"
                :stroke="orbColor"
                stroke-width="3"
                stroke-dasharray="30 14 6 14"
                stroke-linecap="round"
                fill="none"
              />
              <circle
                class="cs-orb__spin cs-orb__spin--fast"
                cx="80"
                cy="80"
                r="38"
                :stroke="orbColor"
                stroke-width="2"
                stroke-dasharray="2 6"
                fill="none"
                opacity="0.5"
              />
            </svg>
            <div
              v-if="revealed > 0"
              :key="`flash-${revealed}`"
              class="cs-orb__flash"
              :style="{ background: orbColor }"
            />
            <div
              class="cs-orb__core"
              :style="{
                background: orbColor,
                boxShadow: `0 0 40px 8px ${orbColor}`
              }"
              >{{ composed ? '✓' : '♞︎' }}</div
            >
          </div>
          <div class="cs-forge__words">
            <span class="cs-forge__label">{{ needLabel }}</span>
            <CoachScramble :text="needWords" />
          </div>
        </div>
        <div v-if="error" class="cs-error" role="alert">
          {{ error }}
          <button type="button" class="cs-link" @click="emit('retry')"
            >Réessayer</button
          >
        </div>
        <div v-if="composed" class="cs-actions">
          <button
            type="button"
            class="cs-cta cs-cta--accent cs-cta--big cs-up"
            data-testid="coach-to-session"
            @click="emit('next')"
            >Voir ma séance</button
          >
        </div>
      </div>

      <div class="cs-cols__main">
        <div class="cs-forge__total">
          <span class="cs-kicker cs-kicker--light"
            >TA SÉANCE ·
            {{
              shownModules.length
                ? `${shownModules.length} module${shownModules.length > 1 ? 's' : ''}`
                : 'vide'
            }}</span
          >
          <span class="cs-forge__minutes">{{ minutes }} min</span>
        </div>
        <div
          v-for="(m, i) in shownModules"
          :key="i"
          class="cs-mod cs-mat"
          :class="{ 'cs-mod--off': !m.included }"
          :style="{ background: moduleColor(m) }"
          data-testid="coach-forged"
        >
          <span class="cs-mod__n">{{ i + 1 }}</span>
          <span class="cs-mod__text">
            <b>{{ moduleCard(m).title }}</b>
            <span>{{
              m.included ? needText(m, composition) : 'Pas cette fois'
            }}</span>
          </span>
          <span class="cs-mod__min">{{
            m.included ? `${m.minutes}′` : '—'
          }}</span>
        </div>
        <div v-if="!composed && !error" class="cs-slot">
          <span class="cs-spinner" />{{
            composition && current >= revealed
              ? 'Création du module…'
              : 'Recherche d’un besoin…'
          }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Step 3 of the tailored session (design "Session perso"): the session composed by the API (always
 * the same modules, in the same order), each one forged in turn with the need it answers.
 */
import { computed } from 'vue'
import CoachScramble from '@/components/coach/CoachScramble.vue'
import { moduleCard, moduleColor, needText } from '@/utils/coach'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/coach').Composition|null>} */
  composition: { type: Object, default: null },
  /** The need being shown, -1 before the first. */
  current: { type: Number, default: -1 },
  /** Modules forged so far. */
  revealed: { type: Number, default: 0 },
  composed: { type: Boolean, default: false },
  error: { type: String, default: '' }
})
const emit = defineEmits(['next', 'retry'])

const ACCENT = '#5EE6B0'

const shownModules = computed(
  () => props.composition?.modules.slice(0, props.revealed) ?? []
)
const minutes = computed(() =>
  shownModules.value
    .filter(m => m.included)
    .reduce((sum, m) => sum + m.minutes, 0)
)
const currentModule = computed(() =>
  props.composition && props.current >= 0 && !props.composed
    ? props.composition.modules[props.current]
    : null
)
const orbColor = computed(() =>
  props.composed
    ? ACCENT
    : currentModule.value
      ? moduleColor(currentModule.value)
      : '#8B6BFF'
)
const needLabel = computed(() =>
  props.composed
    ? 'SÉANCE COMPOSÉE'
    : currentModule.value
      ? `BESOIN N°${props.current + 1}`
      : 'JE CROISE TES PARTIES'
)
const needWords = computed(() => {
  if (props.composed && props.composition) {
    const n = props.composition.modules.filter(m => m.included).length
    return `${n} module${n > 1 ? 's' : ''}, rien que pour toi.`
  }
  return currentModule.value && props.composition
    ? needText(currentModule.value, props.composition)
    : 'Je cherche ce dont tu as besoin…'
})
</script>

<style scoped lang="scss">
.cs-forge {
  position: relative;
  border-radius: 26px;
  background: #241d3d;
  padding: 18px 18px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  min-height: 250px;

  &__words {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    text-align: center;
  }

  &__label {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--cs-accent);
  }

  &__total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
  }

  &__minutes {
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 22px;
    color: var(--cs-accent);
  }
}

.cs-orb {
  position: relative;
  width: 160px;
  height: 160px;
  flex: none;

  &__rings {
    position: absolute;
    inset: 0;
    overflow: visible;
  }

  &__spin {
    transform-box: view-box;
    transform-origin: 80px 80px;
    animation: csSpin 9s linear infinite;
    transition: stroke 0.5s;

    &--slow {
      animation-duration: 14s;
    }

    &--back {
      animation: csSpinBack 5s linear infinite;
    }

    &--fast {
      animation-duration: 3s;
    }
  }

  &__flash {
    position: absolute;
    left: 50px;
    top: 50px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    animation: csFlash 0.8s ease-out both;
  }

  &__core {
    position: absolute;
    left: 52px;
    top: 52px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
    font-size: 30px;
    color: var(--cs-night);
    animation: csBreath 1.6s ease-in-out infinite;
    transition:
      background 0.5s,
      box-shadow 0.5s;
  }
}

.cs-mod {
  min-height: 48px;
  border-radius: 16px;
  color: var(--cs-night);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 12px;

  &--off {
    opacity: 0.55;
  }

  &__n {
    width: 28px;
    height: 28px;
    flex: none;
    border-radius: 50%;
    background: var(--cs-night);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 13px;
  }

  &__text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;

    b {
      font-size: 14px;
      line-height: 1.15;
    }

    span {
      font-size: 12px;
      font-weight: 700;
      color: #4c475e;
    }
  }

  &__min {
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 16px;
  }
}

.cs-slot {
  min-height: 48px;
  border-radius: 16px;
  border: 2px dashed rgba(255, 255, 255, 0.3);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  color: #cfc9e2;
}
</style>
