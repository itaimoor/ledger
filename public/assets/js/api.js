/**
 * The only place this application talks to the server.
 *
 * Three things are handled here so no caller has to think about them: the access token is
 * attached, a 401 triggers exactly one refresh-and-retry, and every failure arrives as an
 * ApiError carrying the status, code and per-field messages the server sent.
 */

import { session } from './session.js';

const BASE = '/api/v1';

export class ApiError extends Error {
  constructor(status, code, message, fields = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
  }

  /** True when the server rejected the input rather than the caller. */
  get isValidation() {
    return this.status === 422;
  }
}

/**
 * Concurrent 401s must not each start their own refresh: the first rotation would
 * invalidate the token the others are about to send, the server would read that as reuse,
 * and the whole family would be revoked mid-session. They all await one promise instead.
 */
let refreshInFlight = null;

function refreshOnce() {
  refreshInFlight ??= send('/auth/refresh', {
    method: 'POST',
    body: { refresh_token: session.refreshToken },
    skipAuth: true,
    skipRetry: true,
  })
    .then((result) => {
      session.setTokens(result.data);
      return true;
    })
    .catch(() => {
      session.clear();
      return false;
    })
    .finally(() => {
      refreshInFlight = null;
    });

  return refreshInFlight;
}

async function send(path, { method = 'GET', body, query, skipAuth = false, skipRetry = false } = {}) {
  const url = new URL(BASE + path, window.location.origin);

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== null && value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  }

  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (!skipAuth && session.accessToken) headers.Authorization = `Bearer ${session.accessToken}`;

  const response = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (response.status === 401 && !skipRetry && session.refreshToken) {
    if (await refreshOnce()) {
      return send(path, { method, body, query, skipAuth, skipRetry: true });
    }
  }

  if (response.status === 204) return { data: null, meta: {} };

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError(response.status, 'unreadable', 'The server sent a response we could not read.');
  }

  if (!response.ok) {
    const error = payload?.error ?? {};
    throw new ApiError(
      response.status,
      error.code ?? 'error',
      error.message ?? 'Something went wrong.',
      error.fields ?? {},
    );
  }

  return payload;
}

export const api = {
  get: (path, query) => send(path, { query }),
  post: (path, body) => send(path, { method: 'POST', body }),
  patch: (path, body) => send(path, { method: 'PATCH', body }),
  delete: (path) => send(path, { method: 'DELETE' }),

  /** Sign-in and sign-up must not carry a stale Authorization header. */
  publicPost: (path, body) => send(path, { method: 'POST', body, skipAuth: true, skipRetry: true }),
  publicGet: (path) => send(path, { skipAuth: true, skipRetry: true }),
};
