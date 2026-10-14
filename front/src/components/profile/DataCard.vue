<template>
  <section class="cm-card profile-card" data-testid="profile-data">
    <h2 class="cm-card__title">{{ $t('profile.data.title') }}</h2>
    <div class="profile-row profile-row--flat">
      <div class="profile-row__text">
        <div class="profile-row__title">{{ $t('profile.data.export') }}</div>
        <div class="profile-row__sub">{{ $t('profile.data.exportHint') }}</div>
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn"
        :label="$t('profile.data.exportButton')"
        :loading="exporting"
        data-testid="profile-export"
        @click="exportData"
      />
    </div>
    <div v-if="exportError" class="profile-row__sub text-negative q-mb-sm">{{
      exportError
    }}</div>
    <div class="profile-row">
      <div class="profile-row__text">
        <div class="profile-row__title profile-row__title--danger">{{
          $t('profile.data.delete')
        }}</div>
        <div class="profile-row__sub">{{ $t('profile.data.deleteHint') }}</div>
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn profile-btn--danger"
        :label="$t('profile.data.deleteButton')"
        data-testid="profile-delete"
        @click="deleting = true"
      />
    </div>
    <AccountDeletionDialog v-model="deleting" />
  </section>
</template>

<script setup>
/** Profile: the export of every data (a ZIP) and the account deletion. */
import { ref } from 'vue'
import AccountDeletionDialog from '@/components/profile/AccountDeletionDialog.vue'
import { useDataExport } from '@/composables/profile/useDataExport'

const deleting = ref(false)
const { exporting, error: exportError, exportData } = useDataExport()
</script>
