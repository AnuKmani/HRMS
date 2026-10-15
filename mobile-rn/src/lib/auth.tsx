import * as SecureStore from 'expo-secure-store';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Platform } from 'react-native';

import { api, setOnUnauthorised, setToken } from './api';
import { loadServerAddress } from './config';

export type EmployeeBrief = {
  id: number;
  employee_code: string;
  full_name: string;
  department?: string | null;
  designation?: string | null;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  employee: EmployeeBrief | null;
  must_change_password: boolean;
};

type AuthState = {
  ready: boolean;
  user: AuthUser | null;
  can: (permission: string) => boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  passwordChanged: () => void;
};

const TOKEN_KEY = 'hrms.token';
const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [user, setUser] = useState<AuthUser | null>(null);

  const clear = useCallback(async () => {
    setToken(null);
    setUser(null);
    try {
      await SecureStore.deleteItemAsync(TOKEN_KEY);
    } catch {
      // Nothing stored.
    }
  }, []);

  useEffect(() => {
    setOnUnauthorised(() => {
      void clear();
    });
    (async () => {
      await loadServerAddress();
      try {
        const saved = await SecureStore.getItemAsync(TOKEN_KEY);
        if (saved) {
          setToken(saved);
          setUser(await api.get<AuthUser>('/auth/me'));
        }
      } catch {
        await clear();
      } finally {
        setReady(true);
      }
    })();
    return () => setOnUnauthorised(null);
  }, [clear]);

  const login = useCallback(async (email: string, password: string) => {
    const data = await api.post<{ token: string; user: AuthUser }>('/auth/login', {
      email: email.trim(),
      password,
      device_name: `HRMS app (${Platform.OS})`,
    });
    setToken(data.token);
    try {
      await SecureStore.setItemAsync(TOKEN_KEY, data.token);
    } catch {
      // Signed in for this session only.
    }
    setUser(data.user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Signing out locally is what matters.
    }
    await clear();
  }, [clear]);

  const refresh = useCallback(async () => {
    setUser(await api.get<AuthUser>('/auth/me'));
  }, []);

  const value = useMemo<AuthState>(
    () => ({
      ready,
      user,
      can: (permission) =>
        !!user && (user.permissions.includes('*') || user.permissions.includes(permission)),
      login,
      logout,
      refresh,
      passwordChanged: () => setUser((u) => (u ? { ...u, must_change_password: false } : u)),
    }),
    [ready, user, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
