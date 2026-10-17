export class ApiError extends Error {
  constructor(message, status = 0, errors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

function csrfToken() {
  const cookie = document.cookie
    .split('; ')
    .find((part) => part.startsWith('XSRF-TOKEN='));

  return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

export async function apiRequest(path, options = {}) {
  const method = options.method ?? 'GET';
  const headers = {
    Accept: 'application/json',
    ...options.headers,
  };

  if (options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) {
    try {
      await fetch('/api/csrf-cookie', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
    } catch {
      throw new ApiError('The service is unavailable. Check that the backend is running.');
    }

    const token = csrfToken();
    if (!token) {
      throw new ApiError('Could not establish a secure session. Please refresh and try again.');
    }
    headers['X-XSRF-TOKEN'] = token;
  }

  let response;
  try {
    response = await fetch(path, {
      ...options,
      method,
      headers,
      credentials: 'same-origin',
    });
  } catch {
    throw new ApiError('The service is unavailable. Check that the backend is running.');
  }

  const data = response.status === 204 ? {} : await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new ApiError(
      data.message ?? 'The request could not be completed.',
      response.status,
      data.errors ?? {},
    );
  }

  return data;
}
