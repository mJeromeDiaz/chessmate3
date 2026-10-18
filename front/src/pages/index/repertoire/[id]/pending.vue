<template>
  <q-page padding>
    <div class="pending-page q-gutter-y-md">
      <div class="row items-center no-wrap">
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          :to="`/repertoire/${repertoireId}`"
          :aria-label="$t('repertoire.pending.back')"
        />
        <div class="text-h5 q-ml-sm ellipsis">
          {{
            store.graph?.id === repertoireId
              ? $t('repertoire.pending.named', { name: store.graph.name })
              : $t('repertoire.pending.title')
          }}
        </div>
      </div>

      <div class="text-body2 cm-muted">
        {{ $t('repertoire.pending.lead') }}
      </div>

      <q-banner v-if="loadError" rounded class="bg-negative text-white">{{
        loadError
      }}</q-banner>

      <q-list v-else-if="items === null" bordered separator>
        <q-item v-for="i in 3" :key="i">
          <q-item-section><q-skeleton type="text" /></q-item-section>
        </q-item>
      </q-list>

      <div
        v-else-if="items.length === 0"
        class="text-grey q-pa-lg text-center"
        data-testid="pending-empty"
      >
        {{ $t('repertoire.pending.empty') }}
      </div>

      <template v-else>
        <div class="text-subtitle2" data-testid="pending-open">
          {{ $t('repertoire.pending.open', { n: open }) }}
        </div>
        <q-list bordered separator>
          <q-item
            v-for="item in items"
            :key="item.id"
            :class="{ 'pending-page__item--off': item.status !== 'open' }"
            data-testid="pending-item"
            :data-status="item.status"
          >
            <q-item-section>
              <q-item-label class="text-weight-medium">{{
                sanPath(item.path)
              }}</q-item-label>
              <q-item-label caption>{{ caption(item) }}</q-item-label>
            </q-item-section>
            <q-item-section side>
              <div class="row no-wrap q-gutter-xs">
                <template v-if="item.status === 'open'">
                  <q-btn
                    flat
                    dense
                    no-caps
                    color="primary"
                    icon="add_circle_outline"
                    :label="$t('repertoire.pending.answer')"
                    data-testid="pending-answer"
                    @click="openAnswer(item)"
                  />
                  <q-btn
                    flat
                    dense
                    no-caps
                    icon="visibility_off"
                    :label="$t('repertoire.pending.dismiss')"
                    :loading="acting === item.id"
                    data-testid="pending-dismiss"
                    @click="act(item, 'dismiss')"
                  />
                </template>
                <q-btn
                  v-else-if="item.status === 'dismissed'"
                  flat
                  dense
                  no-caps
                  icon="restore"
                  :label="$t('repertoire.pending.restore')"
                  :loading="acting === item.id"
                  data-testid="pending-restore"
                  @click="act(item, 'restore')"
                />
                <q-chip v-else dense icon="check" data-testid="pending-done">{{
                  $t('repertoire.pending.answered')
                }}</q-chip>
              </div>
            </q-item-section>
          </q-item>
        </q-list>
      </template>
    </div>

    <q-dialog v-model="answering.open" data-testid="pending-dialog">
      <q-card v-if="answering.item" class="pending-page__dialog">
        <q-card-section class="text-h6">{{
          $t('repertoire.pending.answerTitle', {
            path: sanPath(answering.item.path)
          })
        }}</q-card-section>
        <q-card-section class="q-pt-none">
          <div class="q-mb-sm">{{ $t('repertoire.pending.answerHint') }}</div>
          <ChessBoard
            :fen="answering.item.fen"
            :orientation="store.graph?.color ?? 'white'"
            :movable-color="answering.saving ? null : store.graph?.color"
            @move="answer"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            v-close-popup
            flat
            no-caps
            :label="$t('common.actions.cancel')"
          />
        </q-card-actions>
        <q-inner-loading :showing="answering.saving" />
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
/**
 * The pending positions of a repertoire built from the user's games (docs/REPERTOIRE.md, "En
 * suspens"): popular replies on Lichess its games did not cover. Nothing enters the repertoire
 * without an answer: answering plays the user's move on a board (one change, undoable from the
 * notice), dismissing sets the position aside (restorable).
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import { useRepertoireStore } from '@/stores/repertoire'
import { apiErrorMessage } from '@/utils/apiError'
import { pendingReasonText, sanPath } from '@/utils/repertoireAuto'
import { intlLocale, t } from '@/i18n'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const router = useRouter()
const $q = useQuasar()
const store = useRepertoireStore()

const repertoireId = computed(() => /** @type {string} */ (route.params.id))
/** @type {import('vue').Ref<import('@/stores/repertoire').PendingPosition[]|null>} */
const items = ref(null)
const open = ref(0)
const loadError = ref('')
/** @type {import('vue').Ref<string|null>} the position being dismissed or restored */
const acting = ref(null)
const answering = reactive({
  open: false,
  /** @type {import('@/stores/repertoire').PendingPosition|null} */
  item: null,
  saving: false
})

/** @param {import('@/stores/repertoire').PendingPosition} item */
function caption(item) {
  const parts = [pendingReasonText(item.reason)]
  if (item.share !== null)
    parts.push(
      t('repertoire.pending.share', {
        share: item.share.toLocaleString(intlLocale(), {
          maximumFractionDigits: 1
        })
      })
    )
  if (item.score !== null)
    parts.push(t('repertoire.pending.score', { n: item.score }))
  return parts.join(' · ')
}

/** @param {{open: number, items: import('@/stores/repertoire').PendingPosition[]}} list */
function show(list) {
  items.value = list.items
  open.value = list.open
}

async function load() {
  loadError.value = ''
  items.value = null
  try {
    if (store.graph?.id !== repertoireId.value)
      await store.load(repertoireId.value)
    show(await store.fetchPending())
  } catch (e) {
    loadError.value = apiErrorMessage(e, {
      404: t('repertoire.stats.notFound')
    })
  }
}

/**
 * @param {import('@/stores/repertoire').PendingPosition} item
 * @param {'dismiss'|'restore'} action
 */
async function act(item, action) {
  acting.value = item.id
  try {
    show(await store.pendingAction(item.id, action))
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    await load()
  } finally {
    acting.value = null
  }
}

/** @param {import('@/stores/repertoire').PendingPosition} item */
function openAnswer(item) {
  Object.assign(answering, { open: true, item, saving: false })
}

/** @param {{uci: string}} move the user's move on the board */
async function answer(move) {
  const item = answering.item
  if (!item || answering.saving) return
  answering.saving = true
  const change = await store.answerPending(item.id, move.uci)
  answering.saving = false
  answering.open = false
  if (change) {
    $q.notify({
      type: 'positive',
      message: t('repertoire.pending.added', { path: sanPath(item.path) }),
      actions: [
        {
          label: t('common.actions.cancel'),
          color: 'white',
          noCaps: true,
          handler: async () => {
            await store.undo()
            await load()
          }
        },
        {
          label: t('repertoire.pending.openEditor'),
          color: 'white',
          noCaps: true,
          handler: () => router.push(`/repertoire/${repertoireId.value}`)
        }
      ]
    })
  } else {
    $q.notify({
      type: 'negative',
      message:
        store.failure?.status === 409
          ? t('repertoire.pending.occupied')
          : t('repertoire.pending.refused')
    })
  }
  await load()
}

onMounted(load)
watch(repertoireId, load)
</script>

<style scoped lang="scss">
.pending-page {
  max-width: 760px;
  margin: 0 auto;
}

.pending-page__item--off {
  opacity: 0.6;
}

.pending-page__dialog {
  width: 480px;
  max-width: 94vw;
}
</style>
