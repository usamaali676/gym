// One change made while offline, waiting to be sent to the server (`POST /sync/batch`).

/// The operation types the server's batch endpoint understands (see docs/mobile/openapi.yaml, SyncBatch).
abstract final class OpTypes {
  static const setLog = 'workout.set.log';
  static const exerciseSkip = 'workout.exercise.skip';
  static const workoutFinish = 'workout.finish';
  static const workoutAbandon = 'workout.abandon';
  static const measurementSave = 'measurement.save';
  static const postureSave = 'posture.session.save';
}

enum OpState {
  /// Waiting to be sent (or sent and not yet answered).
  queued,

  /// The server holds a newer version of the same record. The member decides which one to keep.
  conflict,

  /// The server refused it for good (invalid, no longer allowed). It will not be retried.
  rejected,
}

class PendingOp {
  const PendingOp({
    required this.opId,
    required this.type,
    required this.payload,
    required this.createdAt,
    required this.label,
    this.state = OpState.queued,
    this.attempts = 0,
    this.errorCode,
    this.errorMessage,
    this.serverCopy,
  });

  /// A client-generated UUID. The server applies an op id once, however often it is sent.
  final String opId;
  final String type;
  final Map<String, dynamic> payload;

  /// When the member did it on this phone (sent as `client_created_at`; conflict checks compare it with the server row).
  final DateTime createdAt;

  /// A plain-words description for the sync screen ("Bench press, set 2: 60 kg × 8").
  final String label;
  final OpState state;
  final int attempts;
  final String? errorCode;
  final String? errorMessage;

  /// For a conflict: what the server holds.
  final Map<String, dynamic>? serverCopy;

  /// The workout this op belongs to, when it has one.
  int? get sessionId => (payload['session_id'] as num?)?.toInt();

  PendingOp copyWith({OpState? state, int? attempts, String? errorCode, String? errorMessage, Map<String, dynamic>? serverCopy, DateTime? createdAt, bool clearProblem = false}) => PendingOp(
        opId: opId,
        type: type,
        payload: payload,
        createdAt: createdAt ?? this.createdAt,
        label: label,
        state: state ?? this.state,
        attempts: attempts ?? this.attempts,
        errorCode: clearProblem ? null : (errorCode ?? this.errorCode),
        errorMessage: clearProblem ? null : (errorMessage ?? this.errorMessage),
        serverCopy: clearProblem ? null : (serverCopy ?? this.serverCopy),
      );

  /// The shape `POST /sync/batch` takes.
  Map<String, dynamic> toRequest() => {'op_id': opId, 'type': type, 'payload': payload, 'client_created_at': createdAt.toUtc().toIso8601String()};

  Map<String, dynamic> toJson() => {
        'op_id': opId,
        'type': type,
        'payload': payload,
        'created_at': createdAt.toUtc().toIso8601String(),
        'label': label,
        'state': state.name,
        'attempts': attempts,
        'error_code': errorCode,
        'error_message': errorMessage,
        'server_copy': serverCopy,
      };

  factory PendingOp.fromJson(Map<String, dynamic> j) => PendingOp(
        opId: j['op_id'] as String,
        type: j['type'] as String,
        payload: Map<String, dynamic>.from(j['payload'] as Map),
        createdAt: DateTime.parse(j['created_at'] as String),
        label: (j['label'] ?? '') as String,
        state: OpState.values.firstWhere((s) => s.name == j['state'], orElse: () => OpState.queued),
        attempts: (j['attempts'] as num?)?.toInt() ?? 0,
        errorCode: j['error_code'] as String?,
        errorMessage: j['error_message'] as String?,
        serverCopy: j['server_copy'] is Map ? Map<String, dynamic>.from(j['server_copy'] as Map) : null,
      );
}
