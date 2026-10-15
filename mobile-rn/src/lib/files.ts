import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';

import { ApiError, getToken } from './api';
import { apiUrl } from './config';

/**
 * Download a protected file (payslip, report PDF, document) with the
 * session's token, then hand it to the phone to open or share.
 */
export async function openRemoteFile(path: string, filename: string, mimeType = 'application/pdf') {
  const dir = new Directory(Paths.cache, 'downloads');
  if (!dir.exists) dir.create({ idempotent: true });
  const safe = filename.replace(/[^A-Za-z0-9._-]+/g, '_') || 'file';
  const target = new File(dir, safe);

  let file: File;
  try {
    file = await File.downloadFileAsync(`${apiUrl()}${path}`, target, {
      headers: { Authorization: `Bearer ${getToken() ?? ''}`, Accept: '*/*' },
      idempotent: true,
    });
  } catch {
    throw new ApiError('The file could not be downloaded. You may not have access to it.');
  }

  if (!(await Sharing.isAvailableAsync())) {
    throw new ApiError('This phone cannot open the file.');
  }
  await Sharing.shareAsync(file.uri, { mimeType, dialogTitle: filename });
}
