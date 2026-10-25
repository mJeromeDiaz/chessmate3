<template>
  <AdminPage testid="admin-announcements">
    <template #actions>
      <q-btn
        unelevated
        no-caps
        color="primary"
        icon="add"
        :label="$t('admin.announcements.new')"
        data-testid="announcement-new"
        @click="edit(null)"
      />
    </template>

    <section class="cm-card">
      <p class="cm-muted announcements__lead">{{
        $t('admin.announcements.lead')
      }}</p>

      <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
        error
      }}</q-banner>

      <q-markup-table
        flat
        dense
        class="admin__table"
        data-testid="announcement-table"
      >
        <thead>
          <tr>
            <th class="text-left">{{
              $t('admin.announcements.columns.title')
            }}</th>
            <th class="text-left">{{
              $t('admin.announcements.columns.state')
            }}</th>
            <th class="text-left">{{
              $t('admin.announcements.columns.window')
            }}</th>
            <th class="text-right">{{
              $t('admin.announcements.columns.reads')
            }}</th>
            <th class="text-left">{{
              $t('admin.announcements.columns.email')
            }}</th>
            <th class="text-right">{{
              $t('admin.announcements.columns.actions')
            }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && announcements.length === 0">
            <td colspan="6" class="cm-muted">{{
              $t('admin.announcements.none')
            }}</td>
          </tr>
          <tr
            v-for="a in announcements"
            :key="a.id"
            data-testid="announcement-row"
          >
            <td class="announcements__title">
              <strong>{{ a.titleFr }}</strong>
              <span v-if="a.titleEn" class="announcements__en">EN</span>
              <div class="cm-muted">{{
                $t('admin.announcements.by', {
                  when: when(a.createdAt),
                  author: a.author ?? '—'
                })
              }}</div>
            </td>
            <td>
              <span
                :class="['admin__status', STATE_CLASSES[a.state]]"
                data-testid="announcement-state"
                >{{ stateLabel(a.state) }}</span
              >
            </td>
            <td class="no-wrap">
              <div>{{
                $t('admin.announcements.from', {
                  when: a.startsAt
                    ? when(a.startsAt)
                    : $t('admin.announcements.publication')
                })
              }}</div>
              <div v-if="a.endsAt">{{
                $t('admin.announcements.until', { when: when(a.endsAt) })
              }}</div>
            </td>
            <td class="text-right" data-testid="announcement-reads">{{
              a.reads
            }}</td>
            <td data-testid="announcement-email">
              {{
                $t(`admin.announcements.email.${emailState(a)}`, {
                  sent: a.email.sent,
                  queued: a.email.queued
                })
              }}
            </td>
            <td class="text-right no-wrap">
              <q-btn
                v-if="a.status !== 'archived'"
                flat
                dense
                no-caps
                :label="$t('admin.announcements.edit')"
                data-testid="announcement-edit"
                @click="edit(a)"
              />
              <q-btn
                v-if="canEmail(a)"
                flat
                dense
                no-caps
                color="primary"
                :label="$t('admin.announcements.sendEmail')"
                :disable="busy === a.id"
                data-testid="announcement-send-email"
                @click="askEmail(a)"
              />
              <q-btn
                v-if="a.status !== 'archived'"
                flat
                dense
                no-caps
                color="negative"
                :label="$t('admin.announcements.archive')"
                :disable="busy === a.id"
                data-testid="announcement-archive"
                @click="archive(a)"
              />
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </section>

    <AnnouncementForm
      :open="formOpen"
      :announcement="editing"
      @close="formOpen = false"
      @saved="onSaved"
    />
  </AdminPage>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import { adminApi } from '@/services/api'
import AdminPage from '@/components/admin/AdminPage.vue'
import AnnouncementForm from '@/components/admin/announcement/AnnouncementForm.vue'
import { apiErrorMessage } from '@/utils/apiError'
import { canEmail, emailState, stateLabel } from '@/utils/admin/announcement'
import { violationsOf } from '@/utils/admin/shop'
import { intlLocale, t } from '@/i18n'

/**
 * The team's announcements (docs/ANNOUNCEMENTS.md, § 2): written here, shown once to every player
 * in a modal, emailed on demand to the active accounts that accept it. An archived one is no
 * longer shown nor changed.
 */
definePage({
  meta: {
    auth: 'admin',
    header: {
      title: 'layout.header.titles.admin.announcements',
      subtitle: 'layout.header.kickers.admin'
    }
  }
})

/** The status tints: a state is always written out. */
const STATE_CLASSES = {
  draft: '',
  scheduled: 'admin__status--pending',
  live: 'admin__status--used',
  ended: '',
  archived: 'admin__status--revoked'
}

const $q = useQuasar()
/** @type {import('vue').Ref<import('@/utils/admin/announcement').AdminAnnouncement[]>} */
const announcements = ref([])
const loading = ref(false)
const error = ref('')
/** @type {import('vue').Ref<string|null>} the row being changed */
const busy = ref(null)
const formOpen = ref(false)
/** @type {import('vue').Ref<import('@/utils/admin/announcement').AdminAnnouncement|null>} */
const editing = ref(null)

/** @param {string} iso */
function when(iso) {
  return new Intl.DateTimeFormat(intlLocale(), {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(new Date(iso))
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    announcements.value = (await adminApi.announcements()).announcements
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

/** @param {import('@/utils/admin/announcement').AdminAnnouncement|null} announcement */
function edit(announcement) {
  editing.value = announcement
  formOpen.value = true
}

function onSaved() {
  formOpen.value = false
  load()
}

/**
 * A row's change, the list read again after it; a refusal in a notification.
 *
 * @param {string} id
 * @param {() => Promise<unknown>} action
 */
async function change(id, action) {
  busy.value = id
  try {
    await action()
    await load()
  } catch (e) {
    const violations = violationsOf(e)
    $q.notify({
      type: 'negative',
      message: violations.length
        ? violations.map(v => v.title).join(' ')
        : apiErrorMessage(e)
    })
  } finally {
    busy.value = null
  }
}

/** @param {import('@/utils/admin/announcement').AdminAnnouncement} a */
function archive(a) {
  $q.dialog({
    title: t('admin.announcements.archiveTitle'),
    message: t('admin.announcements.archiveMessage'),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      noCaps: true,
      label: t('admin.announcements.archive'),
      color: 'negative'
    }
  }).onOk(() => change(a.id, () => adminApi.archiveAnnouncement(a.id)))
}

/** @param {import('@/utils/admin/announcement').AdminAnnouncement} a */
async function askEmail(a) {
  let count
  try {
    count = (await adminApi.announcementRecipients()).count
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
    return
  }
  $q.dialog({
    title: t('admin.announcements.emailTitle'),
    message: t(
      a.state === 'scheduled'
        ? 'admin.announcements.emailConfirmScheduled'
        : 'admin.announcements.emailConfirm',
      { accounts: count, title: a.titleFr },
      count
    ),
    cancel: { flat: true, noCaps: true, label: t('common.actions.cancel') },
    ok: {
      unelevated: true,
      noCaps: true,
      label: t('admin.announcements.emailOk'),
      color: 'primary'
    },
    persistent: true
  }).onOk(() => change(a.id, () => adminApi.emailAnnouncement(a.id)))
}

onMounted(load)
</script>

<style scoped lang="scss">
.announcements__lead {
  margin: 0 0 12px;
  font-size: 13px;
}

.announcements__title {
  max-width: 320px;
  white-space: normal;
}

.announcements__en {
  margin-left: 6px;
  padding: 1px 6px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  background: var(--cm-subtle);
  color: var(--cm-ink-soft);
}
</style>
