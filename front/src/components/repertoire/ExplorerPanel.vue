<template>
  <div class="explorer" data-testid="explorer-panel">
    <div class="row items-center q-gutter-sm q-mb-sm">
      <q-btn-toggle
        v-model="source"
        dense
        no-caps
        unelevated
        toggle-color="primary"
        :options="[
          { label: 'Maîtres', value: 'masters' },
          { label: 'Lichess', value: 'lichess' }
        ]"
        data-testid="explorer-source"
      />
      <q-btn
        v-if="source === 'lichess'"
        flat
        dense
        no-caps
        icon="tune"
        :label="filterSummary"
      >
        <q-menu>
          <div class="q-pa-md" style="max-width: 320px">
            <div class="text-subtitle2 q-mb-xs">Cadences</div>
            <div class="row q-gutter-xs q-mb-md">
              <q-chip
                v-for="s in SPEEDS"
                :key="s.value"
                clickable
                dense
                :outline="!speeds.includes(s.value)"
                color="primary"
                :text-color="speeds.includes(s.value) ? 'white' : 'primary'"
                @click="toggle(speeds, s.value)"
                >{{ s.label }}</q-chip
              >
            </div>
            <div class="text-subtitle2 q-mb-xs">Classements</div>
            <div class="row q-gutter-xs">
              <q-chip
                v-for="r in RATINGS"
                :key="r"
                clickable
                dense
                :outline="!ratings.includes(r)"
                color="primary"
                :text-color="ratings.includes(r) ? 'white' : 'primary'"
                @click="toggle(ratings, r)"
                >{{ r === 0 ? '< 1000' : r }}</q-chip
              >
            </div>
            <div class="text-caption text-grey q-mt-sm">
              Aucune sélection : toutes.
            </div>
          </div>
        </q-menu>
      </q-btn>
    </div>

    <div
      v-if="data?.opening"
      class="text-caption cm-muted q-mb-xs"
      data-testid="explorer-opening"
    >
      {{ data.opening.eco }} · {{ data.opening.name }}
    </div>

    <q-banner
      v-if="failure"
      dense
      rounded
      class="cm-banner--neutral"
      data-testid="explorer-failure"
    >
      {{ failure.message }}
      <template #action>
        <q-btn
          v-if="failure.reason === 'no_token'"
          flat
          dense
          no-caps
          color="primary"
          label="Mon profil"
          to="/profile"
        />
        <q-btn
          v-else
          flat
          dense
          no-caps
          color="primary"
          label="Réessayer"
          @click="retry"
        />
      </template>
    </q-banner>

    <div v-else-if="loading" class="q-gutter-y-xs">
      <q-skeleton v-for="n in 5" :key="n" type="text" height="28px" />
    </div>

    <div
      v-else-if="data && data.moves.length === 0"
      class="text-grey q-pa-sm"
      data-testid="explorer-empty"
    >
      Aucune partie dans cette base pour cette position.
    </div>

    <q-markup-table v-else-if="data" flat dense class="explorer__table">
      <thead>
        <tr>
          <th class="text-left">Coup</th>
          <th class="text-right">Parties</th>
          <th class="text-left explorer__bar-head">Blancs / nulle / Noirs</th>
          <th class="text-right gt-xs">Elo moy.</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="move in data.moves"
          :key="move.uci"
          class="explorer__row"
          data-testid="explorer-move"
          :data-uci="move.uci"
          @click="emit('play', move.uci)"
          @mouseenter="emit('hover', move.uci)"
          @mouseleave="emit('hover', null)"
        >
          <td class="text-left text-weight-medium">
            {{ move.san }}
            <q-icon
              v-if="known.includes(move.uci)"
              name="check_circle"
              color="positive"
              size="14px"
            >
              <q-tooltip>Déjà dans le répertoire</q-tooltip>
            </q-icon>
          </td>
          <td class="text-right">
            <span class="cm-muted q-mr-xs">{{ share(move.total) }}</span>
            {{ formatGames(move.total) }}
          </td>
          <td><ResultBar :counts="move" /></td>
          <td class="text-right cm-muted gt-xs">
            {{ move.averageRating ?? '' }}
          </td>
        </tr>
        <tr class="explorer__total">
          <td class="text-left">Σ</td>
          <td class="text-right">{{ formatGames(data.total) }}</td>
          <td><ResultBar :counts="data" /></td>
          <td class="gt-xs" />
        </tr>
      </tbody>
    </q-markup-table>

    <div class="text-caption text-grey q-mt-sm">
      Statistiques de l’explorateur d’ouvertures de Lichess. Cliquez sur un coup
      pour l’ajouter au répertoire.
    </div>
  </div>
</template>

<script setup>
/**
 * Game statistics of the editor's current position (Lichess opening explorer, through the API):
 * masters or Lichess games with speed and rating filters. A click on a move plays it (and adds
 * it to the repertoire); hovering it shows it on the board.
 */
import { computed, toRef } from 'vue'
import ResultBar from './ResultBar.vue'
import { useExplorer } from '@/composables/repertoire/useExplorer'
import { formatGames } from '@/utils/chess/lichess'

const SPEEDS = [
  { value: 'ultraBullet', label: 'UltraBullet' },
  { value: 'bullet', label: 'Bullet' },
  { value: 'blitz', label: 'Blitz' },
  { value: 'rapid', label: 'Rapide' },
  { value: 'classical', label: 'Classique' },
  { value: 'correspondence', label: 'Correspondance' }
]
const RATINGS = [0, 1000, 1200, 1400, 1600, 1800, 2000, 2200, 2500]

const props = defineProps({
  /** Normalized FEN of the position, null for none. */
  fen: { type: String, default: null },
  /** Ask Lichess only while shown. */
  enabled: { type: Boolean, default: true },
  /** @type {import('vue').PropType<string[]>} UCI of the moves already in the repertoire here */
  known: { type: Array, default: () => [] }
})

const emit = defineEmits({
  /** Play this move (UCI). */
  play: uci => typeof uci === 'string',
  /** The move under the pointer (UCI), null when none. */
  hover: uci => uci === null || typeof uci === 'string'
})

const { source, speeds, ratings, data, loading, failure, retry } = useExplorer(
  toRef(props, 'fen'),
  toRef(props, 'enabled')
)

const filterSummary = computed(() => {
  const count = speeds.value.length + ratings.value.length
  return count ? `Filtres (${count})` : 'Filtres'
})

/**
 * @template T
 * @param {import('vue').Ref<T[]>} list
 * @param {T} value
 */
function toggle(list, value) {
  list.value = list.value.includes(value)
    ? list.value.filter(v => v !== value)
    : [...list.value, value]
}

/** @param {number} games */
function share(games) {
  const total = data.value?.total ?? 0
  return total ? `${Math.round((games * 100) / total)} %` : ''
}
</script>

<style scoped>
.explorer__table :deep(td),
.explorer__table :deep(th) {
  padding: 4px 6px;
}
.explorer__row {
  cursor: pointer;
}
.explorer__row:hover {
  background: rgba(25, 118, 210, 0.08);
}
.explorer__bar-head {
  width: 45%;
}
.explorer__total td {
  font-weight: 600;
  border-top: 1px solid #e0e0e0;
}
</style>
