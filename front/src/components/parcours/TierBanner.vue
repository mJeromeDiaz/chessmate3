<template>
  <div class="tier-banner cm-card" data-testid="parcours-banner">
    <img :src="image" alt="" class="tier-banner__prof" />
    <div class="tier-banner__body">
      <div class="tier-banner__done" data-testid="parcours-banner-done">{{
        $t('parcours.banner.done', { n: tier + 1, solved, count })
      }}</div>
      <p class="tier-banner__line">{{ $t(`parcours.banner.lines.${mood}`) }}</p>
      <div class="text-subtitle1 text-weight-medium">{{
        $t('parcours.banner.next', { n: tier + 2, name: nextName })
      }}</div>
      <div v-if="!error" class="text-caption text-grey">{{
        $t('parcours.banner.starting')
      }}</div>
      <div v-else class="text-negative">{{ error }}</div>
      <q-linear-progress
        v-if="!error"
        :value="elapsed"
        color="orange"
        class="q-my-sm"
        instant-feedback
      />
      <q-btn
        color="primary"
        unelevated
        no-caps
        icon="skip_next"
        :label="$t('parcours.banner.continue')"
        :loading="starting"
        data-testid="parcours-continue"
        @click="go"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * Between two tiers of a parcours (docs/PARCOURS.md): Lizy with the tier's score, what comes next,
 * and the next tier started by itself after a short pause ("Continuer" goes at once). After a
 * refused start, only the button is left.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { profImage } from '@/utils/prof/images'
import { NEXT_TIER_MS } from '@/utils/parcours'
import { CLOSE_RATE } from '@/utils/runResult'

const props = defineProps({
  /** The tier just played, 0-based. */
  tier: { type: Number, required: true },
  solved: { type: Number, required: true },
  count: { type: Number, required: true },
  nextName: { type: String, required: true },
  starting: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits({ continue: null })

const mood = computed(() => {
  if (props.count > 0 && props.solved >= props.count) return 'perfect'
  return props.solved >= props.count * CLOSE_RATE ? 'good' : 'hard'
})
const image = computed(() =>
  profImage(
    { perfect: 'lizy-joy', good: 'lizy-cool', hard: 'lizy-think' }[mood.value]
  )
)

const elapsed = ref(0)
let timer = 0
let started = 0
let sent = false

function stopTimer() {
  if (timer) clearInterval(timer)
  timer = 0
}

function go() {
  stopTimer()
  if (sent && !props.error) return
  sent = true
  emit('continue')
}

onMounted(() => {
  started = Date.now()
  timer = window.setInterval(() => {
    elapsed.value = Math.min(1, (Date.now() - started) / NEXT_TIER_MS)
    if (elapsed.value >= 1) go()
  }, 100)
})
// A refused start: no new attempt by itself.
watch(
  () => props.error,
  error => {
    if (error) stopTimer()
  }
)
onBeforeUnmount(stopTimer)
</script>

<style scoped>
.tier-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px 24px;
  max-width: 700px;
  margin: 0 auto;
}
.tier-banner__prof {
  width: 120px;
  height: 120px;
  object-fit: contain;
  flex: none;
}
@media (min-width: 1024px) {
  .tier-banner__prof {
    width: 180px;
    height: 180px;
  }
}
.tier-banner__body {
  flex: 1 1 260px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}
.tier-banner__body .q-linear-progress {
  width: 100%;
}
.tier-banner__done {
  font-size: 1.3rem;
  font-weight: 800;
}
.tier-banner__line {
  margin: 0;
  opacity: 0.8;
}
</style>
