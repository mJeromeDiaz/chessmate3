<template>
  <section class="cm-card reward-rule" data-testid="reward-rule">
    <h2 class="cm-card__title">{{
      $t('admin.rewardRuleForm.couronnesGagneesEnSession')
    }}</h2>
    <p class="cm-muted reward-rule__lead">
      {{ $t('admin.rewardRuleForm.uneSessionMeneeAu') }}
    </p>
    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>
    <div v-if="form" class="reward-rule__fields">
      <q-input
        v-for="field in REWARD_RULE_FIELDS"
        :key="field.key"
        v-model.number="form[field.key]"
        type="number"
        min="0"
        :max="field.max"
        outlined
        dense
        :label="field.label"
        :suffix="field.suffix"
        :hint="field.hint"
        :error="!!err(field.key)"
        :error-message="err(field.key)"
        :data-testid="`reward-rule-${field.key}`"
      />
    </div>
    <p
      v-if="form"
      class="reward-rule__preview"
      data-testid="reward-rule-preview"
    >
      {{
        $t('admin.rewardRuleForm.preview', {
          perfect: crowns(preview.perfect),
          near: crowns(preview.near),
          missed: crowns(preview.missed)
        })
      }}
    </p>
    <div v-if="saved" class="cm-muted reward-rule__saved">
      {{
        $t('admin.rewardRuleForm.modified', {
          date: saved.updatedAt
            ? formatDate(saved.updatedAt)
            : $t('admin.rewardRuleForm.never'),
          by: saved.updatedBy
            ? $t('admin.rewardRuleForm.by', { who: saved.updatedBy })
            : $t('admin.rewardRuleForm.defaults')
        })
      }}
    </div>
    <q-btn
      color="primary"
      no-caps
      unelevated
      :label="$t('admin.rewardRuleForm.enregistrer')"
      :loading="saving"
      :disable="!form"
      data-testid="reward-rule-save"
      @click="save"
    />
  </section>
</template>

<script setup>
/**
 * What a completed session earns (docs/SHOP.md, § 4 bis): crowns per paid hour, the factor of each
 * tier, the paid minutes of a day. Applies to the next sessions.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { formatDate } from '@/utils/format'
import {
  REWARD_RULE_FIELDS,
  rewardPreview,
  violationOf,
  violationsOf
} from '@/utils/admin/shop'
import { crowns } from '@/utils/shop'

const $q = useQuasar()
/** @type {import('vue').Ref<Record<string, number>|null>} */
const form = ref(null)
/** @type {import('vue').Ref<{updatedAt: string|null, updatedBy: string|null}|null>} */
const saved = ref(null)
const saving = ref(false)
const error = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])

const preview = computed(() =>
  rewardPreview(
    {
      crownsPerHour: Number(form.value?.crownsPerHour) || 0,
      perfectPercent: Number(form.value?.perfectPercent) || 0,
      nearPercent: Number(form.value?.nearPercent) || 0,
      missedPercent: Number(form.value?.missedPercent) || 0
    },
    20
  )
)

/** @param {Record<string, unknown>} rule */
function fill(rule) {
  form.value = Object.fromEntries(
    REWARD_RULE_FIELDS.map(f => [f.key, Number(rule[f.key])])
  )
  saved.value = {
    updatedAt: /** @type {string|null} */ (rule.updatedAt ?? null),
    updatedBy: /** @type {string|null} */ (rule.updatedBy ?? null)
  }
}

/** @param {string} path */
function err(path) {
  return violationOf(violations.value, path)
}

async function save() {
  if (!form.value) return
  saving.value = true
  error.value = ''
  violations.value = []
  try {
    fill(
      await adminApi.saveShopRewardRule(
        /** @type {any} */ (
          Object.fromEntries(
            REWARD_RULE_FIELDS.map(f => [f.key, form.value?.[f.key]])
          )
        )
      )
    )
    $q.notify({ type: 'positive', message: t('admin.rewardRuleForm.saved') })
  } catch (e) {
    violations.value = violationsOf(e)
    error.value = violations.value.length
      ? t('admin.offerForm.checkFields')
      : apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    fill(await adminApi.shopRewardRule())
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.reward-rule {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  max-width: 760px;
}

.reward-rule__lead,
.reward-rule__preview,
.reward-rule__saved {
  margin: 0;
}

.reward-rule__preview {
  font-weight: 600;
}

.reward-rule__fields {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px 14px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
