<template>
  <div
    ref="screen"
    class="boss-victory"
    role="dialog"
    aria-modal="true"
    tabindex="-1"
    :aria-label="$t('mindMapPath.victory.kicker')"
    data-testid="boss-victory"
    @keydown.esc="emit('continue')"
  >
    <ConfettiBurst :key="step.key" :count="140" pieces />
    <div class="boss-victory__rays" aria-hidden="true" />
    <div class="boss-victory__col">
      <span class="boss-victory__kicker">{{
        $t('mindMapPath.victory.kicker')
      }}</span>
      <h2 class="boss-victory__name">{{ bossName(step) }}</h2>
      <BossPortrait
        class="boss-victory__portrait"
        :character="step.character"
        pose="beaten"
        :label="bossName(step)"
      />
      <div class="boss-victory__bubble">« {{ bossLine(step, 'defeat') }} »</div>
      <div class="boss-victory__gains">
        <span v-if="xp > 0" class="boss-victory__xp">{{
          $t('mindMapPath.sheet.xp', { xp })
        }}</span>
        <span
          v-for="badge in step.badges"
          :key="badge"
          class="boss-victory__badge"
          ><q-icon name="military_tech" />{{ badgeName(badge) }}</span
        >
      </div>
      <button
        type="button"
        class="boss-victory__action"
        data-testid="boss-victory-continue"
        @click="emit('continue')"
      >
        {{
          chest
            ? $t('mindMapPath.victory.openChest')
            : $t('mindMapPath.victory.continue')
        }}
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * A boss beaten (docs/MIND_MAP.md): its defeated face, its last words, the XP and badges won, then
 * its chest. A stand-in until the bosses' own animations (Claude Design) arrive: a burst of
 * confetti and pieces, turning rays.
 */
import { onMounted, ref } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import { badgeName, bossLine, bossName } from '@/utils/mindMapPath'

defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep|{key: string, kind: string, character: string|null, badges: string[]}>} */
  step: { type: Object, required: true },
  /** The XP of the victory. */
  xp: { type: Number, default: 0 },
  /** A chest comes next. */
  chest: { type: Boolean, default: false }
})

const emit = defineEmits({ continue: null })

const screen = ref(/** @type {HTMLDivElement|null} */ (null))
onMounted(() => screen.value?.focus())
</script>

<style scoped lang="scss">
.boss-victory {
  position: fixed;
  inset: 0;
  z-index: 6050;
  display: grid;
  place-items: center;
  overflow: hidden;
  background: radial-gradient(circle at 50% 40%, #5a3be0, #1b1530 70%);
  color: #fff;

  &:focus {
    outline: none;
  }
}

.boss-victory__rays {
  position: absolute;
  left: 50%;
  top: 40%;
  width: 160vmax;
  height: 160vmax;
  margin: -80vmax 0 0 -80vmax;
  background: repeating-conic-gradient(
    rgba(255, 255, 255, 0.07) 0 10deg,
    transparent 10deg 20deg
  );
  animation: boss-victory-turn 24s linear infinite;
}

@keyframes boss-victory-turn {
  to {
    transform: rotate(360deg);
  }
}

.boss-victory__col {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  width: min(440px, 100vw);
  padding: 24px;
  text-align: center;
}

.boss-victory__kicker {
  padding: 6px 16px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  animation: boss-victory-pop 0.5s cubic-bezier(0.2, 1.6, 0.4, 1) both;
}

.boss-victory__name {
  margin: 0;
  font-family: var(--cm-heading);
  font-size: 34px;
  font-weight: 800;
}

.boss-victory__portrait {
  width: min(220px, 56vw);
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.4);
  animation: boss-victory-fall 0.9s 0.2s cubic-bezier(0.3, 1.5, 0.5, 1) both;
}

@keyframes boss-victory-pop {
  from {
    transform: scale(0.4);
    opacity: 0;
  }
}

@keyframes boss-victory-fall {
  from {
    transform: translateY(-40px) rotate(12deg) scale(1.15);
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .boss-victory__rays,
  .boss-victory__kicker,
  .boss-victory__portrait {
    animation: none;
  }
}

.boss-victory__bubble {
  padding: 10px 14px;
  border-radius: 16px;
  background: #fff;
  color: #1b1530;
  font-weight: 700;
}

.boss-victory__gains {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 8px;
}

.boss-victory__xp,
.boss-victory__badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 999px;
  font-weight: 800;
}

.boss-victory__xp {
  background: #c6f432;
  color: #1b1530;
}

.boss-victory__badge {
  background: rgba(255, 255, 255, 0.14);
}

.boss-victory__action {
  margin-top: 8px;
  padding: 14px 28px;
  border: none;
  border-radius: 16px;
  background: #ffd43b;
  color: #1b1530;
  font: inherit;
  font-size: 17px;
  font-weight: 800;
  box-shadow: 0 6px 0 #c99a00;
  cursor: pointer;
}
</style>
