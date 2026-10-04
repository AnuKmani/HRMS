/// One row of the signed-in user's inbox — `InAppNotificationResource`.
///
/// Named `AppNotification` rather than `Notification` so it never collides
/// with Flutter's own `Notification` class in a widget file.
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.read,
    this.route,
    this.createdAt,
  });

  final int id;

  /// The category the server filed it under (`leave.submitted`, …).
  final String type;

  final String title;
  final String body;
  final bool read;

  /// Where in the app the server says this is about, or null when it is
  /// about nothing a screen can show. Only in-app paths are kept: the value
  /// is pushed onto the router, and a URL from anywhere else has no business
  /// there.
  final String? route;

  final DateTime? createdAt;

  AppNotification markedRead() => AppNotification(
    id: id,
    type: type,
    title: title,
    body: body,
    read: true,
    route: route,
    createdAt: createdAt,
  );

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    final route = data is Map ? data['route'] : null;

    return AppNotification(
      id: json['id'] as int,
      type: json['type'] as String? ?? '',
      title: json['title'] as String? ?? '',
      body: json['body'] as String? ?? '',
      read: json['read'] as bool? ?? false,
      route: route is String && route.startsWith('/') && !route.startsWith('//')
          ? route
          : null,
      createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
    );
  }
}
