import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Muted, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { money, ymd } from '../../lib/format';
import { employeeOptions } from '../../lib/people';

export default function NewLoan() {
  const router = useRouter();
  const { can, user } = useAuth();
  const forOthers = can('loans.approve') || can('loans.manage');
  const next = new Date();
  next.setMonth(next.getMonth() + 1, 1);
  const [employeeId, setEmployeeId] = useState<number | null>(user?.employee?.id ?? null);
  const [type, setType] = useState('loan');
  const [principal, setPrincipal] = useState('');
  const [count, setCount] = useState('6');
  const [start, setStart] = useState(ymd(next));
  const [remarks, setRemarks] = useState('');
  const [people, setPeople] = useState<Option[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (forOthers) employeeOptions().then(setPeople, () => {});
  }, [forOthers]);

  const each = Number(principal) && Number(count) ? Number(principal) / Number(count) : null;

  async function save(submit: boolean) {
    setBusy(submit ? 'submit' : 'draft');
    setError(null);
    setErrors({});
    try {
      const l = await api.post<{ id: number }>('/loans', {
        employee_id: employeeId,
        loan_type: type,
        principal_amount: Number(principal),
        number_of_installments: Number(count),
        start_date: start,
        remarks: remarks || null,
      });
      if (submit) await api.post(`/loans/${l.id}/submit`);
      router.replace(`/loans/${l.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen>
      {error ? <Banner message={error} /> : null}
      {forOthers ? <PickerField label="Employee" required value={employeeId} options={people} onChange={(v) => setEmployeeId(v as number)} error={errors.employee_id} /> : null}
      <PickerField
        label="Type"
        value={type}
        options={[
          { value: 'loan', label: 'Loan' },
          { value: 'salary_advance', label: 'Salary advance' },
        ]}
        onChange={(v) => setType(String(v))}
        error={errors.loan_type}
      />
      <Field label="Amount" required value={principal} onChangeText={setPrincipal} keyboardType="decimal-pad" error={errors.principal_amount} />
      <Field label="Number of monthly instalments" required value={count} onChangeText={setCount} keyboardType="number-pad" error={errors.number_of_installments} />
      {each ? <Muted>About {money(each)} is taken from each salary.</Muted> : null}
      <DateField label="First deduction month" required value={start} onChange={setStart} />
      <Field label="Reason / remarks" value={remarks} onChangeText={setRemarks} multiline error={errors.remarks} />
      <Screen.Spacer />
      <Button label="Submit for approval" onPress={() => save(true)} busy={busy === 'submit'} />
      <Button label="Save as draft" kind="outline" onPress={() => save(false)} busy={busy === 'draft'} />
    </Screen>
  );
}
