<template>
  <q-dialog :model-value="open" persistent>
    <div
      class="game-result"
      :style="{ '--dark': colors.dark }"
      data-testid="arena-result"
      :data-status="status"
    >
      <div class="game-result__stage">
        <img v-if="face" :src="face" alt="" class="game-result__face" />
        <span v-else class="game-result__glyph" aria-hidden="true">{{
          glyph
        }}</span>
      </div>
      <div class="game-result__body">
        <h2 class="game-result__title">
          {{ $t(`arena.result.${status}`) }}
        </h2>
        <p v-if="end" class="game-result__end">
          {{ $t(`arena.end.${end}`) }}
        </p>
        <p class="game-result__line">« {{ line }} »</p>
        <div v-if="xp > 0 || crystals > 0" class="game-result__gains">
          <span
            v-if="xp > 0"
            class="game-result__gain"
            data-testid="arena-result-xp"
            >+{{ xp }} XP</span
          >
          <span
            v-if="crystals > 0"
            class="game-result__gain game-result__gain--crystals"
            data-testid="arena-result-crystals"
            >+{{ crystals }} <CrystalGem :size="16"
          /></span>
        </div>
        <div class="game-result__actions">
          <button
            type="button"
            class="game-result__btn game-result__btn--main"
            data-testid="arena-rematch"
            :disabled="busy"
            @click="emit('rematch')"
          >
            {{ $t('arena.result.rematch') }}
          </button>
          <button
            type="button"
            class="game-result__btn"
            data-testid="arena-back"
            @click="emit('back')"
          >
            {{ $t('arena.result.back') }}
          </button>
        </div>
        <button
          type="button"
          class="game-result__review"
          data-testid="arena-review-open"
          @click="emit('review')"
        >
          <q-icon name="insights" size="18px" />
          {{ $t('arena.result.review') }}
        </button>
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The end of a game of the arena (design « Défier les boss »): the boss's face (sad when beaten,
 * proud when it won), how the game ended, its words, the XP and the crystals of a first win; then
 * a rematch, back to the bosses, or the game reviewed.
 */
import { computed } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { bossMood } from '@/utils/arena'
import { REACTION_MOODS, endReaction } from '@/utils/arenaBoss'

const props = defineProps({
  open: { type: Boolean, default: false },
  character: { type: String, required: true },
  /** @type {import('vue').PropType<'won'|'lost'|'drawn'>} */
  status: { type: String, required: true },
  end: { type: String, default: null },
  line: { type: String, default: '' },
  xp: { type: Number, default: 0 },
  crystals: { type: Number, default: 0 },
  glyph: { type: String, default: '♚' },
  /** @type {import('vue').PropType<import('@/utils/arena').Palette>} */
  colors: { type: Object, required: true },
  busy: { type: Boolean, default: false }
})

const emit = defineEmits(['rematch', 'back', 'review'])

const face = computed(() =>
  bossMood(
    props.character,
    REACTION_MOODS[endReaction(/** @type {any} */ (props.status))]
  )
)
</script>

<style scoped lang="scss">
.game-result {
  width: 100%;
  max-width: 420px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-radius: 28px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.game-result__stage {
  width: 100%;
  height: 160px;
  background: var(--dark);
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.game-result__face {
  height: 150px;
  object-fit: contain;
}

.game-result__glyph {
  font-size: 110px;
  line-height: 1.2;
  color: #fff;
}

.game-result__body {
  width: 100%;
  box-sizing: border-box;
  padding: 18px 22px 22px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
}

.game-result__title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 27px;
  line-height: 1.1;
}

.game-result__end {
  margin: 0;
  font-weight: 700;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.game-result__line {
  margin: 0;
  font-size: 14.5px;
  line-height: 1.45;
  color: var(--cm-ink-soft);
  font-style: italic;
}

.game-result__gains {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}

.game-result__gain {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 12px;
  border-radius: 999px;
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
  font-weight: 800;
  font-size: 14px;
}

.game-result__gain--crystals {
  background: var(--cm-info-soft);
  color: var(--cm-info);
}

.game-result__actions {
  display: flex;
  gap: 8px;
  width: 100%;
  margin-top: 8px;
}

.game-result__btn {
  flex: 1;
  height: 50px;
  border: none;
  border-radius: 16px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }
}

.game-result__review {
  margin-top: 4px;
  border: none;
  background: none;
  color: var(--cm-brand);
  font: inherit;
  font-weight: 800;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
}

.game-result__btn--main {
  background: var(--cm-brand);
  color: #fff;
}
</style>
