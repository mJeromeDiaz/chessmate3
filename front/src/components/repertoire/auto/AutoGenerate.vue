<template>
  <div class="ra-screen ra-screen--light-text">
    <div class="ra-cols">
      <div class="ra-cols__side">
        <div class="ra-albert ra-albert--medium">
          <img
            :src="profImage(phase >= 5 ? 'albert-v2-idea' : 'albert-v2-think')"
            alt="Albert"
            class="ra-albert__img ra-bob"
          />
          <div class="ra-bubble ra-gen__bubble">
            <span class="ra-gen__kicker">{{
              $t('repertoireAuto.gen.kicker')
            }}</span>
            <span
              :key="tick"
              class="ra-gen__ticker ra-in"
              data-testid="auto-ticker"
              >{{ ticker }}</span
            >
          </div>
        </div>

        <div class="ra-gen__phases">
          <div
            v-for="(p, i) in PHASES"
            :key="p"
            class="ra-gen__phase"
            :class="{
              'ra-gen__phase--done': phase > i + 1 || phase >= 5,
              'ra-gen__phase--active': phase === i + 1
            }"
          >
            <span class="ra-gen__mark">{{
              phase > i + 1 || phase >= 5 ? '✓' : ''
            }}</span
            >{{ $t(`repertoireAuto.gen.phases.${p}`) }}
          </div>
        </div>

        <div class="ra-gen__title" data-testid="auto-gen-title">{{
          phase >= 5
            ? $t('repertoireAuto.gen.ready')
            : $t('repertoireAuto.gen.building')
        }}</div>
      </div>

      <div class="ra-cols__main">
        <div class="ra-gen__stage">
          <AutoGenTree :trees="trees" :phase="phase" />
          <div v-if="phase >= 1 && phase < 5" class="ra-gen__scan" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Fourth step: the repertoire being built. The phases follow the real work: the trees asked of
 * the server, the lines merged, the line ends evaluated by the Lichess cloud, the critical
 * moments found. Albert reads out the user's figures meanwhile.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import AutoGenTree from '@/components/repertoire/auto/AutoGenTree.vue'
import { profImage } from '@/utils/prof/images'
import { tickerPhrases } from '@/utils/repertoireAuto'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/repertoireAuto').Tree[]>} */
  trees: { type: Array, required: true },
  /** 1 to 4: the phase in progress; 5: done. */
  phase: { type: Number, required: true }
})

/** The four phases (their words: repertoireAuto.gen.phases). */
const PHASES = [0, 1, 2, 3]

const tick = ref(0)
const timer = setInterval(() => tick.value++, 1300)
onBeforeUnmount(() => clearInterval(timer))

const ticker = computed(() => {
  if (props.phase >= 5) {
    const lines = props.trees.flatMap(tree => tree.lines)
    const risk = lines.filter(l => l.tag === 'risk').length
    return t('repertoireAuto.gen.done', { n: lines.length, risk }, lines.length)
  }
  const phrases = tickerPhrases(props.trees)
  return phrases[tick.value % phrases.length]
})
</script>

<style scoped lang="scss">
.ra-gen__bubble {
  min-height: 64px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
}

.ra-gen__kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--ra-brand);
}

.ra-gen__ticker {
  font-size: 14px;
  line-height: 1.4;
  font-weight: 600;
}

.ra-gen__stage {
  position: relative;
  height: 262px;
  border-radius: 24px;
  background: #241d3d;
  overflow: hidden;

  @media (min-width: 1024px) {
    height: 420px;
  }
}

.ra-gen__scan {
  position: absolute;
  inset: 0 0 auto;
  height: 40px;
  background: linear-gradient(
    to bottom,
    transparent,
    rgba(79, 178, 255, 0.28),
    transparent
  );
  animation: ra-scan 1.8s linear infinite;
  pointer-events: none;
}

.ra-gen__phases {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 8px 0;
}

.ra-gen__phase {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 15px;
  font-weight: 700;
  color: #8e89a3;
  transition: color 0.3s;

  &--active,
  &--done {
    color: #fff;
  }
}

.ra-gen__mark {
  width: 28px;
  height: 28px;
  flex: none;
  border-radius: 50%;
  border: 2px solid #4c475e;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ra-night);
  font-size: 14px;
  font-weight: 800;

  .ra-gen__phase--active & {
    border-top-color: #4fb2ff;
    animation: ra-spin 0.8s linear infinite;
  }

  .ra-gen__phase--done & {
    background: var(--ra-accent);
    border-color: var(--ra-accent);
  }
}

.ra-gen__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 24px;
  line-height: 1.1;
  text-align: center;
}
</style>
