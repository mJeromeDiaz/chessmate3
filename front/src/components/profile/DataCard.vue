<template>
  <section class="cm-card profile-card" data-testid="profile-data">
    <h2 class="cm-card__title">Données</h2>
    <div class="profile-row profile-row--flat">
      <div class="profile-row__text">
        <div class="profile-row__title">
          Exporter mes données <span class="profile-soon">Bientôt</span>
        </div>
        <div class="profile-row__sub"
          >Sessions, stats et répertoire · JSON + PGN</div
        >
      </div>
      <q-btn unelevated no-caps class="profile-btn" label="Exporter" disable />
    </div>
    <div class="profile-row">
      <div class="profile-row__text">
        <div class="profile-row__title profile-row__title--danger">
          Supprimer mon compte <span class="profile-soon">Bientôt</span>
        </div>
        <div class="profile-row__sub">Définitif après 30 jours</div>
      </div>
      <q-btn
        unelevated
        no-caps
        class="profile-btn profile-btn--danger"
        label="Supprimer…"
        disable
      />
    </div>
  </section>
</template>

<script setup>
/** Profile: data export and account deletion, not built yet ("Bientôt"). */
</script>
