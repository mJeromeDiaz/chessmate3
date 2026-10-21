<template>
  <q-page padding>
    <div v-if="set" class="woodpecker-page">
      <div class="woodpecker-page__head">
        <div class="woodpecker-page__name cm-heading">{{ set.name }}</div>
        <span class="woodpecker-page__mode">{{
          light ? $t('woodpecker.new.light') : $t('woodpecker.new.classic')
        }}</span>
        <q-space />
        <q-btn
          v-if="menu.length"
          flat
          round
          icon="more_vert"
          :aria-label="$t('woodpecker.set.actions')"
          data-testid="set-menu"
        >
          <q-menu auto-close>
            <q-list style="min-width: 200px">
              <q-item
                v-for="item in menu"
                :key="item.action"
                clickable
                :class="{ 'text-negative': item.danger }"
                :data-testid="`set-${item.action}`"
                @click="item.onClick"
              >
                <q-item-section avatar>
                  <q-icon :name="item.icon" />
                </q-item-section>
                <q-item-section>{{ item.label }}</q-item-section>
              </q-item>
            </q-list>
          </q-menu>
        </q-btn>
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <CurrentCycleCard
        :set="set"
        :run-here="runHere"
        @resume="act('resume')"
      />

      <CycleExplainer :set="set" />

      <section
        v-if="!light && set.cycles.length"
        class="woodpecker-page__section"
      >
        <h2 class="woodpecker-page__title">{{
          $t('woodpecker.set.cycles')
        }}</h2>
        <CycleTable
          :cycles="set.cycles"
          :puzzle-count="set.puzzleCount"
          :time-zone="set.timezone"
        />
      </section>

      <section v-if="light || set.runs.length" class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">{{ $t('woodpecker.set.runs') }}</h2>
        <RunTable :runs="set.runs" />
      </section>

      <section v-if="light" class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">{{
          $t('woodpecker.set.growth')
        }}</h2>
        <div
          v-if="set.growths.length"
          class="woodpecker-page__rows"
          data-testid="growth-list"
        >
          <div
            v-for="g in [...set.growths].reverse()"
            :key="g.occurredAt + g.puzzleCount"
            class="woodpecker-page__row"
          >
            <div class="woodpecker-page__row-main">{{
              $t('woodpecker.set.grown', {
                added: g.added,
                total: g.puzzleCount
              })
            }}</div>
            <div class="woodpecker-page__row-side">{{
              formatDate(g.occurredAt)
            }}</div>
          </div>
        </div>
        <div v-else class="woodpecker-page__empty">{{
          $t('woodpecker.set.noGrowth')
        }}</div>
      </section>

      <SetPuzzleList
        :set-id="set.id"
        :count="set.puzzleCount"
        :editable="set.status === 'active' || set.status === 'paused'"
      />

      <section class="woodpecker-page__section">
        <h2 class="woodpecker-page__title">{{
          $t('woodpecker.set.stubborn')
        }}</h2>
        <div class="woodpecker-page__hint">{{
          light
            ? $t('woodpecker.set.stubbornHintLight')
            : $t('woodpecker.set.stubbornHintClassic')
        }}</div>
        <div v-if="stubborn.length" class="woodpecker-page__rows">
          <div
            v-for="p in stubborn"
            :key="p.puzzleId"
            class="woodpecker-page__row"
          >
            <div class="woodpecker-page__row-main">
              <div>{{
                $t(
                  light
                    ? 'woodpecker.set.failedLight'
                    : 'woodpecker.set.failedClassic',
                  {
                    rating: p.rating,
                    n: p.failedCycles
                  }
                )
              }}</div>
              <div class="woodpecker-page__row-sub">{{
                p.themes.slice(0, 4).map(puzzles.themeLabel).join(' · ')
              }}</div>
            </div>
            <q-btn
              outline
              color="primary"
              no-caps
              icon="replay"
              :label="$t('woodpecker.set.replay')"
              :to="{ path: '/puzzle', query: { replay: p.puzzleId } }"
            />
          </div>
        </div>
        <div v-else class="woodpecker-page__empty">{{
          $t('woodpecker.set.none')
        }}</div>
      </section>
    </div>
    <div v-else-if="error" class="text-negative">{{ error }}</div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import RunTable from '@/components/training/RunTable.vue'
import CurrentCycleCard from '@/components/woodpecker/CurrentCycleCard.vue'
import CycleExplainer from '@/components/woodpecker/CycleExplainer.vue'
import CycleTable from '@/components/woodpecker/CycleTable.vue'
import SetPuzzleList from '@/components/woodpecker/SetPuzzleList.vue'
import { usePageHeader } from '@/composables/layout/usePageHeader'
import { usePuzzleStore } from '@/stores/puzzle'
import { useTrainingStore } from '@/stores/training'
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { woodpeckerApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'layout.header.titles.woodpecker',
      subtitle: 'layout.nav.woodpecker'
    }
  }
})

const route = useRoute()
const $q = useQuasar()
const store = useWoodpeckerStore()
const training = useTrainingStore()
const puzzles = usePuzzleStore()
const error = ref('')
const stubborn = ref([])
const id = computed(() => String(route.params.id))
const set = computed(() => (store.set?.id === id.value ? store.set : null))
usePageHeader(() => ({ title: set.value?.name ?? null }))
const light = computed(() => set.value?.mode === 'light')

/** The run in progress on this set, if any. */
const runHere = computed(() =>
  training.current?.subjectId === id.value ? training.current : null
)

/** The ⋮ menu: what the set's status allows. */
const menu = computed(() => {
  const s = set.value
  if (!s) return []
  const ongoing = s.status === 'active' || s.status === 'paused'
  return [
    s.status === 'active' && {
      action: 'pause',
      label: t('woodpecker.set.pause'),
      icon: 'pause',
      onClick: () => act('pause')
    },
    s.status === 'paused' && {
      action: 'resume',
      label: t('woodpecker.set.resume'),
      icon: 'play_arrow',
      onClick: () => act('resume')
    },
    ongoing && {
      action: 'abandon',
      label: t('woodpecker.set.abandon'),
      icon: 'flag',
      danger: true,
      onClick: confirmAbandon
    },
    !ongoing &&
      !s.archived && {
        action: 'archive',
        label: t('woodpecker.set.archive'),
        icon: 'archive',
        onClick: () => act('archive')
      }
  ].filter(Boolean)
})

/** @param {'pause'|'resume'|'abandon'|'archive'} action */
async function act(action) {
  error.value = ''
  try {
    await store.act(id.value, action)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: t('woodpecker.set.impossible')
    })
  }
}

function confirmAbandon() {
  $q.dialog({
    title: t('woodpecker.set.abandonTitle'),
    message: t('woodpecker.set.abandonMessage'),
    cancel: true
  }).onOk(() => act('abandon'))
}

onMounted(async () => {
  puzzles.fetchThemes().catch(() => {})
  try {
    // A run past its time is closed by this request: fetch it before the set's run history.
    await training.fetchCurrent().catch(() => {})
    await store.fetchSet(id.value)
    stubborn.value = await woodpeckerApi.stubborn(id.value)
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: t('woodpecker.set.notFound') })
  }
})
</script>

<style scoped lang="scss">
.woodpecker-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 1000px;
  margin: 0 auto;
}

.woodpecker-page__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.woodpecker-page__name {
  font-size: 24px;
  font-weight: 800;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.woodpecker-page__mode {
  flex: none;
  padding: 4px 12px;
  border-radius: 999px;
  background: var(--cm-subtle);
  font-size: 13px;
  font-weight: 700;
}

.woodpecker-page__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.woodpecker-page__title {
  margin: 8px 0 0;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.2;
}

.woodpecker-page__hint,
.woodpecker-page__empty {
  font-size: 15px;
  color: var(--cm-muted);
}

.woodpecker-page__rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.woodpecker-page__row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);
  font-size: 16px;
}

.woodpecker-page__row-main {
  flex: 1;
  min-width: 0;
  font-weight: 600;
}

.woodpecker-page__row-sub {
  font-size: 14px;
  font-weight: 400;
  color: var(--cm-muted);
}

.woodpecker-page__row-side {
  font-size: 14px;
  color: var(--cm-muted);
}

@media (min-width: 1024px) {
  .woodpecker-page {
    gap: 24px;
  }

  .woodpecker-page__name {
    font-size: 30px;
  }

  .woodpecker-page__title {
    font-size: 22px;
  }

  .woodpecker-page__row {
    font-size: 17px;
    padding: 14px 20px;
  }
}
</style>
