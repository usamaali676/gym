/// The credentials of this installation, as returned by `/auth/login|register|guest|refresh`.
class Session {
  const Session({
    required this.accessToken,
    required this.expiresAt,
    required this.refreshAfter,
    this.deviceId,
  });

  final String accessToken;
  final DateTime expiresAt;

  /// From this moment the app should rotate the token (still valid until [expiresAt]).
  final DateTime refreshAfter;
  final int? deviceId;

  bool isExpired(DateTime now) => !now.isBefore(expiresAt);
  bool needsRefresh(DateTime now) => !now.isBefore(refreshAfter);

  /// `{access_token, expires_at, refresh_after}` plus the device id from the auth result.
  factory Session.fromApi(Map<String, dynamic> token, {int? deviceId}) => Session(
        accessToken: token['access_token'] as String,
        expiresAt: DateTime.parse(token['expires_at'] as String).toUtc(),
        refreshAfter: DateTime.parse(token['refresh_after'] as String).toUtc(),
        deviceId: deviceId,
      );

  Map<String, dynamic> toJson() => {
        'access_token': accessToken,
        'expires_at': expiresAt.toIso8601String(),
        'refresh_after': refreshAfter.toIso8601String(),
        'device_id': deviceId,
      };

  factory Session.fromJson(Map<String, dynamic> json) => Session(
        accessToken: json['access_token'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String).toUtc(),
        refreshAfter: DateTime.parse(json['refresh_after'] as String).toUtc(),
        deviceId: json['device_id'] as int?,
      );
}
