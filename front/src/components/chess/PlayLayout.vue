<template>
  <div class="play-layout" :class="{ 'play-layout--stacked': stacked }">
    <div v-if="$slots.header" class="play-layout__header">
      <slot name="header" />
    </div>
    <div v-if="$slots.prof" class="play-layout__prof">
      <slot name="prof" />
    </div>
    <div class="play-layout__board">
      <div v-if="$slots.chips" class="play-layout__chips">
        <slot name="chips" />
      </div>
      <slot name="board" />
    </div>
    <div v-if="$slots.controls" class="play-layout__controls">
      <slot name="controls" />
    </div>
    <div v-if="$slots.default" class="play-layout__result">
      <slot />
    </div>
    <div v-if="$slots.footer" class="play-layout__footer">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup>
/**
 * The screen of a board exercise (design "Animation Puzzle"). On a phone, one column: the
 * professor talking, the board as wide as the screen, then the big buttons. On a wide screen,
 * the board as tall as the window on the left, and the same pieces stacked on the right.
 *
 * Slots: `header` (the module's bar), `prof`, `chips` (above the board), `board`, `controls`
 * (the row of `.play-btn` buttons), default (the result), `footer`. The slotted `.play-chip` and
 * `.play-btn` (`--hint`, `--solution`, `--skip`, `--icon`) get the design's look.
 */
defineProps({
  /** One column whatever the screen (a narrow side column). */
  stacked: { type: Boolean, default: false }
})
</script>

<style scoped lang="scss">
.play-layout {
  // The board's size, read by ChessBoard: as wide as the column on a phone, without pushing
  // the buttons below the fold on a short one.
  --board-max: min(100%, max(260px, 100svh - 360px));

  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 12px;
  max-width: 560px;
  margin: 0 auto;
}

.play-layout__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}

.play-layout__controls {
  display: flex;
  gap: 8px;
}

:slotted(.play-chip) {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
}

:slotted(.play-chip--accent) {
  background: var(--cm-lime);
  color: #1b1530;
}

:slotted(.play-btn) {
  flex: 1;
  min-width: 0;
  height: 52px;
  border-radius: 16px;
  font-size: 15px;
  font-weight: 700;
  color: #1b1530;
  box-shadow: none;
}

:slotted(.play-btn--hint) {
  background: #fff5d1;
}

:slotted(.play-btn--hint-used) {
  box-shadow: inset 0 0 0 2px #ffd43b;
}

:slotted(.play-btn--solution) {
  background: #ffe6f1;
}

:slotted(.play-btn--skip) {
  flex: 1.4;
  background: var(--cm-ink);
  color: var(--cm-page);
}

:slotted(.play-btn--icon) {
  flex: none;
  width: 52px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
}

// Wide screen: the board on the left, as tall as the window allows, the rest on the right.
@media (min-width: 1024px) {
  .play-layout:not(.play-layout--stacked) {
    --board-max: min(calc(100vh - 170px), 800px, calc(100vw - 460px));

    grid-template-columns: auto minmax(340px, 1fr);
    grid-template-rows: auto auto auto auto auto 1fr;
    grid-template-areas:
      'board header'
      'board prof'
      'board controls'
      'board result'
      'board footer'
      'board .';
    column-gap: 32px;
    row-gap: 18px;
    align-items: start;
    max-width: 1320px;
  }

  .play-layout:not(.play-layout--stacked) .play-layout__header {
    grid-area: header;
  }

  .play-layout:not(.play-layout--stacked) .play-layout__prof {
    grid-area: prof;
  }

  // Room above and below: the board never fills the whole height.
  .play-layout:not(.play-layout--stacked) .play-layout__board {
    grid-area: board;
    width: var(--board-max);
    padding-block: 28px;
    box-sizing: content-box;
  }

  .play-layout:not(.play-layout--stacked) .play-layout__controls {
    grid-area: controls;
    gap: 10px;
  }

  .play-layout:not(.play-layout--stacked) .play-layout__result {
    grid-area: result;
  }

  .play-layout:not(.play-layout--stacked) .play-layout__footer {
    grid-area: footer;
  }

  :slotted(.play-chip) {
    padding: 5px 12px;
    font-size: 14px;
  }

  :slotted(.play-btn) {
    height: 60px;
    font-size: 17px;
  }

  :slotted(.play-btn--icon) {
    width: 60px;
  }
}
</style>
