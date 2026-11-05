import { normalizeApiBaseUrl } from '../assets/js/api.js';

/**
 * Participant requests are authorized by a high-entropy invitation bearer
 * token. The token is NEVER put in the URL, body, browser cookies or logs.
 */
export function createParticipantApi(apiBaseUrl, pageUrl, fetchImpl = globalThis.fetch) {
  const base = normalizeApiBaseUrl(apiBaseUrl, pageUrl);
  if (!base || new URL(base).origin !== new URL(pageUrl).origin) {
    throw new Error('The booking API must be hosted on the same origin as the invitation page.');
  }

  async function request(method, path, token, body) {
    if (!/^[A-Za-z0-9_-]{43}$/.test(token)) throw new Error('Invalid invitation link.');
    const response = await fetchImpl(new URL(path, base), {
      method,
      mode: 'same-origin',
      credentials: 'omit',
      cache: 'no-store',
      redirect: 'error',
      headers: {
        Accept: 'application/json',
        Authorization: 'Bearer ' + token,
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    let result;
    try { result = await response.json(); } catch { result = null; }
    if (!response.ok) {
      const error = new Error(result?.message || 'The booking request could not be completed.');
      error.reason = result?.error || 'REQUEST_FAILED';
      error.status = response.status;
      throw error;
    }
    return result?.data;
  }

  return {
    invitation: token => request('GET', 'participant/invitation', token),
    book: (token, id) => request('POST', 'participant/book', token, { session_id: id }),
    cancel: token => request('POST', 'participant/cancel', token, {}),
    reschedule: (token, id) => request('POST', 'participant/reschedule', token, { session_id: id }),
  };
}
