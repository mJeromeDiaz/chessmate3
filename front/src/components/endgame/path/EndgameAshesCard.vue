<template>
  <button
    type="button"
    class="ashes-card"
    :class="{
      'ashes-card--chosen': chosen,
      'ashes-card--beaten': beaten
    }"
    :data-testid="`path-step-${step.key}`"
    :data-state="beaten ? 'beaten' : 'open'"
    @click="emit('select', step)"
  >
    <span class="ashes-card__portrait">
      <BossPortrait
        :character="step.character"
        :mood="beaten ? 'sadness' : 'pride'"
        :label="bossName(step)"
      />
      <span v-if="beaten" class="ashes-card__mark">✓</span>
      <span
        v-if="next"
        class="ashes-card__star"
        :title="$t('endgame.path.next')"
        >★</span
      >
    </span>
    <span class="ashes-card__body">
      <span class="ashes-card__kicker">{{
        $t('endgame.path.ashes.kicker')
      }}</span>
      <span class="ashes-card__name">{{ bossName(step) }}</span>
      <span class="ashes-card__tagline">{{ bossLine(step, 'tagline') }}</span>
      <span class="ashes-card__chips">
        <span class="ashes-card__chip">{{
          $t('endgame.path.positions', { n: ASHES_POSITIONS })
        }}</span>
        <span class="ashes-card__chip">{{
          $t('endgame.path.ashes.clock')
        }}</span>
        <span class="ashes-card__chip">{{
          $t('endgame.path.lives', { n: BOSS_LIVES })
        }}</span>
      </span>
      <span class="ashes-card__rewards">
        <span class="ashes-card__reward"
          ><CrystalGem :size="18" />{{
            $t('mindMapPath.sheet.crystals', { n: step.crystals })
          }}</span
        >
        <span class="ashes-card__reward">{{
          $t('mindMapPath.sheet.xp', { xp: step.xp })
        }}</span>
        <span
          v-for="badge in step.badges"
          :key="badge"
          class="ashes-card__reward"
          ><q-icon name="military_tech" />{{ badgeName(badge) }}</span
        >
      </span>
      <span class="ashes-card__state">{{
        beaten ? $t('endgame.path.bossBeaten') : $t('endgame.path.ashes.all')
      }}</span>
    </span>
  </button>
</template>

<script setup>
/**
 * The Roi des cendres, the endgames path's last boss (docs/ENDGAMES.md, § 10): a card of its own
 * under the mini-parcours (chosen 2026-10-24), its face large (the shark for now), what the fight
 * asks and what it gives.
 */
import { computed } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { ASHES_POSITIONS, bossBeaten } from '@/utils/endgamePath'
import { badgeName, BOSS_LIVES, bossLine, bossName } from '@/utils/mindMapPath'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep>} */
  step: { type: Object, required: true },
  /** Shown in full. */
  chosen: { type: Boolean, default: false },
  /** The step to play next. */
  next: { type: Boolean, default: false }
})

const emit = defineEmits(['select'])

const beaten = computed(() => bossBeaten(props.step))
</script>

<style scoped lang="scss">
.ashes-card {
  display: flex;
  align-items: center;
  gap: 22px;
  width: 100%;
  padding: 20px 24px;
  border: none;
  border-radius: 24px;
  background: linear-gradient(135deg, #2a1d3d, #3d1f1a 70%, #5a2a12);
  color: #fff;
  text-align: left;
  cursor: pointer;
  box-shadow: inset 0 0 0 2px rgba(255, 138, 61, 0.55);

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }

  &--chosen {
    box-shadow:
      inset 0 0 0 2px #ff8a3d,
      0 0 0 4px rgba(255, 138, 61, 0.25);
  }

  @media (max-width: $breakpoint-xs-max) {
    flex-direction: column;
    align-items: stretch;
    padding: 18px;
  }
}

.ashes-card__portrait {
  position: relative;
  flex: none;
  width: 150px;

  @media (max-width: $breakpoint-xs-max) {
    align-self: center;
    width: 140px;
  }
}

.ashes-card__mark,
.ashes-card__star {
  position: absolute;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: #1b1530;
  font-weight: 800;
}

.ashes-card__mark {
  right: 0;
  bottom: 0;
  width: 30px;
  height: 30px;
  background: var(--cm-lime);
}

.ashes-card__star {
  right: 0;
  top: 0;
  width: 26px;
  height: 26px;
  background: #ff8a3d;
  font-size: 13px;
}

.ashes-card__body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}

.ashes-card__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #ffb07a;
}

.ashes-card__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  line-height: 1.1;
}

.ashes-card__tagline {
  font-size: 14.5px;
  line-height: 1.45;
  color: #e4dcef;
  text-wrap: pretty;
}

.ashes-card__chips,
.ashes-card__rewards {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.ashes-card__chip {
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.1);
  font-size: 13px;
  font-weight: 700;
}

.ashes-card__reward {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13.5px;
  font-weight: 800;
  color: #ffd2ad;
}

.ashes-card__state {
  font-size: 13px;
  font-weight: 700;
  color: #b4afc6;
}
</style>
