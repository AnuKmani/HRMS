import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * app.json plus what depends on the env file: a plain-http server address
 * (a laptop on the office Wi-Fi) needs cleartext allowed on Android; an
 * https staging or production server does not.
 */
export default ({ config }: ConfigContext): ExpoConfig => {
  const apiUrl = process.env.EXPO_PUBLIC_API_URL ?? '';
  const allowHttp =
    apiUrl.startsWith('http://') ||
    process.env.EXPO_PUBLIC_ALLOW_SERVER_SETTING === 'true';

  return {
    ...(config as ExpoConfig),
    plugins: [
      ...(config.plugins ?? []),
      [
        'expo-build-properties',
        { android: { usesCleartextTraffic: allowHttp } },
      ],
    ],
  };
};
