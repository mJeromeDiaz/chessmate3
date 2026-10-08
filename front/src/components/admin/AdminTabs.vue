<template>
  <nav class="admin-tabs" aria-label="Administration">
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
        label="Vue d’ensemble"
        data-testid="admin-tab-overview"
      />
      <q-route-tab
        to="/admin/invitations"
        label="Invitations"
        data-testid="admin-tab-invitations"
      />
      <q-route-tab
        to="/admin/demandes"
        label="Demandes"
        data-testid="admin-tab-requests"
      />
      <q-route-tab
        to="/admin/joueurs"
        label="Joueurs"
        data-testid="admin-tab-players"
      />
      <q-route-tab
        to="/admin/positions"
        label="Positions"
        data-testid="admin-tab-positions"
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
