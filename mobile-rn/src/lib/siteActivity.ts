import * as Location from 'expo-location';

import { ApiError } from './api';

export type SiteActivityReport = {
  id: number;
  employee?: { id: number; full_name?: string } | null;
  project?: { id: number; name: string } | null;
  site?: { id: number; name: string } | null;
  report_date: string;
  work_category: string;
  work_performed: string;
  progress_percentage: number;
  manpower?: string | null;
  materials_used?: string | null;
  equipment_used?: string | null;
  issues?: string | null;
  safety_issues?: string | null;
  remarks?: string | null;
  latitude?: number | string | null;
  longitude?: number | string | null;
  gps_accuracy?: number | string | null;
  has_gps_fix?: boolean;
  status: string;
  is_draft: boolean;
  is_editable: boolean;
  submitted_at?: string | null;
  photos?: { id: number }[];
  photo_count?: number;
};

export const WORK_CATEGORIES = ['Civil', 'Electrical', 'Plumbing', 'Finishing', 'Inspection', 'Survey', 'Maintenance'];

/** Where the phone is now, in the shape the report endpoints take. */
export async function reportGps(): Promise<{ latitude: number; longitude: number; gps_accuracy: number }> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (permission.status !== 'granted') {
    throw new ApiError('Location permission is needed to record where the report was written.', 400);
  }
  const fix = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  return { latitude: fix.coords.latitude, longitude: fix.coords.longitude, gps_accuracy: Math.round(fix.coords.accuracy ?? 0) };
}
