<template>
  <div class="cs-screen cs-up">
    <div class="cs-cols">
      <div class="cs-cols__side">
        <h1 class="cs-title">Ta séance du jour</h1>
        <div class="cs-pills">
          <span class="cs-pill cs-pill--accent" data-testid="coach-minutes"
            >{{ composition.minutes }} min</span
          >
          <span class="cs-pill"
            >{{ played.length }} module{{ played.length > 1 ? 's' : '' }}</span
          >
          <span class="cs-pill"
            >tirée de {{ composition.diagnosis.games }} parties</span
          >
        </div>
        <div v-if="played.length" class="cs-strip" aria-hidden="true">
          <div
            v-for="(m, i) in played"
            :key="i"
            class="cs-strip__part"
            :style="{ flex: m.minutes, background: moduleColor(m) }"
            >{{ m.minutes }}′</div
          >
        </div>
        <div class="cs-albert cs-albert--small">
          <img
            :src="profImage('albert-v2-idea')"
            alt=""
            class="cs-albert__img"
          />
          <div class="cs-bubble cs-bubble--night" data-testid="coach-says">{{
            sessionSays(composition)
          }}</div>
        </div>
        <div class="cs-actions">
          <button
            type="button"
            class="cs-cta cs-cta--night cs-cta--big"
            :disabled="!played.length"
            data-testid="coach-begin"
            @click="emit('begin')"
          >
            Commencer la séance
            <span class="cs-cta__badge">{{ composition.minutes }} min</span>
          </button>
          <router-link to="/session" class="cs-text cs-text--small cs-saved"
            >Enregistrée dans Mes sessions : « Ma séance sur mesure »,
            relançable quand tu veux.</router-link
          >
          <button type="button" class="cs-link" @click="emit('restart')"
            >Refaire l’analyse</button
          >
        </div>
      </div>

      <div class="cs-cols__main">
        <article
          v-for="(m, i) in composition.modules"
          :key="i"
          class="cs-card cs-mcard"
          :class="{ 'cs-mcard--off': !m.included }"
          :style="{ animationDelay: `${0.05 * (i + 1)}s` }"
          :data-testid="`coach-module-${i}`"
        >
          <div class="cs-mcard__head">
            <span
              class="cs-mcard__n"
              :style="{ background: m.included ? moduleColor(m) : undefined }"
              >{{ m.included ? number(i) : '–' }}</span
            >
            <span class="cs-mcard__titles">
              <b>{{ moduleCard(m).title }}</b>
              <span>{{ moduleCard(m).subtitle }}</span>
            </span>
            <span v-if="m.included" class="cs-mcard__time"
              ><span v-if="moduleCard(m).glyph" class="cs-glyph">{{
                moduleCard(m).glyph
              }}</span
              >{{ m.minutes }} min</span
            >
            <span v-else class="cs-mcard__time cs-mcard__time--off"
              >Absent</span
            >
          </div>
          <div class="cs-text">{{ moduleCard(m).text }}</div>
          <router-link
            v-if="m.module === 'repertoire' && m.missing === 'not_asked'"
            to="/repertoire/auto"
            class="cs-link cs-link--inline"
            data-testid="coach-repertoire-missing"
            >Générer mon répertoire →</router-link
          >
        </article>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Step 4 of the tailored session (design "Session perso"): the session of the day, saved as the
 * coach plan (Mes sessions), each module with what the games say about it; a module that could not
 * be included says why. No free time (the design's): the session is what the games ask for.
 */
import { computed } from 'vue'
import {
  moduleCard,
  moduleColor,
  playedModules,
  sessionSays
} from '@/utils/coach'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/coach').Composition>} */
  composition: { type: Object, required: true }
})
const emit = defineEmits(['begin', 'restart'])

const played = computed(() => playedModules(props.composition))

/** @param {number} index a module's index among all: its number among those played */
function number(index) {
  return props.composition.modules
    .slice(0, index + 1)
    .filter(m => m.included).length
}
</script>

<style scoped lang="scss">
.cs-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.cs-strip {
  height: 34px;
  border-radius: 12px;
  display: flex;
  gap: 3px;
  overflow: hidden;

  &__part {
    min-width: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    color: var(--cs-night);
  }
}

.cs-saved {
  text-align: center;
  text-decoration: none;
}

.cs-mcard {
  gap: 8px;
  animation: csUp 0.5s cubic-bezier(0.34, 1.3, 0.64, 1) both;

  &--off {
    background: transparent;
    border: 2px dashed var(--cs-dash);
  }

  &__head {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  &__n {
    width: 34px;
    height: 34px;
    flex: none;
    border-radius: 50%;
    background: var(--cs-subtle);
    color: var(--cs-night);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 16px;
  }

  &__titles {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;

    b {
      font-size: 16px;
    }

    span {
      font-size: 12px;
      color: var(--cs-muted);
    }
  }

  &__time {
    height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    background: var(--cs-night);
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 6px;
    flex: none;

    .cs-glyph {
      font-size: 15px;
    }

    &--off {
      background: var(--cs-subtle);
      color: var(--cs-muted);
    }
  }
}
</style>
