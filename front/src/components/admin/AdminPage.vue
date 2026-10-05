<template>
  <q-page class="admin" :data-testid="testid">
    <div class="admin__inner">
      <header class="admin__head">
        <h1 class="admin__title">Administration</h1>
        <slot name="actions" />
      </header>
      <AdminTabs />
      <slot />
    </div>
  </q-page>
</template>

<script setup>
import AdminTabs from '@/components/admin/AdminTabs.vue'

/** The frame of the administration's pages: title, optional actions, the tabs, then the page. */
defineProps({
  testid: { type: String, required: true }
})
</script>

<!-- Not scoped: the admin pages share these classes (layout, filters, tables, status labels). -->
<style lang="scss">
.admin {
  padding: 16px;
}

.admin__inner {
  max-width: 1100px;
  margin: 0 auto;
}

.admin__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.admin__title {
  margin: 0;
  font-size: 26px;
}

.admin__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}

.admin__filter {
  flex: 0 0 180px;
}

.admin__filter--grow {
  flex: 1 1 240px;
}

.admin__table {
  background: transparent;

  td,
  th {
    font-size: 13px;
  }

  code {
    font-size: 12px;
  }
}

.admin__pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 12px;
  font-size: 13px;
}

// A status is always written out: the tint only supports the word.
.admin__status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: var(--cm-subtle);
  color: var(--cm-ink-soft);
}

.admin__status--pending,
.admin__status--active {
  background: var(--cm-info-soft);
  color: var(--cm-info);
}

.admin__status--used {
  background: var(--cm-success-soft);
  color: var(--cm-success);
}

.admin__status--revoked,
.admin__status--danger {
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
}
</style>
