/**
 * Single API boundary shared by the GitHub Pages preview and GoDaddy frontend.
 * The public preview intentionally has no backend configured.
 */
export function normalizeApiBaseUrl(rawUrl, pageUrl) {
  if (!rawUrl) return null;

  const target = new URL(rawUrl, pageUrl);
  const isLocal = target.hostname === 'localhost' || target.hostname === '127.0.0.1';
  if (target.protocol !== 'https:' && !(isLocal && target.protocol === 'http:')) {
    throw new Error('API endpoints must use HTTPS outside localhost.');
  }
  if (target.username || target.password || target.search || target.hash) {
    throw new Error('API endpoints must not include credentials, queries or fragments.');
  }

  return target.href.endsWith('/') ? target.href : target.href + '/';
}

export function createApiClient(apiBaseUrl, pageUrl, fetchImpl = globalThis.fetch) {
  const baseUrl = normalizeApiBaseUrl(apiBaseUrl, pageUrl);
  if (!baseUrl) return null;

  return {
    async health({ signal } = {}) {
      const response = await fetchImpl(new URL('health', baseUrl), {
        method: 'GET',
        mode: 'cors',
        cache: 'no-store',
        credentials: 'omit',
        signal,
      });
      if (!response.ok) throw new Error('API request failed.');
      return response.json();
    },
  };
}
