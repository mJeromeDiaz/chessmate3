<template>
  <div class="tour-bubble" :style="{ '--accent': accent }">
    <div class="tour-bubble__kicker">{{ kicker }}</div>
    <div class="tour-bubble__text" data-testid="tour-line" :aria-label="text">
      <span aria-hidden="true">{{ shown }}</span
      ><span
        class="tour-bubble__caret"
        :style="{ visibility: typing ? 'visible' : 'hidden' }"
        aria-hidden="true"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * The professor's speech bubble, typed letter by letter (design "Visite guidée").
 */
import { useTypewriter } from '@/composables/onboarding/useTypewriter'

const props = defineProps({
  kicker: { type: String, required: true },
  text: { type: String, required: true },
  accent: { type: String, required: true }
})

const { shown, typing } = useTypewriter(() => props.text)
</script>

<style scoped lang="scss">
.tour-bubble {
  flex: none;
  border: 2px solid var(--cm-line);
  border-radius: 20px 20px 20px 6px;
  padding: 10px 14px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  background: var(--cm-surface);
}

.tour-bubble__kicker {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--accent);
}

.tour-bubble__text {
  font-size: 14.5px;
  line-height: 1.45;
  min-height: 42px;
  text-wrap: pretty;
  color: var(--cm-ink);

  @media (min-width: $breakpoint-md-min) {
    font-size: 16px;
  }
}

.tour-bubble__caret {
  display: inline-block;
  width: 2px;
  height: 15px;
  margin-left: 2px;
  vertical-align: -2px;
  background: var(--accent);
  animation: tour-caret 0.8s steps(1) infinite;
}

@keyframes tour-caret {
  50% {
    opacity: 0;
  }
}
</style>
