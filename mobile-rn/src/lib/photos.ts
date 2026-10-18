import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

const LONGEST_SIDE = 1280;

/**
 * Phone cameras take 10-12 MP pictures, several MB each even compressed —
 * more than the hosted server accepts in one request, which cuts the
 * connection and looks like "no signal". Every photo is scaled so its
 * longest side is at most 1280 px and saved as JPEG (a few hundred KB)
 * before it is sent. If anything goes wrong the original is used.
 */
export async function shrinkPhoto(uri: string): Promise<string> {
  try {
    const original = await ImageManipulator.manipulate(uri).renderAsync();
    const { width, height } = original;
    const context = ImageManipulator.manipulate(uri);
    if (Math.max(width, height) > LONGEST_SIDE) {
      context.resize(width >= height ? { width: LONGEST_SIDE } : { height: LONGEST_SIDE });
    }
    const image = await context.renderAsync();
    const saved = await image.saveAsync({ compress: 0.7, format: SaveFormat.JPEG });
    return saved.uri;
  } catch {
    return uri;
  }
}

/** Whether a picked file is a photo (and so worth shrinking), from its MIME type. */
export const isPhoto = (mimeType?: string | null) => !!mimeType && /^image\/(jpe?g|png|heic|heif|webp)$/i.test(mimeType);

/**
 * A multipart file field, with photos shrunk first (see shrinkPhoto).
 * PDFs and other files go as they are.
 */
export async function uploadField(uri: string, name: string, type: string): Promise<any> {
  if (!isPhoto(type)) return { uri, name, type };
  const small = await shrinkPhoto(uri);
  if (small === uri) return { uri, name, type };
  return { uri: small, name: name.replace(/\.(png|heic|heif|webp|jpe?g)$/i, '') + '.jpg', type: 'image/jpeg' };
}
