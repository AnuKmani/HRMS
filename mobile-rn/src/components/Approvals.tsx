import { useState, type ReactNode } from 'react';
import { Text, View } from 'react-native';

import { useAuth } from '../lib/auth';
import { Button, Card, Chip, Field, Muted, Title, colors } from './ui';

export type Step = {
  id: number;
  sequence: number;
  name: string;
  approver_type: string;
  approver_role?: string | null;
  approver_permission?: string | null;
  approver_employee_id?: number | null;
  status: string;
  is_current?: boolean;
  remarks?: string | null;
  resolved_approver?: { full_name?: string } | null;
};

/** Who signs off, step by step. */
export function ChainCard({ chain }: { chain?: Step[] | null }) {
  if (!chain?.length) return null;
  return (
    <Card>
      <Title>Approvals</Title>
      {chain.map((s) => (
        <View key={s.id} style={{ flexDirection: 'row', paddingVertical: 6, alignItems: 'center' }}>
          <View style={{ flex: 1 }}>
            <Text style={{ fontWeight: '600', color: colors.text }}>{s.name}</Text>
            <Muted>
              {s.resolved_approver?.full_name || s.approver_role || s.approver_permission || 'Your manager'}
              {s.remarks ? ` · “${s.remarks}”` : ''}
            </Muted>
          </View>
          <Chip
            label={s.status === 'pending' ? 'Waiting' : s.status[0].toUpperCase() + s.status.slice(1)}
            tone={s.status === 'approved' ? 'success' : s.status === 'rejected' ? 'danger' : s.status === 'pending' ? 'warning' : 'neutral'}
          />
        </View>
      ))}
    </Card>
  );
}

/** Is the signed-in person the one the current step waits on? */
export function useIsApprover(chain: Step[] | null | undefined, ownerEmployeeId: number, status: string, permission: string): boolean {
  const { user, can } = useAuth();
  if (status !== 'pending' || !can(permission) || ownerEmployeeId === user?.employee?.id) return false;
  const current = chain?.find((s) => s.is_current || s.status === 'pending');
  if (!current) return false;
  if (current.approver_type === 'role') return !!user?.roles.includes(current.approver_role ?? '');
  if (current.approver_type === 'permission') return can(current.approver_permission ?? '');
  return current.approver_employee_id === user?.employee?.id;
}

/** Remarks + Approve / Reject. `extra` adds fields (e.g. minutes granted). */
export function DecisionCard({
  onApprove,
  onReject,
  busy,
  extra,
}: {
  onApprove: (remarks: string) => void;
  onReject: (remarks: string) => void;
  busy?: string | null;
  extra?: ReactNode;
}) {
  const [remarks, setRemarks] = useState('');
  const [needRemark, setNeedRemark] = useState(false);
  return (
    <Card>
      <Title>Your decision</Title>
      {extra}
      <Field label="Remarks" value={remarks} onChangeText={setRemarks} multiline error={needRemark ? 'Write why, so they know.' : undefined} />
      <Button testID="approve" label="Approve" onPress={() => onApprove(remarks)} busy={busy === 'approve'} />
      <Button
        testID="reject"
        label="Reject"
        kind="danger"
        onPress={() => {
          if (!remarks.trim()) return setNeedRemark(true);
          onReject(remarks);
        }}
        busy={busy === 'reject'}
      />
    </Card>
  );
}

export const STATUS_LABEL: Record<string, string> = {
  draft: 'Draft',
  pending: 'Waiting for approval',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

export const statusTone = (s: string) => (s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending' ? 'warning' : 'neutral');
