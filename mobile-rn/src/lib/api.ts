import { File } from 'expo-file-system';

import { apiUrl } from './config';

/**
 * The Laravel API answers `{ success, message, data, errors }`. Every call
 * goes through here: base URL, bearer token, JSON, and one error type the
 * screens can show as-is.
 */
export class ApiError extends Error {
  status: number;
  errors: Record<string, string>;

  constructor(message: string, status = 0, errors: Record<string, string> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }

  /** The first field error, or the message. */
  get firstMessage(): string {
    return Object.values(this.errors)[0] ?? this.message;
  }
}

let token: string | null = null;
let onUnauthorised: (() => void) | null = null;

export function setToken(value: string | null) {
  token = value;
}

export function getToken(): string | null {
  return token;
}

export function setOnUnauthorised(handler: (() => void) | null) {
  onUnauthorised = handler;
}

type Query = Record<string, string | number | boolean | null | undefined>;

function withQuery(path: string, query?: Query): string {
  const parts = Object.entries(query ?? {})
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
  return `${apiUrl()}${path}${parts.length ? `?${parts.join('&')}` : ''}`;
}

function flattenErrors(raw: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (raw && typeof raw === 'object') {
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
      if (Array.isArray(value) && typeof value[0] === 'string') out[key] = value[0];
      else if (typeof value === 'string') out[key] = value;
    }
  }
  return out;
}

type FilePart = { uri: string; name?: string; type?: string };
const isFilePart = (v: unknown): v is FilePart => !!v && typeof v === 'object' && typeof (v as FilePart).uri === 'string';

/**
 * A multipart form, rewritten as JSON. Multipart uploads do not get through
 * the hosted server's front door, so files travel base64-encoded under
 * `_files` ("photos[]" becomes a list under "photos") and the server turns
 * them back into ordinary uploads before its usual checks.
 */
async function formToJson(form: FormData): Promise<Record<string, unknown>> {
  const parts: [string, unknown][] = (form as unknown as { _parts?: [string, unknown][] })._parts ?? [];
  const out: Record<string, unknown> = {};
  const files: Record<string, unknown> = {};
  for (const [rawKey, value] of parts) {
    const many = rawKey.endsWith('[]');
    const key = many ? rawKey.slice(0, -2) : rawKey;
    if (isFilePart(value)) {
      const encoded = { name: value.name ?? 'upload', type: value.type ?? 'application/octet-stream', data: await new File(value.uri).base64() };
      if (many) files[key] = [...((files[key] as unknown[]) ?? []), encoded];
      else files[key] = encoded;
    } else if (many) {
      out[key] = [...((out[key] as unknown[]) ?? []), value];
    } else {
      out[key] = value;
    }
  }
  if (Object.keys(files).length) out._files = files;
  return out;
}

async function send<T>(method: string, path: string, body?: unknown, query?: Query): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;

  let payload: BodyInit | undefined;
  const upload = body instanceof FormData;
  if (upload) {
    try {
      body = await formToJson(body as FormData);
    } catch {
      throw new ApiError('Could not read the file from the phone. Please take or choose it again.', 400);
    }
  }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  const controller = new AbortController();
  // Photo uploads on a weak signal take longer than a plain request.
  const timer = setTimeout(() => controller.abort(), upload ? 90000 : 30000);

  let response: Response;
  try {
    response = await fetch(withQuery(path, query), { method, headers, body: payload, signal: controller.signal });
  } catch {
    throw new ApiError('Could not reach the server. Check your connection and try again.');
  } finally {
    clearTimeout(timer);
  }

  let json: any = null;
  try {
    json = await response.json();
  } catch {
    // Not JSON — handled below.
  }

  if (!response.ok || (json && json.success === false)) {
    if (response.status === 401 && token) onUnauthorised?.();
    const message =
      (json && typeof json.message === 'string' && json.message) ||
      (response.status >= 500 ? 'Something went wrong on our end. Please try again.' : 'The request could not be completed.');
    throw new ApiError(message, response.status, flattenErrors(json?.errors));
  }

  if (json === null) throw new ApiError('The server sent an unexpected response.', response.status);
  return json.data as T;
}

export const api = {
  get: <T>(path: string, query?: Query) => send<T>('GET', path, undefined, query),
  post: <T>(path: string, body?: unknown) => send<T>('POST', path, body ?? {}),
  put: <T>(path: string, body?: unknown) => send<T>('PUT', path, body ?? {}),
  patch: <T>(path: string, body?: unknown) => send<T>('PATCH', path, body ?? {}),
  del: <T>(path: string) => send<T>('DELETE', path),
};

/** A file for a multipart upload, as React Native's FormData expects it. */
export function fileField(uri: string, name: string, type: string): any {
  return { uri, name, type };
}

/** Paginated list payload: `{ items, meta }`. */
export type Page<T> = { items: T[]; meta?: { total?: number; current_page?: number; last_page?: number } };
