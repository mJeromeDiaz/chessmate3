<template>
  <section class="cm-card profile-card" data-testid="profile-data">
    <h2 class="cm-card__title">Données</h2>
    <div class="profile-row profile-row--flat">
      <div class="profile-row__text">
        <div class="profile-row__title">Exporter mes données</div>
        <div class="profile-row__sub"
          >Puzzles, répertoires, sessions et stats · ZIP (JSON + PGN)</div
        >
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn"
        label="Exporter"
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
        <div class="profile-row__title profile-row__title--danger"
          >Supprimer mon compte</div
        >
        <div class="profile-row__sub">Définitif après 30 jours</div>
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn profile-btn--danger"
        label="Supprimer…"
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
