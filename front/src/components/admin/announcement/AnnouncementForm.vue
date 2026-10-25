<template>
  <q-dialog
    :model-value="open"
    maximized-on-mobile
    @update:model-value="!$event && emit('close')"
  >
    <q-card class="announcement-form" data-testid="announcement-form">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6">{{
          announcement
            ? $t('admin.announcementForm.edit')
            : $t('admin.announcementForm.new')
        }}</div>
        <q-space />
        <q-btn
          flat
          round
          dense
          icon="close"
          :aria-label="$t('admin.announcementForm.close')"
          @click="emit('close')"
        />
      </q-card-section>

      <q-card-section class="announcement-form__fields">
        <q-input
          v-model="form.titleFr"
          outlined
          dense
          :label="$t('admin.announcementForm.titleFr')"
          :maxlength="TITLE_LENGTH"
          :error="!!err('titleFr')"
          :error-message="err('titleFr')"
          data-testid="announcement-title-fr"
        />
        <div>
          <div class="announcement-form__label">{{
            $t('admin.announcementForm.bodyFr')
          }}</div>
          <q-editor
            v-model="form.bodyFr"
            :toolbar="EDITOR_TOOLBAR"
            min-height="160px"
            data-testid="announcement-body-fr"
          />
          <div v-if="err('bodyFr')" class="announcement-form__error">{{
            err('bodyFr')
          }}</div>
        </div>

        <q-toggle
          v-model="form.english"
          :label="$t('admin.announcementForm.english')"
          data-testid="announcement-english"
        />
        <template v-if="form.english">
          <q-input
            v-model="form.titleEn"
            outlined
            dense
            :label="$t('admin.announcementForm.titleEn')"
            :maxlength="TITLE_LENGTH"
            :error="!!err('titleEn')"
            :error-message="err('titleEn')"
            data-testid="announcement-title-en"
          />
          <div>
            <div class="announcement-form__label">{{
              $t('admin.announcementForm.bodyEn')
            }}</div>
            <q-editor
              v-model="form.bodyEn"
              :toolbar="EDITOR_TOOLBAR"
              min-height="160px"
              data-testid="announcement-body-en"
            />
            <div v-if="err('bodyEn')" class="announcement-form__error">{{
              err('bodyEn')
            }}</div>
          </div>
        </template>
        <p v-else class="announcement-form__hint">{{
          $t('admin.announcementForm.englishHint')
        }}</p>

        <div class="announcement-form__dates">
          <q-input
            v-model="form.startsAt"
            type="datetime-local"
            outlined
            dense
            stack-label
            :label="$t('admin.announcementForm.startsAt')"
            :hint="$t('admin.announcementForm.startsAtHint')"
            :error="!!err('startsAt')"
            :error-message="err('startsAt')"
            data-testid="announcement-starts"
          />
          <q-input
            v-model="form.endsAt"
            type="datetime-local"
            outlined
            dense
            stack-label
            :label="$t('admin.announcementForm.endsAt')"
            :hint="$t('admin.announcementForm.endsAtHint')"
            :error="!!err('endsAt')"
            :error-message="err('endsAt')"
            data-testid="announcement-ends"
          />
        </div>

        <div class="announcement-form__preview">
          <div class="announcement-form__preview-head">
            <span class="announcement-form__label">{{
              $t('admin.announcementForm.preview')
            }}</span>
            <q-btn-toggle
              v-if="form.english"
              v-model="previewLocale"
              dense
              no-caps
              unelevated
              toggle-color="primary"
              :options="[
                { value: 'fr', label: 'FR' },
                { value: 'en', label: 'EN' }
              ]"
            />
          </div>
          <div class="announcement-form__preview-card">
            <div class="announcement-form__preview-title">{{
              preview.title || '—'
            }}</div>
            <!-- What the players will see: the server sanitizes it once saved. -->
            <div
              class="announcement__body"
              data-testid="announcement-preview"
              v-html="preview.body"
            />
          </div>
        </div>
      </q-card-section>

      <q-card-section v-if="error" class="q-pt-none">
        <q-banner class="cm-banner--danger" rounded>{{ error }}</q-banner>
      </q-card-section>
      <q-card-actions align="right" class="announcement-form__actions">
        <q-btn
          flat
          no-caps
          :label="$t('common.actions.cancel')"
          @click="emit('close')"
        />
        <q-btn
          v-if="draft"
          outline
          no-caps
          color="primary"
          :label="$t('admin.announcementForm.saveDraft')"
          :loading="saving === 'draft'"
          :disable="!valid || saving !== null"
          data-testid="announcement-save-draft"
          @click="save(false)"
        />
        <q-btn
          unelevated
          no-caps
          color="primary"
          :label="
            draft
              ? $t('admin.announcementForm.publish')
              : $t('common.actions.save')
          "
          :loading="saving === 'publish'"
          :disable="!valid || saving !== null"
          data-testid="announcement-publish"
          @click="draft ? confirmPublish() : save(false)"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * An announcement's form (docs/ANNOUNCEMENTS.md, § 2): French title and text, the English ones
 * optional (both or neither), an optional start and end, and a preview as the modal shows it. A
 * new one or a draft is saved as a draft or published (after a confirmation); a published one is
 * only saved.
 */
import { computed, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  EDITOR_TOOLBAR,
  TITLE_LENGTH,
  announcementForm,
  announcementFormValid,
  announcementPayload,
  fieldlessViolations
} from '@/utils/admin/announcement'
import { violationOf, violationsOf } from '@/utils/admin/shop'
import { t } from '@/i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** @type {import('vue').PropType<import('@/utils/admin/announcement').AdminAnnouncement|null>} */
  announcement: { type: Object, default: null }
})

const emit = defineEmits(['close', 'saved'])

const $q = useQuasar()
const form = ref(announcementForm(null))
/** @type {import('vue').Ref<'draft'|'publish'|null>} */
const saving = ref(null)
const error = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])
/** @type {import('vue').Ref<'fr'|'en'>} */
const previewLocale = ref('fr')

/** New or still a draft: saved as such, or published. */
const draft = computed(
  () => !props.announcement || props.announcement.status === 'draft'
)
const valid = computed(() => announcementFormValid(form.value))
const preview = computed(() =>
  form.value.english && previewLocale.value === 'en'
    ? { title: form.value.titleEn, body: form.value.bodyEn }
    : { title: form.value.titleFr, body: form.value.bodyFr }
)

watch(
  () => [props.open, props.announcement],
  () => {
    if (!props.open) return
    form.value = announcementForm(props.announcement)
    previewLocale.value = 'fr'
    error.value = ''
    violations.value = []
  },
  { immediate: true }
)

/** @param {string} path */
function err(path) {
  return violationOf(violations.value, path)
}

function confirmPublish() {
  $q.dialog({
    title: t('admin.announcementForm.publishTitle'),
    message: form.value.startsAt
      ? t('admin.announcementForm.publishScheduled')
      : t('admin.announcementForm.publishNow'),
    cancel: { label: t('common.actions.cancel'), flat: true, noCaps: true },
    ok: {
      label: t('admin.announcementForm.publish'),
      unelevated: true,
      noCaps: true,
      color: 'primary'
    }
  }).onOk(() => save(true))
}

/** @param {boolean} publish */
async function save(publish) {
  saving.value = publish ? 'publish' : 'draft'
  error.value = ''
  violations.value = []
  const payload = announcementPayload(form.value, publish)
  try {
    const saved = props.announcement
      ? await adminApi.updateAnnouncement(props.announcement.id, payload)
      : await adminApi.createAnnouncement(payload)
    emit('saved', saved)
  } catch (e) {
    violations.value = violationsOf(e)
    // A refusal tied to no field (an archived announcement) goes in the banner.
    error.value = violations.value.length
      ? fieldlessViolations(violations.value) ||
        t('admin.announcementForm.checkFields')
      : apiErrorMessage(e, { 404: t('admin.announcementForm.gone') })
  } finally {
    saving.value = null
  }
}
</script>

<style scoped lang="scss">
.announcement-form {
  width: 100%;
  max-width: 720px;
}

.announcement-form__fields {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.announcement-form__label {
  margin-bottom: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink-soft);
}

.announcement-form__error {
  margin-top: 4px;
  font-size: 12px;
  color: var(--cm-danger);
}

.announcement-form__hint {
  margin: -6px 0 0;
  font-size: 13px;
  color: var(--cm-muted);
}

.announcement-form__dates {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: 1fr 1fr;
  }
}

.announcement-form__preview-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;

  .announcement-form__label {
    margin-bottom: 0;
  }
}

.announcement-form__preview-card {
  padding: 16px 18px;
  border: 1px solid var(--cm-line);
  border-radius: 16px;
  background: var(--cm-surface);
}

.announcement-form__preview-title {
  margin-bottom: 8px;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.25;
}

.announcement-form__actions {
  flex-wrap: wrap;
  gap: 4px;
}
</style>
