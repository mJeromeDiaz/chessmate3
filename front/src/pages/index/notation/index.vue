<template>
  <q-page padding>
    <div class="notation-page q-gutter-y-md" data-testid="notation-page">
      <div class="text-h5">{{ $t('notation.page.title') }}</div>
      <p class="text-body2 text-grey-8">{{ $t('notation.page.lead') }}</p>
      <p class="text-body2" data-testid="notation-letters">{{
        $t('notation.page.letters', { letters: pieceLetters().join(' ') })
      }}</p>

      <div class="notation-page__kinds">
        <button
          v-for="k in KINDS"
          :key="k"
          type="button"
          class="notation-page__kind cm-card"
          :class="{ 'notation-page__kind--on': kind === k }"
          :aria-pressed="kind === k"
          :data-testid="`notation-kind-${k}`"
          @click="kind = k"
        >
          <span class="notation-page__kind-title">{{
            $t(`notation.kinds.${k}.title`)
          }}</span>
          <span class="notation-page__kind-desc">{{
            $t(`notation.kinds.${k}.desc`)
          }}</span>
        </button>
      </div>

      <q-card flat bordered>
        <q-card-section class="q-gutter-y-sm">
          <template v-if="kind === 'line'">
            <q-select
              v-if="eligible.length"
              v-model="repertoireId"
              outlined
              dense
              emit-value
              map-options
              :options="repertoireOptions"
              :label="$t('notation.page.repertoire')"
              data-testid="notation-repertoire"
            />
            <q-banner
              v-else-if="overview"
              rounded
              class="bg-orange-1"
              data-testid="notation-no-repertoire"
            >
              {{
                $t('notation.page.noRepertoire', {
                  n: overview.rules.lineMinSegments
                })
              }}
              <template #action>
                <q-btn
                  flat
                  no-caps
                  color="primary"
                  :label="$t('notation.page.createRepertoire')"
                  to="/repertoire"
                />
              </template>
            </q-banner>
          </template>
          <RunLauncher
            v-if="subjectId"
            module="notation"
            :subject-id="subjectId"
            :config="config"
            :durations="DURATIONS"
            :default-minutes="5"
            :disable="kind === 'line' && !eligible.length"
            :label="$t('notation.page.launch')"
          />
        </q-card-section>
      </q-card>

      <p class="text-caption text-grey-8">
        <router-link to="/cerveau/visualization">{{
          $t('notation.page.mindMap')
        }}</router-link>
      </p>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The notation module in free play (docs/NOTATION.md): its exercises (play the move announced,
 * play a sequence for both sides, write a puzzle's line, complete a line of one's repertoire: one
 * repertoire or all those of enough segments), the piece letters of the interface's language, a
 * timed run. Its lessons are also in the mind map (chapter 1 of Visualisation).
 */
import { computed, onMounted, ref } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { notationApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { KINDS, pieceLetters } from '@/utils/notation'
import { t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

/** The lengths offered, in minutes. */
const DURATIONS = [2, 5, 10, 15]

const auth = useAuthStore()
const kind = ref('play')
const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

/** @type {import('vue').Ref<{rules: {lineMinSegments: number, lineWritePlies: number}, repertoires: {id: string, name: string, segments: number, eligible: boolean}[]}|null>} GET /notation */
const overview = ref(null)
/** The repertoire of the line exercise, null: any of those fit for it. */
const repertoireId = ref(/** @type {string|null} */ (null))
const eligible = computed(
  () => overview.value?.repertoires.filter(r => r.eligible) ?? []
)
const repertoireOptions = computed(() => [
  {
    value: null,
    label: t('notation.page.allRepertoires', {
      n: overview.value?.rules.lineMinSegments ?? 10
    })
  },
  ...eligible.value.map(r => ({
    value: r.id,
    label: t('notation.page.segments', { name: r.name, n: r.segments })
  }))
])
const config = computed(() =>
  kind.value === 'line' && repertoireId.value
    ? { kind: kind.value, repertoireId: repertoireId.value }
    : { kind: kind.value }
)

onMounted(() => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  notationApi
    .overview()
    .then(o => (overview.value = o))
    .catch(() => {})
})
</script>

<style scoped lang="scss">
.notation-page {
  max-width: 800px;
  margin: 0 auto;
}

.notation-page__kinds {
  display: grid;
  gap: 12px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.notation-page__kind {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px;
  border: none;
  border-radius: 18px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  text-align: left;
  cursor: pointer;
  box-shadow: inset 0 0 0 2px var(--cm-line);

  &--on {
    box-shadow: inset 0 0 0 3px var(--cm-brand);
  }
}

.notation-page__kind-title {
  font-family: var(--cm-heading);
  font-size: 17px;
  font-weight: 800;
}

.notation-page__kind-desc {
  font-size: 13.5px;
  color: var(--cm-muted);
}
</style>
