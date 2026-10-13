<template>
  <span class="cs-scramble" :aria-label="text"
    ><span aria-hidden="true">{{ text.slice(0, n) }}</span
    ><span class="cs-scramble__tail" aria-hidden="true">{{ tail }}</span></span
  >
</template>

<script setup>
/**
 * A text that writes itself, its next letters scrambled (design "Session perso").
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({ text: { type: String, required: true } })

const CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789%#'
const n = ref(0)
/** @type {ReturnType<typeof setInterval>|undefined} */
let timer

function play() {
  clearInterval(timer)
  n.value = 0
  timer = setInterval(() => {
    n.value += 2
    if (n.value >= props.text.length) clearInterval(timer)
  }, 24)
}

const tail = computed(() =>
  props.text
    .slice(n.value, n.value + 10)
    .replace(/[^ ]/g, () => CHARS[Math.floor(Math.random() * CHARS.length)])
)

watch(() => props.text, play, { immediate: true })
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped lang="scss">
.cs-scramble {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
  line-height: 1.2;
  color: #fff;
  text-wrap: balance;

  &__tail {
    color: var(--cs-accent);
    opacity: 0.7;
  }
}
</style>
