<template>
  <div class="floating-pieces" aria-hidden="true">
    <span
      v-for="p in pieces"
      :key="p.key"
      class="floating-pieces__piece"
      :class="[
        `floating-pieces__piece--${dir}`,
        { 'floating-pieces__piece--mark': p.mark }
      ]"
      :style="p.style"
      >{{ p.glyph }}</span
    >
  </div>
</template>

<script setup>
/**
 * Chess pieces drifting across an end-of-run screen (designs "Résultat de leçon", "Corriger ses
 * erreurs"): rising after a success, falling after a failure; with `marks`, one in three is a "?".
 */
import { computed } from 'vue'

const props = defineProps({
  ink: { type: String, default: '#FFFFFF' },
  /** @type {import('vue').PropType<'up'|'down'>} */
  dir: { type: String, default: 'up' },
  count: { type: Number, default: 15 },
  marks: { type: Boolean, default: false }
})

const GLYPHS = ['♞︎', '♝︎', '♛︎', '♜︎', '♟︎', '♚︎']

const pieces = computed(() =>
  Array.from({ length: props.count }, (_, i) => {
    const down = props.dir === 'down'
    const r0 = ((i * 47) % 60) - 30
    const mark = props.marks && i % 3 === 0
    return {
      key: i,
      mark,
      glyph: mark ? '?' : GLYPHS[i % GLYPHS.length],
      style: {
        left: `${(i * 29 + 7) % 92}%`,
        top: down
          ? `${-80 - (i % 4) * 40}px`
          : `calc(100% + ${(i % 4) * 40}px)`,
        fontSize: `${22 + (i % 4) * 9}px`,
        color: props.ink,
        opacity: 0.2 + (i % 3) * 0.08,
        '--r0': `${r0}deg`,
        '--r1': `${r0 + (i % 2 ? 220 : -220)}deg`,
        animationDuration: `${(down ? 6 : 9) + (i % 5) * 2}s`,
        animationDelay: `${-(i * 1.3)}s`
      }
    }
  })
)
</script>

<style scoped lang="scss">
.floating-pieces {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.floating-pieces__piece {
  position: absolute;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  line-height: 1;
  animation: fp-up 9s linear infinite;

  &--down {
    animation-name: fp-down;
  }

  &--mark {
    font-family: var(--cm-heading);
    font-weight: 800;
  }
}

@keyframes fp-up {
  from {
    transform: translateY(0) rotate(var(--r0));
  }
  to {
    transform: translateY(-1100px) rotate(var(--r1));
  }
}

@keyframes fp-down {
  from {
    transform: translateY(0) rotate(var(--r0));
  }
  to {
    transform: translateY(1100px) rotate(var(--r1));
  }
}

@media (prefers-reduced-motion: reduce) {
  .floating-pieces__piece {
    animation: none !important;
  }
}
</style>
