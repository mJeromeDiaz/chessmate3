<template>
  <q-dialog
    :model-value="open"
    @update:model-value="!$event && emit('close')"
  >
    <q-card class="slide-form" data-testid="slide-form">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{
          slide ? 'Modifier le slide' : 'Nouveau slide'
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
      <q-card-section class="slide-form__fields">
        <q-select
          v-model="form.offerId"
          :options="offerOptions"
          emit-value
          map-options
          outlined
          dense
          label="Offre *"
          :error="!!err('offerId')"
          :error-message="err('offerId')"
          data-testid="slide-offer"
        />
        <q-input
          v-model="form.tag"
          outlined
          dense
          label="Accroche *"
          hint="« OFFRE DU MOMENT »"
          maxlength="40"
          :error="!!err('tag')"
          :error-message="err('tag')"
          data-testid="slide-tag"
        />
        <div>
          <div class="slide-form__label">Prof</div>
          <div class="slide-form__images">
            <button
              v-for="name in SLIDE_IMAGES"
              :key="name"
              type="button"
              class="slide-form__image"
              :class="{ 'slide-form__image--on': form.image === name }"
              :aria-pressed="form.image === name"
              :aria-label="name"
              @click="form.image = name"
            >
              <img :src="profImage(name)" alt="" />
            </button>
          </div>
        </div>
        <q-input
          v-model="form.startsAt"
          type="datetime-local"
          outlined
          dense
          stack-label
          label="Affiché à partir de"
        />
        <q-input
          v-model="form.endsAt"
          type="datetime-local"
          outlined
          dense
          stack-label
          label="Jusqu’au"
          :error="!!err('endsAt')"
          :error-message="err('endsAt')"
        />
        <q-input
          v-model.number="form.position"
          type="number"
          outlined
          dense
          label="Ordre"
        />
        <q-toggle v-model="form.suspended" label="Suspendu" />
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
          :label="slide ? 'Enregistrer' : 'Créer'"
          :loading="saving"
          data-testid="slide-save"
          @click="save"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * A carousel slide (docs/SHOP.md): an offer, a catchline, a professor, a window. Shown only while
 * its offer is on sale.
 */
import { computed, ref, watch } from 'vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  SLIDE_IMAGES,
  fromLocalInput,
  toLocalInput,
  violationOf,
  violationsOf
} from '@/utils/admin/shop'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('vue').PropType<import('@/utils/admin/shop').AdminSlide|null>} */
  slide: { type: Object, default: null },
  /** @type {import('vue').PropType<import('@/utils/admin/shop').AdminOffer[]>} */
  offers: { type: Array, required: true }
})

const emit = defineEmits(['close', 'saved'])

const form = ref(blank())
const saving = ref(false)
const error = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])

const offerOptions = computed(() =>
  props.offers
    .filter(o => !o.comingSoon)
    .map(o => ({ value: o.id, label: o.name }))
)

function blank() {
  return {
    offerId: '',
    tag: '',
    image: SLIDE_IMAGES[0],
    position: 0,
    startsAt: '',
    endsAt: '',
    suspended: false
  }
}

watch(
  () => [props.open, props.slide],
  () => {
    if (!props.open) return
    const s = props.slide
    form.value = s
      ? {
          offerId: s.offerId,
          tag: s.tag,
          image: s.image,
          position: s.position,
          startsAt: toLocalInput(s.startsAt),
          endsAt: toLocalInput(s.endsAt),
          suspended: s.suspended
        }
      : blank()
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
  const payload = {
    ...form.value,
    position: Number(form.value.position) || 0,
    startsAt: fromLocalInput(form.value.startsAt),
    endsAt: fromLocalInput(form.value.endsAt)
  }
  try {
    const catalog = props.slide
      ? await adminApi.updateSlide(props.slide.id, payload)
      : await adminApi.createSlide(payload)
    emit('saved', catalog)
  } catch (e) {
    violations.value = violationsOf(e)
    error.value = violations.value.length
      ? 'Vérifie les champs en rouge.'
      : apiErrorMessage(e, { 404: 'Ce slide n’existe plus.' })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.slide-form {
  width: 100%;
  max-width: 560px;
}

.slide-form__fields {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.slide-form__label {
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink-soft);
}

.slide-form__images {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.slide-form__image {
  width: 56px;
  height: 56px;
  padding: 2px;
  border: 2px solid var(--cm-line);
  border-radius: 14px;
  background: var(--cm-page);
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  &--on {
    border-color: var(--cm-brand);
    background: var(--cm-brand-soft);
  }
}
</style>
