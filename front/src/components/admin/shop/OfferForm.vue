<template>
  <q-dialog
    :model-value="open"
    :maximized="$q.screen.lt.md"
    @update:model-value="!$event && emit('close')"
  >
    <q-card class="offer-form" data-testid="offer-form">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{
          offer ? `Modifier « ${offer.name} »` : 'Nouvelle offre'
        }}</div>
        <q-space />
        <q-btn
          flat
          round
          dense
          icon="close"
          aria-label="Fermer"
          @click="emit('close')"
        />
      </q-card-section>

      <q-card-section class="offer-form__fields">
        <q-input
          v-model="form.slug"
          outlined
          dense
          label="Identifiant *"
          hint="Minuscules, chiffres et _ ; ne change plus ensuite."
          :readonly="!!offer"
          :error="!!err('slug')"
          :error-message="err('slug')"
          data-testid="offer-slug"
        />
        <q-select
          v-model="form.section"
          :options="SECTION_OPTIONS"
          emit-value
          map-options
          outlined
          dense
          label="Rayon *"
          data-testid="offer-section"
        />
        <q-input
          v-model="form.name"
          outlined
          dense
          label="Nom *"
          maxlength="80"
          :error="!!err('name')"
          :error-message="err('name')"
          data-testid="offer-name"
        />
        <q-input
          v-model="form.subtitle"
          outlined
          dense
          label="Sous-titre"
          hint="Une ligne sous le nom (« Le week-end »)."
          maxlength="80"
        />
        <q-input
          v-model="form.description"
          outlined
          dense
          autogrow
          label="Description *"
          maxlength="255"
          class="offer-form__wide"
          :error="!!err('description')"
          :error-message="err('description')"
          data-testid="offer-description"
        />

        <div class="offer-form__wide">
          <div class="offer-form__label">Contenu</div>
          <div
            v-for="(line, i) in form.contents"
            :key="i"
            class="offer-form__line"
          >
            <q-select
              v-model="line.item"
              :options="ITEM_OPTIONS"
              emit-value
              map-options
              outlined
              dense
              label="Objet"
              class="offer-form__item"
              :error="!!err(`contents[${i}].item`)"
              :error-message="err(`contents[${i}].item`)"
              :data-testid="`offer-item-${i}`"
            />
            <q-input
              v-model.number="line.quantity"
              type="number"
              min="1"
              max="99"
              outlined
              dense
              label="Qté"
              class="offer-form__qty"
              :error="!!err(`contents[${i}].quantity`)"
              :error-message="err(`contents[${i}].quantity`)"
              :data-testid="`offer-quantity-${i}`"
            />
            <q-btn
              flat
              round
              dense
              icon="delete"
              aria-label="Retirer"
              @click="form.contents.splice(i, 1)"
            />
          </div>
          <div v-if="err('contents')" class="offer-form__error">{{
            err('contents')
          }}</div>
          <q-btn
            flat
            dense
            no-caps
            icon="add"
            label="Ajouter un objet"
            data-testid="offer-add-item"
            @click="form.contents.push({ item: 'puzzle_pardon', quantity: 1 })"
          />
        </div>

        <q-input
          v-model.number="form.price"
          type="number"
          min="0"
          outlined
          dense
          label="Prix (couronnes) *"
          :error="!!err('price')"
          :error-message="err('price')"
          data-testid="offer-price"
        />
        <q-input
          v-model.number="form.wasPrice"
          type="number"
          min="1"
          outlined
          dense
          clearable
          label="Prix barré"
          hint="Vide : aucun."
          :error="!!err('wasPrice')"
          :error-message="err('wasPrice')"
          data-testid="offer-was-price"
        />
        <q-input
          v-model="form.startsAt"
          type="datetime-local"
          outlined
          dense
          stack-label
          label="En vente à partir de"
          hint="Vide : tout de suite."
        />
        <q-input
          v-model="form.endsAt"
          type="datetime-local"
          outlined
          dense
          stack-label
          label="Jusqu’au"
          hint="Vide : sans fin."
          :error="!!err('endsAt')"
          :error-message="err('endsAt')"
        />
        <q-input
          v-model.number="form.position"
          type="number"
          outlined
          dense
          label="Ordre dans le rayon"
        />
        <div class="offer-form__toggles">
          <q-toggle
            v-model="form.suspended"
            label="Suspendue"
            data-testid="offer-suspended"
          />
          <q-toggle
            v-model="form.comingSoon"
            label="Bientôt (affichée, pas vendue)"
          />
        </div>
      </q-card-section>

      <q-card-section v-if="error" class="q-pt-none">
        <q-banner class="cm-banner--danger" rounded>{{ error }}</q-banner>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn flat no-caps label="Annuler" @click="emit('close')" />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="offer ? 'Enregistrer' : 'Créer'"
          :loading="saving"
          data-testid="offer-save"
          @click="save"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * An offer of the store, created or changed by an admin (docs/SHOP.md): coded items and their
 * quantities, prices, a sale window, a suspension. The API checks everything (422 per field).
 */
import { ref, watch } from 'vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  ITEM_OPTIONS,
  SECTION_OPTIONS,
  offerForm,
  offerPayload,
  violationOf,
  violationsOf
} from '@/utils/admin/shop'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('vue').PropType<import('@/utils/admin/shop').AdminOffer|null>} */
  offer: { type: Object, default: null }
})

const emit = defineEmits(['close', 'saved'])

const form = ref(offerForm(null))
const saving = ref(false)
const error = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])

watch(
  () => [props.open, props.offer],
  () => {
    if (!props.open) return
    form.value = offerForm(props.offer)
    error.value = ''
    violations.value = []
  },
  { immediate: true }
)

/** @param {string} path */
function err(path) {
  return violationOf(violations.value, path)
}

async function save() {
  saving.value = true
  error.value = ''
  violations.value = []
  try {
    const payload = offerPayload(form.value)
    const catalog = props.offer
      ? await adminApi.updateOffer(props.offer.id, payload)
      : await adminApi.createOffer(payload)
    emit('saved', catalog)
  } catch (e) {
    violations.value = violationsOf(e)
    error.value = violations.value.length
      ? 'Vérifie les champs en rouge.'
      : apiErrorMessage(e, { 404: 'Cette offre n’existe plus.' })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.offer-form {
  width: 100%;
  max-width: 760px;
}

.offer-form__fields {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px 14px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: 1fr 1fr;
  }
}

.offer-form__wide,
.offer-form__toggles {
  grid-column: 1 / -1;
}

.offer-form__label {
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink-soft);
}

.offer-form__line {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.offer-form__item {
  flex: 1;
}

.offer-form__qty {
  width: 90px;
}

.offer-form__error {
  font-size: 12px;
  color: var(--cm-danger);
}

.offer-form__toggles {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}
</style>
