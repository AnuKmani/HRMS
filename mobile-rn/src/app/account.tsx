import { useState } from 'react';

import { KeyValues, SectionHeader, confirm } from '../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, Row, Screen } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { prettyDate, timeOf } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';

type Session = { id: number; device: string; is_current: boolean; last_used_at: string | null; created_at: string | null };

const when = (v: string | null) => (v ? `${prettyDate(v)} ${timeOf(v)}` : 'never');

/** Who you are signed in as, your password, and the devices signed in to your account. */
export default function AccountScreen() {
  const { user, logout } = useAuth();
  const sessions = useLoad(() => api.get<Session[]>('/auth/sessions'));
  const { busy, error, notice, run } = useAction();
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [pwErrors, setPwErrors] = useState<Record<string, string>>({});

  async function changePassword() {
    setPwErrors({});
    const ok = await run(
      'password',
      async () => {
        try {
          await api.post('/auth/change-password', pw);
        } catch (e) {
          if (e instanceof ApiError) setPwErrors(e.errors);
          throw e;
        }
      },
      'Password changed. Your other devices have been signed out.',
    );
    if (ok) {
      setPw({ current_password: '', password: '', password_confirmation: '' });
      await sessions.reload();
    }
  }

  if (!user) return <Loading />;

  return (
    <Screen onRefresh={sessions.reload}>
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <KeyValues
          rows={[
            ['Name', user.name],
            ['Email', user.email],
            ['Employee code', user.employee?.employee_code],
            ['Designation', user.employee?.designation],
            ['Role', user.roles.join(', ')],
          ]}
        />
      </Card>

      <Card>
        <SectionHeader title="Change password" />
        <Field label="Current password" secureTextEntry value={pw.current_password} onChangeText={(v) => setPw((p) => ({ ...p, current_password: v }))} error={pwErrors.current_password} />
        <Field label="New password" secureTextEntry value={pw.password} onChangeText={(v) => setPw((p) => ({ ...p, password: v }))} error={pwErrors.password} />
        <Field label="Confirm new password" secureTextEntry value={pw.password_confirmation} onChangeText={(v) => setPw((p) => ({ ...p, password_confirmation: v }))} error={pwErrors.password_confirmation} />
        <Button label="Change password" onPress={changePassword} busy={busy === 'password'} />
      </Card>

      <Card>
        <SectionHeader title="Signed-in devices" />
        {sessions.error ? <Banner message={sessions.error} /> : null}
        {sessions.loading ? (
          <Loading />
        ) : (
          (sessions.data ?? []).map((s) => (
            <Row
              key={s.id}
              title={s.device || 'Unknown device'}
              subtitle={`Signed in ${when(s.created_at)} · last used ${when(s.last_used_at)}`}
              right={s.is_current ? <Chip label="This phone" tone="success" /> : <Chip label="Sign out" tone="danger" />}
              onPress={
                s.is_current
                  ? undefined
                  : async () => {
                      if (await confirm('Sign out that device?', s.device, 'Sign out')) {
                        if (await run('revoke', () => api.del(`/auth/sessions/${s.id}`), 'Device signed out.')) await sessions.reload();
                      }
                    }
              }
            />
          ))
        )}
        <Muted>Tap a device to sign it out.</Muted>
        <Button
          label="Sign out everywhere"
          kind="outline"
          busy={busy === 'all'}
          onPress={async () => {
            if (await confirm('Sign out everywhere?', 'Every device, including this phone, will need to sign in again.', 'Sign out')) {
              if (await run('all', () => api.del('/auth/sessions'))) await logout();
            }
          }}
        />
      </Card>

      <Button label="Sign out" kind="outline" onPress={logout} />
    </Screen>
  );
}
