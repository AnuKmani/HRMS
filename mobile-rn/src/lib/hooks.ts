import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from './api';

/**
 * Load something when the screen comes into view, and again on pull-to-
 * refresh: `{ data, error, loading, reload }`.
 */
export function useLoad<T>(loader: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const loaderRef = useRef(loader);

  // Always call the latest loader (it closes over the screen's filters).
  useEffect(() => {
    loaderRef.current = loader;
  });

  const reload = useCallback(async () => {
    try {
      setData(await loaderRef.current());
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setLoading(false);
    }
  }, []);

  // Reload when the screen comes into view…
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );

  // …and when a filter changes (not on the first render: focus did that).
  const key = JSON.stringify(deps);
  const firstKey = useRef(key);
  useEffect(() => {
    if (firstKey.current === key) return;
    firstKey.current = key;
    void reload();
  }, [key, reload]);

  return { data, setData, error, setError, loading, reload };
}

/** Run an action with a busy flag and a readable error. */
export function useAction() {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const run = useCallback(async (key: string, action: () => Promise<unknown>, done?: string) => {
    setBusy(key);
    setError(null);
    setNotice(null);
    try {
      await action();
      if (done) setNotice(done);
      return true;
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Something went wrong. Please try again.');
      return false;
    } finally {
      setBusy(null);
    }
  }, []);

  return { busy, error, notice, setError, setNotice, run };
}
