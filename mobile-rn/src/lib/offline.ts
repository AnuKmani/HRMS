import { Directory, File, Paths } from 'expo-file-system';

import { api, ApiError, fileField } from './api';
import { shrinkPhoto } from './photos';

/**
 * Check-ins and check-outs made with no signal are kept on the phone and
 * sent later. Each keeps its own client_event_id, so the server records it
 * once however many times it is retried, and `source: offline` tells HR it
 * was recorded on the phone first.
 */
export type QueuedEvent = {
  id: string;
  kind: 'check-in' | 'check-out';
  at: string;
  fields: Record<string, string>;
  selfie?: string;
  error?: string;
};

const dir = () => {
  const d = new Directory(Paths.document, 'offline');
  if (!d.exists) d.create({ idempotent: true, intermediates: true });
  return d;
};
const queueFile = () => new File(dir(), 'attendance-queue.json');

export function pending(): QueuedEvent[] {
  try {
    const f = queueFile();
    return f.exists ? (JSON.parse(f.textSync()) as QueuedEvent[]) : [];
  } catch {
    return [];
  }
}

function save(events: QueuedEvent[]) {
  queueFile().write(JSON.stringify(events));
}

/** Keep an event for later; the selfie is copied out of the camera cache. */
export function enqueue(event: Omit<QueuedEvent, 'selfie'> & { selfieUri?: string }) {
  let selfie: string | undefined;
  if (event.selfieUri) {
    const target = new File(dir(), `${event.id}.jpg`);
    new File(event.selfieUri).copy(target);
    selfie = target.uri;
  }
  const { selfieUri: _unused, ...rest } = event;
  save([...pending(), { ...rest, selfie }]);
}

/** A failure that means "no connection", not "the server said no". */
export const isOffline = (e: unknown) => e instanceof ApiError && e.status === 0;

/**
 * Send everything waiting, oldest first. Stops at the first connection
 * failure; an event the server refuses is kept with its reason so the
 * person can see it and dismiss it.
 */
export async function syncPending(): Promise<{ sent: number; left: number }> {
  let sent = 0;
  const events = pending();
  const remaining: QueuedEvent[] = [];
  let offline = false;

  for (const e of events) {
    if (offline || e.error) {
      remaining.push(e);
      continue;
    }
    try {
      if (e.kind === 'check-in') {
        const form = new FormData();
        for (const [k, v] of Object.entries(e.fields)) form.append(k, v);
        // Shrunk here too: check-ins saved by older builds kept the full-size photo.
        if (e.selfie) form.append('selfie', fileField(await shrinkPhoto(e.selfie), 'selfie.jpg', 'image/jpeg'));
        await api.post('/attendance/check-in', form);
      } else {
        await api.post('/attendance/check-out', e.fields);
      }
      if (e.selfie) {
        try {
          new File(e.selfie).delete();
        } catch {
          // Already gone.
        }
      }
      sent++;
    } catch (err) {
      if (isOffline(err)) {
        offline = true;
        remaining.push(e);
      } else {
        remaining.push({ ...e, error: err instanceof ApiError ? err.firstMessage : 'The server refused this record.' });
      }
    }
  }
  save(remaining);
  return { sent, left: remaining.length };
}

export function dismiss(id: string) {
  const events = pending();
  const gone = events.find((e) => e.id === id);
  if (gone?.selfie) {
    try {
      new File(gone.selfie).delete();
    } catch {
      // Already gone.
    }
  }
  save(events.filter((e) => e.id !== id));
}
