<template>
  <div class="repertoire-lines" data-testid="repertoire-lines">
    <q-input
      v-model="query"
      dense
      outlined
      clearable
      :placeholder="$t('repertoire.lines.filter')"
      data-testid="lines-filter"
    >
      <template #prepend><q-icon name="search" /></template>
    </q-input>
    <div class="text-caption text-grey q-my-sm" data-testid="lines-count">
      {{
        query
          ? $t(
              'repertoire.lines.matching',
              { n: shown.length, total: lines.length },
              shown.length
            )
          : $t('repertoire.lines.count', { n: lines.length }, lines.length)
      }}
    </div>

    <div v-if="!lines.length" class="text-caption text-grey">
      {{ $t('repertoire.lines.none') }}
    </div>
    <q-virtual-scroll
      v-else
      v-slot="{ item: line }"
      :items="shown"
      :virtual-scroll-item-size="56"
      class="repertoire-lines__list"
    >
      <q-item
        :key="line.key"
        clickable
        dense
        class="repertoire-lines__item"
        :class="{ 'repertoire-lines__item--current': line.key === currentKey }"
        data-testid="line-item"
        @click="emit('select', line.path)"
      >
        <q-item-section>
          <q-item-label
            class="repertoire-lines__moves"
            data-testid="line-moves"
          >
            {{ line.text }}<span v-if="line.transposition"> ⤳</span>
          </q-item-label>
          <q-item-label caption class="ellipsis">
            <template v-if="line.opening"
              >{{ line.opening.eco }} {{ line.opening.name }} ·
            </template>
            {{
              $t(
                'repertoire.list.prepared',
                { n: line.userMoves },
                line.userMoves
              )
            }}
            <span
              v-if="line.unanswered"
              class="repertoire-lines__unanswered"
              data-testid="line-unanswered"
            >
              · {{ $t('repertoire.editor.unanswered') }}</span
            >
          </q-item-label>
        </q-item-section>
        <q-item-section side>
          <q-btn
            flat
            round
            dense
            size="sm"
            icon="delete_outline"
            :aria-label="$t('repertoire.lines.delete')"
            data-testid="line-delete"
            @click.stop="confirmDelete(line)"
          />
        </q-item-section>
      </q-item>
    </q-virtual-scroll>
  </div>
</template>

<script setup>
/**
 * The repertoire's lines (docs/REPERTOIRE.md, "Lignes"): one per line end, filtered by moves or
 * opening name; a click shows the line's end on the board (`select` with its path), the bin
 * deletes what only this line has, after its last branching (confirmed, to the trash, undoable).
 */
import { computed, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRepertoireStore } from '@/stores/repertoire'
import { moveNumber } from '@/components/chess/moveTree'
import { filterLines, repertoireLines } from '@/utils/repertoireLines'
import { t } from '@/i18n'

const props = defineProps({
  /** The editor's path: the line ending there is highlighted. */
  path: { type: Array, default: () => [] }
})
const emit = defineEmits({
  /** A line picked: its path (move ids from the root). */
  select: path => Array.isArray(path)
})

const $q = useQuasar()
const store = useRepertoireStore()
const query = ref('')

const lines = computed(() =>
  store.graph && store.index ? repertoireLines(store.index, store.graph) : []
)
const shown = computed(() => filterLines(lines.value, query.value ?? ''))
const currentKey = computed(() => props.path.at(-1) ?? null)

/** @param {import('@/utils/repertoireLines').RepertoireLine} line */
function confirmDelete(line) {
  const cut = store.graph.moves[line.cutMoveId]
  const from = cut ? store.graph.positions[cut.from] : null
  if (!cut || !from) return
  const { moveIds } = store.removalOf(cut.id)
  const others = moveIds.length - 1
  const move = `${moveNumber(from)}${cut.san}`
  $q.dialog({
    title: t('repertoire.lines.deleteTitle'),
    message: [
      line.cutIndex === 0 && lines.value.length === 1
        ? t('repertoire.lines.deleteAll')
        : '',
      others > 0
        ? t('repertoire.lines.deleteMany', { move, n: others }, others)
        : t('repertoire.lines.deleteOne', { move })
    ]
      .filter(Boolean)
      .join(' '),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      color: 'negative',
      noCaps: true,
      label: t('repertoire.lines.delete')
    }
  }).onOk(() => store.deleteMove(cut.id))
}
</script>

<style lang="scss">
.repertoire-lines__list {
  max-height: 55vh;
}
.repertoire-lines__item {
  border-radius: 6px;
}
.repertoire-lines__item--current {
  background: var(--cm-brand-soft);
}
.repertoire-lines__moves {
  white-space: normal;
  line-height: 1.4;
}
.repertoire-lines__unanswered {
  color: #ef6c00;
}
</style>
