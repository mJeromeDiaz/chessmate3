<template>
  <div
    class="move-actions row items-center q-gutter-xs"
    data-testid="move-actions"
  >
    <div class="text-weight-medium q-mr-sm">{{ label }}</div>

    <q-btn-dropdown
      flat
      dense
      no-caps
      icon="edit_note"
      :label="$t('repertoire.moveActions.annotate')"
      data-testid="move-annotate"
    >
      <q-list dense style="min-width: 220px">
        <q-item-label header>{{
          $t('repertoire.moveActions.move')
        }}</q-item-label>
        <q-item
          v-for="n in MOVE_NAGS"
          :key="n.nag"
          clickable
          :active="move.nags.includes(n.nag)"
          :data-testid="`nag-${n.nag}`"
          @click="toggleNag(n.nag, MOVE_NAGS)"
        >
          <q-item-section avatar class="text-weight-bold">{{
            n.symbol
          }}</q-item-section>
          <q-item-section>{{ n.label }}</q-item-section>
        </q-item>
        <q-separator />
        <q-item-label header>{{
          $t('repertoire.moveActions.position')
        }}</q-item-label>
        <q-item
          v-for="n in POSITION_NAGS"
          :key="n.nag"
          clickable
          :active="move.nags.includes(n.nag)"
          @click="toggleNag(n.nag, POSITION_NAGS)"
        >
          <q-item-section avatar class="text-weight-bold">{{
            n.symbol
          }}</q-item-section>
          <q-item-section>{{ n.label }}</q-item-section>
        </q-item>
        <q-separator />
        <q-item v-close-popup clickable @click="openComment">
          <q-item-section avatar><q-icon name="comment" /></q-item-section>
          <q-item-section>{{
            move.comment
              ? $t('repertoire.moveActions.editComment')
              : $t('repertoire.moveActions.addComment')
          }}</q-item-section>
        </q-item>
      </q-list>
    </q-btn-dropdown>

    <q-btn
      v-if="!isMain"
      flat
      dense
      no-caps
      icon="vertical_align_top"
      :label="$t('repertoire.moveActions.promote')"
      data-testid="move-promote"
      @click="store.promote(move.id)"
    />
    <q-btn
      flat
      dense
      no-caps
      color="negative"
      icon="delete"
      :label="$t('repertoire.moveActions.delete')"
      data-testid="move-delete"
      @click="confirmDelete"
    />

    <q-dialog v-model="commenting">
      <q-card style="width: 520px; max-width: 92vw">
        <q-card-section class="text-subtitle1">{{
          $t('repertoire.moveActions.commentOn', { move: label })
        }}</q-card-section>
        <q-card-section class="q-pt-none">
          <q-input
            v-model="draft"
            type="textarea"
            autogrow
            autofocus
            :maxlength="COMMENT_MAX"
            counter
            :hint="$t('repertoire.moveActions.plainText')"
            data-testid="move-comment"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            v-close-popup
            flat
            no-caps
            :label="$t('common.actions.cancel')"
          />
          <q-btn
            v-close-popup
            color="primary"
            no-caps
            :label="$t('common.actions.save')"
            data-testid="move-comment-save"
            @click="saveComment"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>

<script setup>
/**
 * Actions on the current move of the repertoire editor: annotation (NAG, comment), main line,
 * deletion (confirmed, with what it removes; to the trash). Every action is saved at once through
 * the store.
 */
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRepertoireStore } from '@/stores/repertoire'
import { MOVE_NAGS, POSITION_NAGS } from '@/utils/chess/nags'
import { moveNumber } from '@/components/chess/moveTree'
import { t } from '@/i18n'

/** Same limits as the API (App\Entity\Repertoire\Move). */
const COMMENT_MAX = 2000
const MAX_NAGS = 4

const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/repertoire').Move>} */
  move: { type: Object, required: true }
})

const $q = useQuasar()
const store = useRepertoireStore()
const commenting = ref(false)
const draft = ref('')

const label = computed(() => {
  const from = store.graph?.positions[props.move.from]
  return from ? `${moveNumber(from)}${props.move.san}` : props.move.san
})
const isMain = computed(
  () => store.index?.out.get(props.move.from)?.[0]?.id === props.move.id
)

/**
 * One NAG per group (move or position assessment); clicking the active one removes it.
 *
 * @param {number} nag
 * @param {{nag: number}[]} group
 */
function toggleNag(nag, group) {
  const inGroup = new Set(group.map(n => n.nag))
  const active = props.move.nags.includes(nag)
  const nags = props.move.nags.filter(n => !inGroup.has(n))
  if (!active) nags.push(nag)
  store.annotate(props.move.id, {
    comment: props.move.comment,
    nags: nags.slice(-MAX_NAGS)
  })
}

function openComment() {
  draft.value = props.move.comment ?? ''
  commenting.value = true
}

function saveComment() {
  store.annotate(props.move.id, {
    comment: draft.value,
    nags: props.move.nags
  })
}

function confirmDelete() {
  const { moveIds } = store.removalOf(props.move.id)
  const others = moveIds.length - 1
  $q.dialog({
    title: t('repertoire.moveActions.deleteTitle', { move: label.value }),
    message:
      others > 0
        ? t('repertoire.moveActions.deleteMany', { n: others }, others)
        : t('repertoire.moveActions.deleteOne'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      color: 'negative',
      noCaps: true,
      label: t('repertoire.moveActions.delete')
    },
    persistent: false
  }).onOk(() => store.deleteMove(props.move.id))
}
</script>
