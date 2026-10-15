import { SimpleCrud } from '../components/SimpleCrud';
import { lookupOptions } from '../lib/people';

export default function DesignationsScreen() {
  return (
    <SimpleCrud
      path="/designations"
      managePermission="designations.manage"
      title={(r) => `${r.name} (${r.code})`}
      subtitle={(r) => r.department?.name ?? r.description ?? ''}
      fields={[
        { key: 'name', label: 'Name', kind: 'text', required: true },
        { key: 'code', label: 'Code', kind: 'text', required: true, upper: true },
        { key: 'department_id', label: 'Department', kind: 'pick', options: () => lookupOptions('/departments') },
        { key: 'description', label: 'Description', kind: 'text', multiline: true },
      ]}
    />
  );
}
