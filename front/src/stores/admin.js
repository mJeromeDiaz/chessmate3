import { defineStore } from 'pinia'
import { ref } from 'vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { DEFAULT_PERIOD, validPeriod } from '@/utils/dashboard/stats'

/**
 * The administration (docs/EARLY_ACCESS.md): the dashboard's statistics, the invitations, the
 * waiting list and the players, each list with its own page and filters. Admins only: the API answers 403 to others.
 *
 * @typedef {import('@/services/api').InvitationStatus} InvitationStatus
 *
 * @typedef {object} Account
 * @property {string} id
 * @property {string|null} email
 * @property {string|null} handle
 *
 * @typedef {object} Invitation
 * @property {string} id
 * @property {string} email
 * @property {InvitationStatus} status
 * @property {string|null} key only right after a creation or a resend
 * @property {string} keyHint
 * @property {string} createdAt
 * @property {Account|null} createdBy
 * @property {string|null} expiresAt null: never expires
 * @property {string|null} usedAt
 * @property {Account|null} usedBy
 * @property {string|null} revokedAt
 * @property {'pending'|'sent'|'failed'} emailStatus
 * @property {string|null} emailSentAt
 * @property {number} sendCount
 * @property {{id: string, action: string, actor: Account|null, details: Record<string, unknown>, createdAt: string}[]|null} logs detail only
 *
 * @typedef {object} Player
 * @property {string} id
 * @property {string|null} email
 * @property {string|null} displayName
 * @property {string|null} handle
 * @property {string} createdAt
 * @property {boolean} emailVerified
 * @property {boolean} isAdmin
 * @property {'password'|'google'|'lichess'|'other'} signupMethod
 * @property {string|null} keyHint
 * @property {string|null} lastActivityAt
 * @property {boolean} active an exercise in the last 7 days
 * @property {number} trainingMs30d
 * @property {{username: string|null, perf: string|null, rating: number|null}|null} lichess
 * @property {string|null} deletionScheduledAt
 * @property {string|null} suspendedAt
 * @property {string|null} suspensionReason the admin's internal note
 *
 * @typedef {object} AccessRequest
 * @property {string} id
 * @property {string} email
 * @property {string} createdAt
 * @property {string|null} invitedAt
 * @property {boolean} hasAccount the address already belongs to an account
 * @property {{id: string, status: InvitationStatus, keyHint: string}|null} invitation
 * @property {string|null} key only in the answer to an invite
 */

/** Rows per page of every list. */
export const PAGE_SIZE = 20

export const useAdminStore = defineStore('admin', () => {
  // Statistics.
  const period = ref(DEFAULT_PERIOD)
  /** @type {import('vue').Ref<object|null>} the GET /api/admin/stats answer */
  const stats = ref(null)
  const statsLoading = ref(false)
  const statsError = ref('')
  let statsSeq = 0

  /**
   * Loads the statistics of a period (the last answer asked for wins).
   *
   * @param {number} [days]
   */
  async function loadStats(days = period.value) {
    period.value = validPeriod(days)
    const seq = ++statsSeq
    statsLoading.value = true
    statsError.value = ''
    try {
      const data = await adminApi.stats(period.value)
      if (seq === statsSeq) stats.value = data
    } catch (e) {
      if (seq === statsSeq) statsError.value = apiErrorMessage(e)
    } finally {
      if (seq === statsSeq) statsLoading.value = false
    }
  }

  // Invitations.
  /** @type {import('vue').Ref<Invitation[]>} */
  const invitations = ref([])
  const invitationTotal = ref(0)
  const invitationPage = ref(1)
  /** @type {import('vue').Ref<InvitationStatus|null>} */
  const invitationStatus = ref(null)
  const invitationEmail = ref('')
  const invitationsLoading = ref(false)
  const invitationsError = ref('')
  let invitationsSeq = 0

  /** Loads the current page of invitations, with the current filters. */
  async function loadInvitations() {
    const seq = ++invitationsSeq
    invitationsLoading.value = true
    invitationsError.value = ''
    try {
      const { member, totalItems } = await adminApi.invitations({
        page: invitationPage.value,
        itemsPerPage: PAGE_SIZE,
        status: invitationStatus.value,
        email: (invitationEmail.value ?? '').trim()
      })
      if (seq !== invitationsSeq) return
      invitations.value = member
      invitationTotal.value = totalItems
    } catch (e) {
      if (seq === invitationsSeq) invitationsError.value = apiErrorMessage(e)
    } finally {
      if (seq === invitationsSeq) invitationsLoading.value = false
    }
  }

  /**
   * Creates an invitation, then reloads the first page. Rejects with the API's error.
   *
   * @param {{email: string, expiresAt?: string|null, neverExpires?: boolean}} body
   * @returns {Promise<Invitation>} with its `key`, shown once
   */
  async function createInvitation(body) {
    const created = await adminApi.createInvitation(body)
    invitationPage.value = 1
    await loadInvitations()
    return created
  }

  /**
   * A new key on the invitation; the row is updated in place. Rejects with the API's error.
   *
   * @param {string} id
   * @returns {Promise<Invitation>} with its new `key`, shown once
   */
  async function resendInvitation(id) {
    const renewed = await adminApi.resendInvitation(id)
    replaceInvitation({ ...renewed, key: null })
    return renewed
  }

  /**
   * Revokes the invitation, then rereads it. Rejects with the API's error.
   *
   * @param {string} id
   */
  async function revokeInvitation(id) {
    await adminApi.revokeInvitation(id)
    replaceInvitation(await adminApi.invitation(id))
  }

  /** @param {Invitation} invitation */
  function replaceInvitation(invitation) {
    invitations.value = invitations.value.map(i =>
      i.id === invitation.id ? { ...invitation, logs: null } : i
    )
  }

  // Waiting list.
  /** @type {import('vue').Ref<AccessRequest[]>} */
  const requests = ref([])
  const requestTotal = ref(0)
  const requestPage = ref(1)
  /** @type {import('vue').Ref<boolean|null>} false: still waiting (default); null: all */
  const requestInvited = ref(/** @type {boolean|null} */ (false))
  const requestsLoading = ref(false)
  const requestsError = ref('')
  let requestsSeq = 0

  /** Loads the current page of the waiting list, with the current filter. */
  async function loadRequests() {
    const seq = ++requestsSeq
    requestsLoading.value = true
    requestsError.value = ''
    try {
      const { member, totalItems } = await adminApi.accessRequests({
        page: requestPage.value,
        itemsPerPage: PAGE_SIZE,
        invited: requestInvited.value
      })
      if (seq !== requestsSeq) return
      requests.value = member
      requestTotal.value = totalItems
    } catch (e) {
      if (seq === requestsSeq) requestsError.value = apiErrorMessage(e)
    } finally {
      if (seq === requestsSeq) requestsLoading.value = false
    }
  }

  /**
   * Invites a request's address; the row is updated in place. Rejects with the API's error.
   *
   * @param {string} id
   * @returns {Promise<AccessRequest>} with the invitation's `key`, shown once
   */
  async function inviteRequest(id) {
    const invited = await adminApi.inviteAccessRequest(id)
    requests.value = requests.value.map(r =>
      r.id === id ? { ...invited, key: null } : r
    )
    return invited
  }

  /**
   * Deletes a request, then reloads the page. Rejects with the API's error.
   *
   * @param {string} id
   */
  async function deleteRequest(id) {
    await adminApi.deleteAccessRequest(id)
    await loadRequests()
  }

  // Players.
  /** @type {import('vue').Ref<Player[]>} */
  const players = ref([])
  const playerTotal = ref(0)
  const playerPage = ref(1)
  const playerSearch = ref('')
  /** @type {import('vue').Ref<boolean|null>} null: every account */
  const playerSuspended = ref(null)
  const playersLoading = ref(false)
  const playersError = ref('')
  let playersSeq = 0

  /** Loads the current page of players, with the current search. */
  async function loadPlayers() {
    const seq = ++playersSeq
    playersLoading.value = true
    playersError.value = ''
    try {
      const { member, totalItems } = await adminApi.players({
        page: playerPage.value,
        itemsPerPage: PAGE_SIZE,
        search: (playerSearch.value ?? '').trim(),
        suspended: playerSuspended.value
      })
      if (seq !== playersSeq) return
      players.value = member
      playerTotal.value = totalItems
    } catch (e) {
      if (seq === playersSeq) playersError.value = apiErrorMessage(e)
    } finally {
      if (seq === playersSeq) playersLoading.value = false
    }
  }

  /**
   * Suspends a player; the row is updated in place. Rejects with the API's error.
   *
   * @param {string} id
   * @param {string|null} reason
   */
  async function suspendPlayer(id, reason) {
    replacePlayer(await adminApi.suspendPlayer(id, reason))
  }

  /**
   * Lifts a player's suspension; the row is updated in place. Rejects with the API's error.
   *
   * @param {string} id
   */
  async function unsuspendPlayer(id) {
    replacePlayer(await adminApi.unsuspendPlayer(id))
  }

  /** @param {Player} player */
  function replacePlayer(player) {
    players.value = players.value.map(p => (p.id === player.id ? player : p))
  }

  return {
    period,
    stats,
    statsLoading,
    statsError,
    loadStats,
    invitations,
    invitationTotal,
    invitationPage,
    invitationStatus,
    invitationEmail,
    invitationsLoading,
    invitationsError,
    loadInvitations,
    createInvitation,
    resendInvitation,
    revokeInvitation,
    requests,
    requestTotal,
    requestPage,
    requestInvited,
    requestsLoading,
    requestsError,
    loadRequests,
    inviteRequest,
    deleteRequest,
    players,
    playerTotal,
    playerPage,
    playerSearch,
    playerSuspended,
    suspendPlayer,
    unsuspendPlayer,
    playersLoading,
    playersError,
    loadPlayers
  }
})
