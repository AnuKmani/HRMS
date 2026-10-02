import '../../../core/data/page_result.dart';
import 'asset.dart';
import 'asset_assignment.dart';
import 'asset_type.dart';

/// How the asset register reaches the server.
///
/// Five verbs do something other than read, and each is a separate method
/// for the same reason `DocumentRepository` has eight: a stringly-typed
/// `act(action)` moves a typo from the route into a parameter, where nothing
/// catches it until a 404 arrives from an endpoint that never existed.
///
/// Two of the five are **not** status writes, which is the whole point of
/// this module:
///
///  - [assign] writes a hand-over row. It is the only way an asset becomes
///    `assigned`, because "somebody has it" is a claim that needs a holder,
///    a date and a condition against it — a status column alone could say
///    `assigned` with nobody in the frame.
///
///  - [returnAsset] closes that row and moves the asset back. Asking
///    [changeStatus] for `available` on an assigned asset is answered with
///    a refusal naming the return action, not with a silent write.
///
/// There is no way to author an assignment directly: `POST /asset-assignments`
/// does not exist, and the two endpoints above are its whole vocabulary.
abstract class AssetRepository {
  Future<PageResult<Asset>> list({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<Asset> find(int id);

  Future<Asset> create(Map<String, Object?> body);

  Future<Asset> update(int id, Map<String, Object?> body);

  /// Hands an asset out, writing the hand-over row.
  Future<Asset> assign(int id, Map<String, Object?> body);

  /// Takes one back, closing the hand-over and setting the asset's
  /// condition to whatever came back.
  Future<Asset> returnAsset(int id, Map<String, Object?> body);

  /// Moves the lifecycle status under the server's transition map.
  Future<Asset> changeStatus(int id, {required String status, String? notes});

  /* -------------------------------------------------------- hand-overs */

  /// `GET /asset-assignments` — the cross-employee log, behind
  /// `assets.history.view` on top of `assets.view`. A different question
  /// from [list] ("who has held what?"), so a different permission and a
  /// different page.
  Future<PageResult<AssetAssignment>> assignments({
    int page = 1,
    Map<String, Object?> query = const <String, Object?>{},
  });

  Future<AssetAssignment> findAssignment(int id);

  /* --------------------------------------------------------- vocabulary */

  Future<List<AssetType>> types({
    Map<String, Object?> query = const <String, Object?>{},
  });
}
