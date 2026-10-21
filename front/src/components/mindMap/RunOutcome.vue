<template>
  <div class="run-outcome" data-testid="mind-outcome">
    <div v-if="!outcome && !error" class="run-outcome__wait">
      <q-spinner size="2.5em" />
    </div>
    <q-banner v-else-if="!outcome" rounded class="bg-negative text-white">{{
      error
    }}</q-banner>

    <div
      v-else
      class="run-outcome__card cm-card"
      :class="`run-outcome__card--${key}`"
      :data-outcome="key"
    >
      <div class="run-outcome__head">
        <BossPortrait
          v-if="boss"
          class="run-outcome__portrait"
          :character="step?.character ?? null"
          :pose="
            key === 'defeat' ? 'cheer' : key === 'pending' ? 'idle' : 'beaten'
          "
        />
        <div class="run-outcome__titles">
          <span class="run-outcome__kicker">{{
            step
              ? `${step.number ? `${step.number} · ` : ''}${title}`
              : $t(`mindMapPath.kinds.${outcome.kind}`)
          }}</span>
          <h2 class="run-outcome__title" data-testid="mind-outcome-title">{{
            $t(`mindMapPath.outcome.${key}`)
          }}</h2>
        </div>
      </div>

      <p v-if="boss && key === 'defeat' && step" class="run-outcome__bubble">
        « {{ bossLine(step, 'win') }} »
      </p>

      <div class="run-outcome__facts">
        <span>{{
          $t('mindMapPath.outcome.score', {
            success: outcome.successCount,
            count: outcome.itemCount
          })
        }}</span>
        <span v-if="boss" class="run-outcome__lives">
          <q-icon
            v-for="i in BOSS_LIVES"
            :key="i"
            :name="i <= lives ? 'favorite' : 'heart_broken'"
          />
        </span>
        <span v-else data-testid="mind-outcome-passes">{{
          $t('mindMapPath.outcome.passes', {
            n: outcome.passes,
            total: outcome.required
          })
        }}</span>
        <span v-if="outcome.xp > 0" class="run-outcome__xp">{{
          $t('mindMapPath.sheet.xp', { xp: outcome.xp })
        }}</span>
      </div>

      <p v-if="key === 'failed'" class="run-outcome__note">
        {{ $t('mindMapPath.outcome.need', { count: outcome.count }) }}
        {{ $t('mindMapPath.outcome.failedText') }}
      </p>
      <p v-if="key === 'defeat'" class="run-outcome__note">{{
        $t('mindMapPath.outcome.defeatText')
      }}</p>
      <div v-if="startError" class="text-negative">{{ startError }}</div>

      <div class="run-outcome__actions">
        <q-btn
          v-if="replayable"
          unelevated
          no-caps
          color="primary"
          :label="
            key === 'pass'
              ? $t('mindMapPath.outcome.nextPass')
              : $t('mindMapPath.outcome.replay')
          "
          :loading="starting"
          data-testid="mind-outcome-replay"
          @click="replay"
        />
        <q-btn
          :outline="replayable"
          :unelevated="!replayable"
          :color="replayable ? undefined : 'primary'"
          no-caps
          :label="$t('mindMapPath.outcome.back')"
          :to="pathPage(outcome.capacity)"
          data-testid="mind-outcome-back"
        />
      </div>
    </div>

    <BossVictory
      v-if="victoryOpen && step"
      :step="step"
      :xp="outcome?.xp ?? 0"
      :chest="chests.length > 0"
      @continue="leaveVictory"
    />
    <TreasureChest
      v-else-if="chests.length && outcome"
      :key="chests[0].id"
      :reward="chestReward(chests[0])"
      :note="chestNote(outcome.capacity, chests[0])"
      :opener="mindMapApi.openChest"
      :results-label="$t('mindMapPath.victory.continue')"
      @results="nextChest"
      @close="nextChest"
      @shop="chests = []"
    />
  </div>
</template>

<script setup>
/**
 * The end of a run played for a step of a mind map path (docs/MIND_MAP.md): the verdict (a pass,
 * the exercise validated, not yet; a boss beaten or winning), the passes or lives, the XP; a
 * beaten boss's victory, then the chests earned, one after the other; « Passage suivant » or
 * « Rejouer », and back to the path.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import BossVictory from '@/components/mindMap/BossVictory.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { mindMapApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import {
  BOSS_LIVES,
  bossLine,
  bossName,
  chestNote,
  chestReward,
  findStep,
  isBoss,
  livesLeft,
  outcomeKey,
  pathPage,
  refusalText,
  stepTitle
} from '@/utils/mindMapPath'

const props = defineProps({
  /** The run just over. */
  runId: { type: String, required: true }
})

const router = useRouter()
const training = useTrainingStore()

/** @type {import('vue').Ref<any>} the API's verdict */
const outcome = ref(null)
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathStep|null>} */
const step = ref(null)
const error = ref('')
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathChest[]>} chests still to open */
const chests = ref([])
const victoryOpen = ref(false)
const starting = ref(false)
const startError = ref('')

const key = computed(() =>
  outcome.value ? outcomeKey(outcome.value) : 'pending'
)
const boss = computed(() => !!outcome.value && isBoss(outcome.value.kind))
const lives = computed(() => (outcome.value ? livesLeft(outcome.value) : 0))
const title = computed(() => {
  if (!step.value || !outcome.value) return ''
  return boss.value
    ? bossName(step.value)
    : stepTitle(outcome.value.capacity, step.value.key)
})
/** Played again from here once judged (a refusal, e.g. a run in progress, says why). */
const replayable = computed(() => !!outcome.value && key.value !== 'pending')

onMounted(load)

async function load() {
  try {
    const verdict = await mindMapApi.outcome(props.runId)
    outcome.value = verdict
    const path = await mindMapApi.path(verdict.capacity).catch(() => null)
    step.value = path ? findStep(path, verdict.step) : null
    chests.value = verdict.unopenedChests ?? []
    victoryOpen.value = outcomeKey(verdict) === 'victory' && !!step.value
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function leaveVictory() {
  victoryOpen.value = false
}

function nextChest() {
  chests.value = chests.value.slice(1)
}

/** The same step again: a new run, on this page. */
async function replay() {
  const verdict = outcome.value
  if (!verdict) return
  starting.value = true
  startError.value = ''
  try {
    const { run } = await mindMapApi.start(verdict.capacity, verdict.step)
    training.current = run
    const boss = /** @type {any} */ (step.value)?.character
    await router.push({
      path: `/training/${run.id}`,
      query: { mindMap: '1', ...(boss ? { boss } : {}) }
    })
  } catch (e) {
    startError.value = refusalText(e) || apiErrorMessage(e)
  } finally {
    starting.value = false
  }
}
</script>

<style scoped lang="scss">
.run-outcome {
  max-width: 640px;
  margin: 0 auto;
}

.run-outcome__wait {
  display: grid;
  place-items: center;
  padding: 48px;
}

.run-outcome__card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 22px;
  border-radius: 22px;
  border-top: 6px solid var(--cm-brand);

  &--validated,
  &--victory,
  &--practice {
    border-top-color: #8fb800;
  }

  &--failed,
  &--defeat {
    border-top-color: #ff8a3d;
  }
}

.run-outcome__head {
  display: flex;
  align-items: center;
  gap: 16px;
}

.run-outcome__portrait {
  width: 96px;
  flex: none;
  border-radius: 18px;
  background: #fff;
}

.run-outcome__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.run-outcome__title {
  margin: 2px 0 0;
  font-family: var(--cm-heading);
  font-size: 28px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--cm-ink);
}

.run-outcome__bubble {
  margin: 0;
  font-weight: 700;
  color: var(--cm-ink-soft);
}

.run-outcome__facts {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
  font-size: 14px;
  font-weight: 800;
  color: var(--cm-ink-soft);
}

.run-outcome__lives {
  display: inline-flex;
  gap: 4px;
  color: #ff6fae;
  font-size: 22px;
}

.run-outcome__xp {
  padding: 3px 12px;
  border-radius: 999px;
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
}

.run-outcome__note {
  margin: 0;
  color: var(--cm-muted);
  font-size: 14px;
}

.run-outcome__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
