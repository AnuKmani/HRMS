import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../domain/site_report_photo.dart';
import 'report_photo_source.dart';

/// The photograph strip shared by both report forms and both detail screens.
///
/// Two halves with deliberately different behaviour, because they are
/// different facts:
///
///  - **attached** frames are already on the server. Removing one is a
///    request with a policy behind it, so it asks first and names the
///    count that will be left.
///  - **pending** frames exist only on this phone. Removing one needs no
///    confirmation at all — nothing has happened anywhere else — and a
///    dialog would just be friction on the way to fixing a bad shot.
///
/// Neither half ever shows a path. An attached frame's thumbnail is fetched
/// as bytes through [ReportPhotoSource] for the same reason there is no
/// `Image.network` in this app: a URL that a browser could open would undo
/// the private storage the server is doing the work to maintain.
class ReportPhotosSection extends StatelessWidget {
  const ReportPhotosSection({
    super.key,
    required this.attached,
    required this.pending,
    required this.pathOf,
    required this.onAdd,
    required this.onRemoveAttached,
    required this.onRemovePending,
    this.label = 'Photographs',
    this.canEdit = true,
    this.busy = false,
    this.maxPhotos = 12,
    this.errorText,
    this.emptyHint,
  });

  /// Frames already on the server, in the document's own order.
  final List<SiteReportPhoto> attached;

  /// Frames captured on this phone and not uploaded yet.
  final List<Uint8List> pending;

  /// How to read bytes for an attached frame — a report knows its own
  /// endpoint, and this section must not have to.
  final String Function(SiteReportPhoto photo) pathOf;

  final VoidCallback onAdd;
  final ValueChanged<SiteReportPhoto> onRemoveAttached;
  final ValueChanged<int> onRemovePending;

  final String label;

  /// False on a submitted report, where the row is closed to edits by
  /// anybody including its author.
  final bool canEdit;

  /// True while an add or a removal is in flight, so a second tap cannot
  /// put the list and the server out of step.
  final bool busy;

  final int maxPhotos;

  final String? errorText;

  final String? emptyHint;

  bool get _canAdd =>
      canEdit && !busy && attached.length + pending.length < maxPhotos;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final total = attached.length + pending.length;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(label, style: theme.textTheme.labelLarge),
              const Spacer(),
              if (total > 0)
                Text(
                  '$total of $maxPhotos',
                  key: const ValueKey('report-photo-count'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.outline,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          if (total == 0 && emptyHint != null)
            Padding(
              key: const ValueKey('report-photos-empty'),
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(emptyHint!, style: theme.textTheme.bodySmall),
            ),
          Wrap(
            key: const ValueKey('report-photos'),
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (var index = 0; index < attached.length; index++)
                _AttachedThumb(
                  key: ValueKey('report-photo-attached-$index'),
                  path: pathOf(attached[index]),
                  removable: canEdit && !busy,
                  busy: busy,
                  onRemove: () => onRemoveAttached(attached[index]),
                ),
              for (var index = 0; index < pending.length; index++)
                _PendingThumb(
                  key: ValueKey('report-photo-pending-$index'),
                  bytes: pending[index],
                  busy: busy,
                  onRemove: canEdit && !busy
                      ? () => onRemovePending(index)
                      : null,
                ),
              if (_canAdd)
                _AddThumb(
                  key: const ValueKey('report-photo-add'),
                  onTap: onAdd,
                ),
            ],
          ),
          if (errorText != null) ...[
            const SizedBox(height: 8),
            Text(
              errorText!,
              key: const ValueKey('report-photos-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _AddThumb extends StatelessWidget {
  const _AddThumb({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SizedBox(
      width: 84,
      height: 84,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(padding: EdgeInsets.zero),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.add_a_photo_outlined, size: 22),
            const SizedBox(height: 4),
            Text('Add', style: theme.textTheme.labelSmall),
          ],
        ),
      ),
    );
  }
}

/// A frame that has not been uploaded yet.
///
/// Shown from memory and marked with a cloud-off badge while it is only on
/// the phone, because "captured" and "attached" are the two states a person
/// has to be able to tell apart by looking — one of them will survive the
/// app closing and one will not.
class _PendingThumb extends StatelessWidget {
  const _PendingThumb({
    super.key,
    required this.bytes,
    required this.onRemove,
    required this.busy,
  });

  final Uint8List bytes;
  final VoidCallback? onRemove;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 84,
      height: 84,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: Image.memory(
              bytes,
              width: 84,
              height: 84,
              fit: BoxFit.cover,
              gaplessPlayback: true,
              errorBuilder: (context, error, stack) => const ColoredBox(
                color: Color(0xFFE0E0E0),
                child: Icon(Icons.broken_image_outlined),
              ),
            ),
          ),
          if (onRemove != null)
            Positioned(
              right: -8,
              top: -8,
              child: Material(
                color: Theme.of(context).colorScheme.surface,
                shape: const CircleBorder(),
                elevation: 2,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: onRemove,
                  child: const Padding(
                    padding: EdgeInsets.all(4),
                    child: Icon(Icons.close, size: 16),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// A frame already on the server.
///
/// Fetches its own bytes, so a thumbnail that cannot be read (expired
/// session, a policy that has since changed, a file that went missing)
/// degrades to a placeholder rather than taking the whole form down with
/// it.
class _AttachedThumb extends ConsumerStatefulWidget {
  const _AttachedThumb({
    super.key,
    required this.path,
    required this.onRemove,
    required this.removable,
    required this.busy,
  });

  final String path;
  final VoidCallback onRemove;
  final bool removable;
  final bool busy;

  @override
  ConsumerState<_AttachedThumb> createState() => _AttachedThumbState();
}

class _AttachedThumbState extends ConsumerState<_AttachedThumb> {
  Uint8List? _bytes;
  bool _failed = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _AttachedThumb old) {
    super.didUpdateWidget(old);

    if (old.path != widget.path) {
      _bytes = null;
      _failed = false;
      _loading = true;
      _load();
    }
  }

  Future<void> _load() async {
    try {
      final bytes = await ref
          .read(reportPhotoSourceProvider)
          .fetch(widget.path);

      if (!mounted) return;

      setState(() {
        _bytes = bytes;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;

      setState(() {
        _failed = true;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SizedBox(
      width: 84,
      height: 84,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Positioned.fill(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: _failed
                  ? ColoredBox(
                      color: theme.colorScheme.errorContainer,
                      child: const Icon(Icons.hide_image_outlined),
                    )
                  : _loading || _bytes == null
                  ? const ColoredBox(
                      color: Color(0xFFEEEEEE),
                      child: Center(
                        child: SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      ),
                    )
                  : Image.memory(
                      _bytes!,
                      fit: BoxFit.cover,
                      gaplessPlayback: true,
                      errorBuilder: (context, error, stack) => const ColoredBox(
                        color: Color(0xFFE0E0E0),
                        child: Icon(Icons.broken_image_outlined),
                      ),
                    ),
            ),
          ),
          if (widget.removable && !widget.busy)
            Positioned(
              right: -8,
              top: -8,
              child: Material(
                color: theme.colorScheme.surface,
                shape: const CircleBorder(),
                elevation: 2,
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTap: widget.onRemove,
                  child: const Padding(
                    padding: EdgeInsets.all(4),
                    child: Icon(Icons.close, size: 16),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
