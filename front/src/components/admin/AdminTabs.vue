<template>
  <nav class="admin-tabs" :aria-label="$t('admin.tabs.administration')">
    <q-tabs
      dense
      no-caps
      align="left"
      active-color="primary"
      indicator-color="primary"
    >
      <q-route-tab
        to="/admin"
        exact
        :label="$t('admin.tabs.vueDensemble')"
        data-testid="admin-tab-overview"
      />
      <q-route-tab
        to="/admin/invitations"
        :label="$t('admin.tabs.invitations')"
        data-testid="admin-tab-invitations"
      />
      <q-route-tab
        to="/admin/demandes"
        :label="$t('admin.tabs.demandes')"
        data-testid="admin-tab-requests"
      />
      <q-route-tab
        to="/admin/joueurs"
        :label="$t('admin.tabs.joueurs')"
        data-testid="admin-tab-players"
      />
      <q-route-tab
        to="/admin/positions"
        :label="$t('admin.tabs.positions')"
        data-testid="admin-tab-positions"
      />
      <q-route-tab
        to="/admin/boutique"
        :label="$t('admin.tabs.boutique')"
        data-testid="admin-tab-shop"
      />
    </q-tabs>
  </nav>
</template>

<script setup>
/** The pages of the administration, one address each (shareable, back button works). */
</script>

<style scoped lang="scss">
.admin-tabs {
  margin-bottom: 16px;
  border-bottom: 1px solid var(--cm-line);
}
</style>
