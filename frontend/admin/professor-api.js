import { normalizeApiBaseUrl } from '../assets/js/api.js';

/**
 * Same-origin cookie/session API. GitHub Pages never instantiates this client.
 * CSRF tokens stay only in memory; privileged API requests never use CORS.
 */
export function createProfessorApi(apiBaseUrl, pageUrl, fetchImpl = globalThis.fetch) {
  const base = normalizeApiBaseUrl(apiBaseUrl, pageUrl);
  if (!base || new URL(base).origin !== new URL(pageUrl).origin) {
    throw new Error('The professor API must be on the same origin as the admin page.');
  }
  let csrf = null;

  async function request(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') {
      if (!csrf) throw new Error('Professor session has not been initialized.');
      headers[csrf.header] = csrf.token;
    }
    const response = await fetchImpl(new URL(path, base), {
      method,
      headers,
      credentials: 'same-origin',
      mode: 'same-origin',
      cache: 'no-store',
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    let result;
    try { result = await response.json(); } catch { result = null; }
    if (!response.ok) {
      const error = new Error(result?.message || result?.error || 'Request failed.');
      error.status = response.status;
      error.reason = result?.error || 'REQUEST_FAILED';
      throw error;
    }
    return result?.data;
  }

  return {
    loginUrl: new URL('login', base).href,
    async me() {
      const response = await fetchImpl(new URL('admin/me', base), {
        method: 'GET', credentials: 'same-origin', mode: 'same-origin',
        cache: 'no-store', headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        const error = new Error('Professor sign-in is required.');
        error.status = response.status;
        throw error;
      }
      const result = await response.json();
      csrf = result.csrf;
      if (!csrf?.token || !csrf?.header) throw new Error('Session is missing CSRF protection.');
      return result.data;
    },
    dashboard: () => request('GET', 'admin/dashboard'),
    studies: () => request('GET', 'admin/studies'),
    createStudy: payload => request('POST', 'admin/studies', payload),
    updateStudy: (id, payload) => request('PUT', `admin/studies/${id}`, payload),
    studyStatus: (id, status) => request('POST', `admin/studies/${id}/status`, { status }),
    sessions: () => request('GET', 'admin/sessions'),
    createSessions: (studyId, sessions) => request('POST', `admin/studies/${studyId}/sessions`, { sessions }),
    sessionCapacity: (id, capacity) => request('POST', `admin/sessions/${id}/capacity`, { capacity }),
    sessionStatus: (id, status) => request('POST', `admin/sessions/${id}/status`, { status }),
    invitations: () => request('GET', 'admin/invitations'),
    issueInvitation: payload => request('POST', 'admin/invitations', payload),
    revokeInvitation: id => request('POST', `admin/invitations/${id}/revoke`, {}),
    bookings: () => request('GET', 'admin/bookings'),
    cancelBooking: id => request('POST', `admin/bookings/${id}/cancel`, {}),
    rescheduleBooking: (id, sessionId) => request('POST', `admin/bookings/${id}/reschedule`, { session_id: sessionId }),
    async exportBookings() {
      if (!csrf) throw new Error('Professor session has not been initialized.');
      const response = await fetchImpl(new URL('admin/bookings.csv', base), {
        method: 'GET', credentials: 'same-origin', mode: 'same-origin', cache: 'no-store',
      });
      if (!response.ok) throw new Error('Export failed.');
      return response.blob();
    },
    logout: () => request('POST', 'admin/logout', {}),
  };
}
