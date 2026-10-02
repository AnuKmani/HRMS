/// A kind of company property — the vocabulary behind an asset, not an item.
///
/// Mirrors `AssetTypeResource`. Four columns and no policy, because nothing
/// here describes a person or a hand-over: it is the list a form's picker
/// reads, and it is the same list for everybody who may open the register at
/// all.
///
/// **There is no hard-coded list of asset types anywhere in this app.**
/// LAPTOP, MOBILE_PHONE, SAFETY_EQUIPMENT and the rest are seeded rows an
/// operator may rename, and this class reads whatever comes back — a screen
/// that switched on `code` to decide what to draw would put the very thing
/// the brief asked to be data back into the client.
class AssetType {
  const AssetType({
    required this.id,
    required this.code,
    required this.name,
    this.description,
    required this.status,
    required this.isActive,
  });

  static const statusActive = 'active';
  static const statusInactive = 'inactive';

  final int id;
  final String code;
  final String name;
  final String? description;

  /// Ships because a retired type still appears beside the assets filed
  /// under it — an operator has to be able to tell "we stopped buying these"
  /// from "this never existed" when reading a register from last year.
  final String status;
  final bool isActive;

  factory AssetType.fromJson(Map<String, dynamic> json) => AssetType(
    id: _int(json['id']) ?? 0,
    code: json['code'] as String? ?? '',
    name: json['name'] as String? ?? '',
    description: json['description'] as String?,
    status: json['status'] as String? ?? statusInactive,
    isActive: json['is_active'] == true,
  );
}

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value);
  return null;
}
