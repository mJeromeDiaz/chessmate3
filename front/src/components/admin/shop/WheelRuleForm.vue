<template>
  <section class="cm-card wheel-rule" data-testid="wheel-rule">
    <h2 class="cm-card__title">{{ $t('admin.wheelRuleForm.title') }}</h2>
    <p class="cm-muted wheel-rule__lead">{{ $t('admin.wheelRuleForm.lead') }}</p>
    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>
    <div v-if="form" class="wheel-rule__fields">
      <q-input
        v-for="prize in WHEEL_PRIZES"
        :key="prize"
        v-model.number="form[prize]"
        type="number"
        min="0"
        max="10000"
        outlined
        dense
        :label="$t(`admin.wheelRuleForm.prizes.${prize}`)"
        :suffix="$t('admin.wheelRuleForm.chance', { n: chances[prize] })"
        :error="!!err(prize)"
        :error-message="err(prize)"
        :data-testid="`wheel-rule-${prize}`"
      />
      <q-input
        v-model.number="form.freezePercent"
        type="number"
        min="0"
        max="100"
        outlined
        dense
        :label="$t('admin.wheelRuleForm.freezePercent')"
        suffix="%"
        :hint="$t('admin.wheelRuleForm.freezeHint')"
        :error="!!err('freezePercent')"
        :error-message="err('freezePercent')"
        data-testid="wheel-rule-freezePercent"
      />
    </div>
    <p v-if="form" class="wheel-rule__preview" data-testid="wheel-rule-preview">
      {{ $t('admin.wheelRuleForm.preview', { n: average }) }}
    </p>
    <div v-if="saved" class="cm-muted wheel-rule__saved">
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
      data-testid="wheel-rule-save"
      @click="save"
    />
  </section>
</template>

<script setup>
/**
 * The gift wheel's odds (docs/SHOP.md, § 4 ter): a weight per prize, each one's chance shown, and
 * a gift's share of streak freezes. Applies to the next spins.
 */
import { computed, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'
import { formatDate } from '@/utils/format'
import {
  WHEEL_PRIZES,
  violationOf,
  violationsOf,
  wheelAverage,
  wheelChances
} from '@/utils/admin/shop'

const FIELDS = [...WHEEL_PRIZES, 'freezePercent']

const $q = useQuasar()
/** @type {import('vue').Ref<Record<string, number>|null>} */
const form = ref(null)
/** @type {import('vue').Ref<{updatedAt: string|null, updatedBy: string|null}|null>} */
const saved = ref(null)
const saving = ref(false)
const error = ref('')
/** @type {import('vue').Ref<{propertyPath: string, title: string}[]>} */
const violations = ref([])

const chances = computed(() => wheelChances(form.value ?? {}))
const average = computed(() => wheelAverage(form.value ?? {}))

/** @param {Record<string, unknown>} odds */
function fill(odds) {
  form.value = Object.fromEntries(FIELDS.map(f => [f, Number(odds[f])]))
  saved.value = {
    updatedAt: /** @type {string|null} */ (odds.updatedAt ?? null),
    updatedBy: /** @type {string|null} */ (odds.updatedBy ?? null)
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
      await adminApi.saveShopWheelRule(
        /** @type {any} */ (
          Object.fromEntries(FIELDS.map(f => [f, form.value?.[f]]))
        )
      )
    )
    $q.notify({ type: 'positive', message: t('admin.wheelRuleForm.saved') })
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
    fill(await adminApi.shopWheelRule())
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.wheel-rule {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  max-width: 760px;
}

.wheel-rule__lead,
.wheel-rule__preview,
.wheel-rule__saved {
  margin: 0;
}

.wheel-rule__preview {
  font-weight: 600;
}

.wheel-rule__fields {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px 14px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
