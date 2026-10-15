import { useState } from 'react';

import { Banner, Button, Field, Muted, Screen } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';

/** First sign-in (or after HR reset it): pick your own password. */
export default function ChangePasswordScreen() {
  const { passwordChanged, logout } = useAuth();
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  async function save() {
    setError(null);
    setErrors({});
    setBusy(true);
    try {
      await api.post('/auth/change-password', {
        current_password: current,
        password,
        password_confirmation: confirm,
      });
      passwordChanged();
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Muted>Your password was set by HR. Choose your own before you continue.</Muted>
      <Screen.Spacer />
      {error ? <Banner message={error} /> : null}
      <Field label="Temporary password" value={current} onChangeText={setCurrent} secureTextEntry error={errors.current_password} required />
      <Field label="New password" value={password} onChangeText={setPassword} secureTextEntry error={errors.password} required />
      <Field label="Confirm new password" value={confirm} onChangeText={setConfirm} secureTextEntry error={errors.password_confirmation} required />
      <Button label="Save password" onPress={save} busy={busy} />
      <Button label="Sign out" kind="outline" onPress={logout} />
    </Screen>
  );
}
