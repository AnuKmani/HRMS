import { SimpleCrud } from '../components/SimpleCrud';

export default function DepartmentsScreen() {
  return (
    <SimpleCrud
      path="/departments"
      managePermission="departments.manage"
      title={(r) => `${r.name} (${r.code})`}
      subtitle={(r) => r.description ?? ''}
      fields={[
        { key: 'name', label: 'Name', kind: 'text', required: true },
        { key: 'code', label: 'Code', kind: 'text', required: true, upper: true },
        { key: 'description', label: 'Description', kind: 'text', multiline: true },
      ]}
    />
  );
}
