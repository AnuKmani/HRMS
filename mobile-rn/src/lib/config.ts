import * as SecureStore from 'expo-secure-store';

/** From the env file at build time (see .env.example). */
export const DEFAULT_API_URL = (process.env.EXPO_PUBLIC_API_URL ?? '').replace(/\/+$/, '');

/** Test builds let a tester point the app at another server from the login screen. */
export const ALLOW_SERVER_SETTING = process.env.EXPO_PUBLIC_ALLOW_SERVER_SETTING === 'true';

const KEY = 'hrms.server_url';
let current = DEFAULT_API_URL;

export function apiUrl(): string {
  return current;
}

/** `192.168.1.164:8000` → `http://192.168.1.164:8000/api/v1`. */
export function normaliseServerAddress(input: string): string {
  let url = input.trim().replace(/\/+$/, '');
  if (url === '') return DEFAULT_API_URL;
  if (!/^https?:\/\//i.test(url)) url = `http://${url}`;
  if (!/\/api\/v\d+$/.test(url)) url = `${url}/api/v1`;
  return url;
}

/** `http://192.168.1.164:8000/api/v1` → `192.168.1.164:8000`. */
export function serverLabel(url: string): string {
  return url.replace(/^https?:\/\//, '').replace(/\/api\/v\d+$/, '');
}

export async function loadServerAddress(): Promise<string> {
  if (!ALLOW_SERVER_SETTING) return current;
  try {
    const saved = await SecureStore.getItemAsync(KEY);
    if (saved) current = saved;
  } catch {
    // Keep the built-in address.
  }
  return current;
}

export async function saveServerAddress(input: string): Promise<string> {
  current = normaliseServerAddress(input);
  try {
    await SecureStore.setItemAsync(KEY, current);
  } catch {
    // Still used for this session.
  }
  return current;
}
