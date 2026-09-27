import 'dart:typed_data';

import '../../../core/data/page_result.dart';
import 'leave_balance.dart';
import 'leave_request.dart';
import 'leave_type.dart';

/// How the leave feature reaches the server.
///
/// One interface for three resources — requests, types and balances — rather
/// than three, because they are one module with one screen behind them: a
/// person applying for leave needs the types to choose from and the balance
/// to know if they can, and splitting those into separate repositories would
/// mean the screen wired three of them together for what is one conversation
/// with the API.
///
/// Abstract so the screens can be tested against an in-memory double: nothing
/// about "does this list show its spinner?" should need a socket.
abstract class LeaveRepository {
  Future<PageResult<LeaveRequest>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<LeaveRequest> find(int id);

  /// Creates a draft. The day count is not in `body` — the server derives it
  /// from the range and the holiday calendar, and accepting it would let a
  /// client record five days as one.
  Future<LeaveRequest> create(Map<String, Object?> body);

  /// Editing is legal only while the request is still a draft; the server
  /// answers 409 once anything has been submitted.
  Future<LeaveRequest> update(int id, Map<String, Object?> body);

  Future<LeaveRequest> submit(int id, {String remarks = ''});

  Future<LeaveRequest> approve(int id, {String remarks = ''});

  Future<LeaveRequest> reject(int id, {String remarks = ''});

  Future<LeaveRequest> cancel(int id, {String remarks = ''});

  /// Files the certificate behind a request. [filename] is only a label for
  /// the server's size and type checks — the bytes are what gets stored, in
  /// private disk, under a name the server picks.
  Future<LeaveRequest> fileCertificate(
    int id,
    Uint8List bytes, {
    String filename = 'certificate.jpg',
  });

  /// The stored file itself, from `GET /leave/{id}/certificate`.
  ///
  /// Reached by id and never by path: the storage path is one of the things
  /// the API refuses to send, so this is the only way a reader gets it.
  Future<Uint8List> certificate(int id);

  Future<PageResult<LeaveType>> types({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<PageResult<LeaveBalance>> balances({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });
}
