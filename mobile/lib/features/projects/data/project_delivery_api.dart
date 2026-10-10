import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_exception.dart';
import '../domain/project_delivery.dart';

final projectDeliveryApiProvider = Provider<ProjectDeliveryApi>(
  (ref) => ProjectDeliveryApi(ref.watch(apiClientProvider)),
);

/// Subcontractors, team, stages, progress, milestones and documents of a
/// project, and the Sales progress view.
class ProjectDeliveryApi {
  ProjectDeliveryApi(this._client);

  final ApiClient _client;

  String _p(int projectId) => '/projects/$projectId';

  Map<String, dynamic> _map(Object? data) {
    if (data is! Map<String, dynamic>) throw unexpectedShapeException;
    return data;
  }

  // Subcontractors
  Future<Subcontractor> addSubcontractor(
    int projectId,
    Map<String, Object?> body,
  ) async => Subcontractor.fromJson(
    _map(
      (await _client.post('${_p(projectId)}/subcontractors', body: body)).data,
    ),
  );

  Future<Subcontractor> updateSubcontractor(
    int projectId,
    int id,
    Map<String, Object?> body,
  ) async => Subcontractor.fromJson(
    _map(
      (await _client.put(
        '${_p(projectId)}/subcontractors/$id',
        body: body,
      )).data,
    ),
  );

  Future<void> removeSubcontractor(
    int projectId,
    int id, {
    required String reason,
    String? removedOn,
  }) => _client.post(
    '${_p(projectId)}/subcontractors/$id/remove',
    body: {'reason': reason, 'removed_on': ?removedOn},
  );

  Future<void> restoreSubcontractor(int projectId, int id) =>
      _client.post('${_p(projectId)}/subcontractors/$id/restore');

  Future<void> uploadSubcontractorDocument(
    int projectId,
    int id,
    Uint8List bytes,
    String filename,
  ) => _client.postMultipart(
    '${_p(projectId)}/subcontractors/$id/document',
    fields: const {},
    files: {'file': MultipartFile.fromBytes(bytes, filename: filename)},
  );

  Future<void> openSubcontractorDocument(
    int projectId,
    int id,
    String filename,
  ) async => openFile(
    await _client.bytes('${_p(projectId)}/subcontractors/$id/document'),
    filename,
  );

  // Team
  Future<List<TeamCandidate>> teamCandidates() async => listOf(
    _map((await _client.get('/project-team-candidates')).data)['items'],
    TeamCandidate.fromJson,
  );

  Future<void> updateTeam(
    int projectId,
    List<int> engineers,
    List<int> supervisors,
  ) => _client.put(
    '${_p(projectId)}/team',
    body: {'site_engineer_ids': engineers, 'site_supervisor_ids': supervisors},
  );

  // Stages and progress
  Future<List<ProjectStage>> stages(int projectId) async => listOf(
    (await _client.get('${_p(projectId)}/stages')).data,
    ProjectStage.fromJson,
  );

  Future<void> replaceStages(
    int projectId,
    List<Map<String, Object?>> stages,
  ) => _client.put('${_p(projectId)}/stages', body: {'stages': stages});

  Future<void> correctStage(
    int projectId,
    int stageId,
    double percent, {
    String? note,
  }) => _client.post(
    '${_p(projectId)}/stages/$stageId/correct',
    body: {'percent': percent, 'note': ?note},
  );

  Future<List<ProgressUpdate>> history(int projectId) async => listOf(
    (await _client.get('${_p(projectId)}/progress-history')).data,
    ProgressUpdate.fromJson,
  );

  // Milestones
  Future<void> addMilestone(int projectId, Map<String, Object?> body) =>
      _client.post('${_p(projectId)}/milestones', body: body);

  Future<void> removeMilestone(int projectId, int id) =>
      _client.delete('${_p(projectId)}/milestones/$id');

  Future<void> setMilestoneStatus(int projectId, int id, String status) =>
      _client.post(
        '${_p(projectId)}/milestones/$id/status',
        body: {'status': status},
      );

  // Documents
  Future<void> uploadDocument(
    int projectId, {
    required String title,
    required String category,
    required Uint8List bytes,
    required String filename,
  }) => _client.postMultipart(
    '${_p(projectId)}/documents',
    fields: {'title': title, 'category': category},
    files: {'file': MultipartFile.fromBytes(bytes, filename: filename)},
  );

  Future<void> openDocument(int projectId, ProjectDocumentFile doc) async =>
      openFile(
        await _client.bytes('${_p(projectId)}/documents/${doc.id}'),
        doc.originalName ?? 'document-${doc.id}',
      );

  Future<void> removeDocument(int projectId, int id) =>
      _client.delete('${_p(projectId)}/documents/$id');

  // Sales progress view
  Future<List<ProjectProgress>> progressList({String? search}) async => listOf(
    (await _client.get('/project-progress', query: {'search': ?search})).data,
    ProjectProgress.fromJson,
  );

  Future<ProjectProgress> progress(int projectId) async =>
      ProjectProgress.fromJson(
        _map((await _client.get('/project-progress/$projectId')).data),
      );

  Future<void> openProgressPdf(int projectId, String code) async => openFile(
    await _client.bytes('/project-progress/$projectId/pdf'),
    'progress-$code.pdf',
  );
}

/// Write to the app's private temp folder and hand it to the phone's viewer.
Future<void> openFile(Uint8List bytes, String filename) async {
  final safe = filename.replaceAll(RegExp(r'[^A-Za-z0-9._-]+'), '_');
  final dir = await getTemporaryDirectory();
  final file = File(
    '${dir.path}${Platform.pathSeparator}${safe.isEmpty ? 'file' : safe}',
  );
  await file.writeAsBytes(bytes, flush: true);
  final result = await OpenFilex.open(file.path);
  if (result.type != ResultType.done) {
    throw StateError(
      'The file was downloaded but no app on this phone can open it.',
    );
  }
}
