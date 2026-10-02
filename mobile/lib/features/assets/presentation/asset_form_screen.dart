import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/permissions/permission_scope.dart';
import '../../../core/presentation/fields.dart';
import '../../../core/presentation/no_permission.dart';
import '../../../core/presentation/remote_picker.dart';
import '../data/api_asset_repository.dart';
import '../domain/asset.dart';
import '../domain/asset_repository.dart';
import '../domain/asset_type.dart';
import 'asset_controller.dart';

/// Register a piece of company property, or correct its master record.
///
/// [assetId] null means create.
///
/// Three things this form is careful about:
///
///  - **it never sets a status.** A new asset is `available` because the
///    server says so, and an existing asset's status is refused outright
///    (`prohibited`) on this route. Status moves go through the assign,
///    return and change-status endpoints, each with its own permission and
///    its own refusal — a form that could set `assigned` would be a second
///    way to say somebody holds something when no hand-over was ever
///    written.
///
///  - **condition is a create-only field.** On an edit it is refused too,
///    because what condition an asset is in *now* is the return action's
///    answer, recorded against the hand-over that put it in that state.
///
///  - **the cost is only drawn when the server sent it.** `purchase_cost`
///    is omitted from the payload for a reader without `assets.manage`, so
///    this form omits the field rather than showing an empty box that would
///    be read as "free".
class AssetFormScreen extends ConsumerStatefulWidget {
  const AssetFormScreen({super.key, this.assetId});

  final int? assetId;

  @override
  ConsumerState<AssetFormScreen> createState() => _AssetFormScreenState();
}

class _AssetFormScreenState extends ConsumerState<AssetFormScreen> {
  late final TextEditingController _code;
  late final TextEditingController _name;
  late final TextEditingController _description;
  late final TextEditingController _serial;
  late final TextEditingController _manufacturer;
  late final TextEditingController _model;
  late final TextEditingController _purchaseDate;
  late final TextEditingController _cost;
  late final TextEditingController _notes;

  int? _typeId;
  String? _typeName;
  String _condition = Asset.conditionGood;
  bool _costVisible = true;

  bool _loading = false;
  bool _saving = false;

  String? _banner;
  Map<String, String> _errors = const <String, String>{};

  bool get _isCreate => widget.assetId == null;

  AssetRepository get _repository => ref.read(assetRepositoryProvider);

  @override
  void initState() {
    super.initState();

    _code = TextEditingController();
    _name = TextEditingController();
    _description = TextEditingController();
    _serial = TextEditingController();
    _manufacturer = TextEditingController();
    _model = TextEditingController();
    _purchaseDate = TextEditingController();
    _cost = TextEditingController();
    _notes = TextEditingController();

    // Gated before the fetch for the same reason the detail screen is: a URL
    // typed by hand should be refused at the door rather than answered by a
    // request the API will only turn into a 403.
    final allowed = _isCreate
        ? ref.read(permissionScopeProvider).canCreateAssets
        : ref.read(permissionScopeProvider).canUpdateAssets;

    if (!allowed) return;

    if (!_isCreate) _load();
  }

  @override
  void dispose() {
    _code.dispose();
    _name.dispose();
    _description.dispose();
    _serial.dispose();
    _manufacturer.dispose();
    _model.dispose();
    _purchaseDate.dispose();
    _cost.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _banner = null;
    });

    try {
      final asset = await _repository.find(widget.assetId!);
      if (!mounted) return;

      setState(() {
        _typeId = asset.assetTypeId;
        _typeName = asset.typeName;
        _code.text = asset.assetCode;
        _name.text = asset.name;
        _description.text = asset.description ?? '';
        _serial.text = asset.serialNumber ?? '';
        _manufacturer.text = asset.manufacturer ?? '';
        _model.text = asset.model ?? '';
        _purchaseDate.text = asset.purchaseDate ?? '';
        _cost.text = asset.purchaseCost ?? '';
        _costVisible = asset.costVisible;
        _notes.text = asset.notes ?? '';
        _loading = false;
      });
    } catch (failure) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _banner = _messageFor(failure);
      });
    }
  }

  AssetType? _typeFromPicker(int? id) {
    if (id == null) return null;

    for (final type in ref.read(assetTypesPickerProvider).items) {
      if (type.id == id) return type;
    }

    return null;
  }

  /// Drop one field's error the moment its value is touched — a
  /// *replacement* of the map that also rebuilds. See the identically named
  /// method on the program form for why it is never a bare mutation.
  void _touch(String field) {
    setState(() => _errors = Map<String, String>.of(_errors)..remove(field));
  }

  Map<String, String> _validate() {
    final errors = <String, String>{};

    if (_code.text.trim().isEmpty) {
      errors['asset_code'] = 'Enter an asset code.';
    }
    if (_typeId == null) errors['asset_type_id'] = 'Choose an asset type.';
    if (_name.text.trim().isEmpty) errors['name'] = 'Enter a name.';

    final cost = double.tryParse(_cost.text.trim());
    if (_costVisible &&
        _cost.text.trim().isNotEmpty &&
        (cost == null || cost < 0)) {
      errors['purchase_cost'] =
          'Enter the cost as a number, or leave it empty.';
    }

    return errors;
  }

  Future<void> _save() async {
    if (_saving) return;

    final errors = _validate();

    if (errors.isNotEmpty) {
      setState(() {
        _errors = errors;
        _banner = null;
      });
      return;
    }

    setState(() {
      _saving = true;
      _banner = null;
      _errors = const <String, String>{};
    });

    final body = <String, Object?>{
      'asset_code': _code.text.trim(),
      'asset_type_id': _typeId,
      'name': _name.text.trim(),
      'description': _description.text.trim(),
      'serial_number': _serial.text.trim(),
      'manufacturer': _manufacturer.text.trim(),
      'model': _model.text.trim(),
      'purchase_date': _purchaseDate.text.trim(),
      if (_costVisible) 'purchase_cost': _cost.text.trim(),
      'notes': _notes.text.trim(),
      // Create only: the server refuses `current_condition` on an update
      // and `status` on both. Sending them anyway would be a payload shaped
      // for a route this is not.
      if (_isCreate) 'current_condition': _condition,
    };

    try {
      if (_isCreate) {
        await _repository.create(body);
      } else {
        await _repository.update(widget.assetId!, body);
      }

      ref.read(assetListProvider.notifier).reload();

      if (!mounted) return;
      context.go('/assets');
    } on ApiException catch (failure) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _errors = failure.errors;
        _banner = failure.errors.isEmpty ? failure.message : null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _banner = 'Something went wrong while saving. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = ref.watch(permissionScopeProvider);
    final theme = Theme.of(context);

    if (_isCreate && !scope.canCreateAssets) {
      return Scaffold(
        appBar: AppBar(title: const Text('Register asset')),
        body: const NoPermission(module: 'asset registration'),
      );
    }

    if (!_isCreate && !scope.canUpdateAssets) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit asset')),
        body: const NoPermission(module: 'asset editing'),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(_isCreate ? 'Register asset' : 'Edit asset')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (_banner != null)
                    Padding(
                      key: const ValueKey('asset-form-banner'),
                      padding: const EdgeInsets.only(bottom: 16),
                      child: Material(
                        color: theme.colorScheme.errorContainer,
                        borderRadius: BorderRadius.circular(8),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Text(
                            _banner!,
                            style: TextStyle(
                              color: theme.colorScheme.onErrorContainer,
                            ),
                          ),
                        ),
                      ),
                    ),
                  LabeledTextField(
                    key: const ValueKey('asset-code'),
                    label: 'Asset code',
                    controller: _code,
                    isRequired: true,
                    hint: 'TOOL-00042',
                    helper: 'What the barcode or label will carry.',
                    errorText: _errors['asset_code'],
                    onChanged: (_) => _touch('asset_code'),
                  ),
                  RemotePickerField<AssetType>(
                    key: const ValueKey('asset-type'),
                    label: 'Type',
                    provider: assetTypesPickerProvider,
                    idOf: (type) => type.id,
                    labelOf: (type) => type.name,
                    value: _typeId,
                    selectedLabel: _typeName,
                    isRequired: true,
                    searchHint: 'Search asset types',
                    helper:
                        'The kind of thing this is — data, not a fixed list.',
                    errorText: _errors['asset_type_id'],
                    onChanged: (id) {
                      _typeId = id;
                      _typeName = _typeFromPicker(id)?.name;
                      _touch('asset_type_id');
                    },
                  ),
                  LabeledTextField(
                    key: const ValueKey('asset-name'),
                    label: 'Name',
                    controller: _name,
                    isRequired: true,
                    hint: 'Dell Latitude 5540',
                    errorText: _errors['name'],
                    onChanged: (_) => _touch('name'),
                  ),
                  LabeledTextField(
                    key: const ValueKey('asset-description'),
                    label: 'Description',
                    controller: _description,
                    maxLines: 3,
                  ),
                  LabeledTextField(
                    key: const ValueKey('asset-serial'),
                    label: 'Serial number',
                    controller: _serial,
                  ),
                  Row(
                    children: [
                      Expanded(
                        child: LabeledTextField(
                          key: const ValueKey('asset-manufacturer'),
                          label: 'Manufacturer',
                          controller: _manufacturer,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: LabeledTextField(
                          key: const ValueKey('asset-model'),
                          label: 'Model',
                          controller: _model,
                        ),
                      ),
                    ],
                  ),
                  DateField(
                    key: const ValueKey('asset-purchase-date'),
                    label: 'Purchased on',
                    controller: _purchaseDate,
                    allowEmpty: true,
                  ),
                  if (_costVisible)
                    LabeledTextField(
                      key: const ValueKey('asset-cost'),
                      label: 'Purchase cost',
                      controller: _cost,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      errorText: _errors['purchase_cost'],
                    )
                  else
                    // Not an empty box: "we were not told" and "it was free"
                    // are different facts, and only one of them is true here.
                    Text(
                      'Purchase cost is not shown to your role.',
                      key: const ValueKey('asset-cost-withheld'),
                      style: theme.textTheme.bodySmall,
                    ),
                  if (_isCreate) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Condition on arrival',
                      style: theme.textTheme.labelLarge,
                    ),
                    const SizedBox(height: 6),
                    InputDecorator(
                      decoration: const InputDecoration(
                        border: OutlineInputBorder(),
                        isDense: true,
                        contentPadding: EdgeInsets.symmetric(
                          horizontal: 12,
                          vertical: 12,
                        ),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          key: const ValueKey('asset-condition'),
                          isExpanded: true,
                          value: _condition,
                          items:
                              const [
                                    (Asset.conditionNew, 'New'),
                                    (Asset.conditionGood, 'Good'),
                                    (Asset.conditionFair, 'Fair'),
                                    (Asset.conditionPoor, 'Poor'),
                                  ]
                                  .map(
                                    (option) => DropdownMenuItem<String>(
                                      value: option.$1,
                                      child: Text(option.$2),
                                    ),
                                  )
                                  .toList(),
                          onChanged: (value) => setState(
                            () => _condition = value ?? Asset.conditionGood,
                          ),
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),
                  LabeledTextField(
                    key: const ValueKey('asset-notes'),
                    label: 'Notes',
                    controller: _notes,
                    maxLines: 3,
                  ),
                  const SizedBox(height: 24),
                  FilledButton(
                    key: const ValueKey('save-asset'),
                    onPressed: _saving ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(_isCreate ? 'Register asset' : 'Save changes'),
                  ),
                ],
              ),
            ),
    );
  }

  static String _messageFor(Object failure) {
    if (failure is ApiException) return failure.message;

    return 'Something went wrong. Please try again.';
  }
}
