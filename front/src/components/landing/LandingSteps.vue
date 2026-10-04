<template>
  <section class="landing-section">
    <div class="landing-section__inner landing-steps">
      <div class="landing-step">
        <div class="landing-step__head">
          <span class="landing-step__num landing-step__num--brand">1</span>
          <div class="landing-step__title cm-heading">Compose</div>
        </div>
        <p class="landing-step__text">
          Un titre, une couverture, puis tes modules dans l’ordre qui te plaît.
        </p>
        <div class="landing-step__well landing-step__program">
          <div
            v-for="item in EXAMPLE_PROGRAM"
            :key="item.module.id"
            class="landing-step__item"
          >
            <ProfAvatar
              class="landing-step__avatar"
              :image="item.module.image"
              :bg="item.module.bg"
              :deep="item.module.deep"
              :ink="item.module.ink"
              :glyph="item.module.glyph"
              radius="12px"
            />
            <div class="landing-step__item-text">
              <div class="landing-step__item-title">{{
                item.module.title
              }}</div>
              <div class="landing-step__item-meta">{{ item.meta }}</div>
            </div>
            <span class="landing-step__duration">{{ item.duration }}</span>
          </div>
        </div>
      </div>

      <div class="landing-step">
        <div class="landing-step__head">
          <span class="landing-step__num landing-step__num--pink">2</span>
          <div class="landing-step__title cm-heading">Entraîne-toi</div>
        </div>
        <p class="landing-step__text">
          Ton prof te glisse un conseil, une aide ou la solution quand tu
          bloques.
        </p>
        <div class="landing-step__train">
          <div class="landing-step__board">
            <ChessBoard
              :fen="BOARD_FEN"
              :highlights="BOARD_HIGHLIGHTS"
              :animation-duration="0"
            />
          </div>
          <div class="landing-step__coach">
            <div class="landing-bubble">
              <div class="landing-bubble__label landing-step__label"
                >ALBERT · CONSEIL</div
              >
              <div class="landing-step__tip"
                >Regarde chaque diagonale ouverte.</div
              >
            </div>
            <img
              v-if="LANDING_IMAGES.albertIdea"
              :src="LANDING_IMAGES.albertIdea"
              alt=""
              class="landing-step__coach-img"
            />
          </div>
        </div>
      </div>

      <div class="landing-step">
        <div class="landing-step__head">
          <span class="landing-step__num landing-step__num--lime">3</span>
          <div class="landing-step__title cm-heading">Progresse</div>
        </div>
        <p class="landing-step__text">
          Chaque puzzle laisse une trace. Les erreurs reviennent jusqu’à devenir
          des réflexes.
        </p>
        <div class="landing-step__well landing-step__cells">
          <div
            v-for="(color, i) in cells"
            :key="i"
            class="landing-step__cell"
            :style="{ background: color }"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import {
  EXAMPLE_PROGRAM,
  LANDING_IMAGES,
  woodpeckerCells
} from '@/utils/landing/content'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import ProfAvatar from '@/components/session/ProfAvatar.vue'

/** "How it works": compose a session, train with a professor's help, keep track. */

/** An Italian game: the c4 bishop's diagonal towards f7, to go with Albert's tip. */
const BOARD_FEN =
  'r1bqk2r/pppp1ppp/2n2n2/2b1p3/2B1P3/5N2/PPPP1PPP/RNBQ1RK1 w kq - 6 5'
const BOARD_HIGHLIGHTS = [{ square: 'c4', type: 'hint' }]

const cells = woodpeckerCells(50, 34, 'var(--cm-line)')
</script>

<style scoped lang="scss">
.landing-steps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr));
  gap: 20px;
}

.landing-step {
  background: var(--cm-surface);
  border-radius: 28px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.landing-step__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.landing-step__num {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;

  &--brand {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
  }

  &--pink {
    background: #ffe6f1;
    color: #c02670;
  }

  &--lime {
    background: var(--cm-lime-soft);
    color: var(--cm-lime-ink);
  }
}

.landing-step__title {
  font-size: 22px;
}

.landing-step__text {
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
}

.landing-step__well {
  margin-top: auto;
  background: var(--cm-page);
  border-radius: 20px;
}

.landing-step__program {
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.landing-step__item {
  display: flex;
  gap: 10px;
  align-items: center;
  background: var(--cm-surface);
  border-radius: 16px;
  padding: 8px 10px;
}

.landing-step__avatar {
  width: 40px;
  height: 40px;
  flex: none;
}

.landing-step__item-text {
  flex: 1;
  min-width: 0;
}

.landing-step__item-title {
  font-weight: 700;
  font-size: 14px;
}

.landing-step__item-meta {
  font-size: 12px;
  color: var(--cm-muted);
}

.landing-step__duration {
  font-weight: 700;
  font-size: 12px;
  background: var(--cm-subtle);
  padding: 3px 8px;
  border-radius: 999px;
  white-space: nowrap;
}

.landing-step__train {
  margin-top: auto;
  display: flex;
  gap: 12px;
  align-items: flex-end;
}

.landing-step__board {
  width: 46%;
  flex: none;
  border-radius: 16px;
  overflow: hidden;
  pointer-events: none;
}

.landing-step__coach {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
}

.landing-step__label {
  color: #c02670;
}

.landing-step__tip {
  font-size: 13px;
  line-height: 1.4;
}

.landing-step__coach-img {
  width: 72px;
}

.landing-step__cells {
  padding: 14px;
  display: grid;
  grid-template-columns: repeat(10, minmax(0, 1fr));
  gap: 4px;
}

.landing-step__cell {
  aspect-ratio: 1;
  border-radius: 4px;
}
</style>
