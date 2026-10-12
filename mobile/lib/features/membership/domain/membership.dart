/// What the member's plan allows. The server is the only authority; this is for drawing locks and explanations.
class Entitlements {
  const Entitlements(this.map);

  /// Not known yet (first launch, nothing cached): the UI shows content normally and lets the server refuse.
  static const unknown = Entitlements({});

  final Map<String, bool> map;

  bool get isKnown => map.isNotEmpty;

  /// `true` when allowed, or when we do not know yet. Never use this to protect anything.
  bool allows(String key) => !isKnown || (map[key] ?? false);

  factory Entitlements.fromJson(Object? json) => Entitlements({
        if (json is Map)
          for (final e in json.entries)
            if (e.value is bool) e.key.toString(): e.value as bool,
      });
}

class SubscriptionInfo {
  const SubscriptionInfo({required this.status, required this.source, this.endsAt, this.periodEndsAt, this.trialEndsAt, required this.autoRenew});

  /// trialing | active | canceled | expired | revoked
  final String status;

  /// apple | google | admin | ...
  final String source;
  final DateTime? endsAt;
  final DateTime? periodEndsAt;
  final DateTime? trialEndsAt;
  final bool autoRenew;

  factory SubscriptionInfo.fromJson(Map<String, dynamic> j) => SubscriptionInfo(
        status: (j['status'] ?? 'active').toString(),
        source: (j['source'] ?? '').toString(),
        endsAt: _date(j['ends_at']),
        periodEndsAt: _date(j['current_period_ends_at']),
        trialEndsAt: _date(j['trial_ends_at']),
        autoRenew: j['auto_renew'] == true,
      );
}

class PlanInfo {
  const PlanInfo({
    required this.id,
    required this.slug,
    required this.name,
    required this.description,
    required this.isDefault,
    required this.priceDisplay,
    required this.isFree,
    required this.features,
    required this.entitlements,
    required this.limits,
  });

  final int id;
  final String slug;
  final String name;
  final String? description;
  final bool isDefault;

  /// Admin-entered display text ("USD 9.99"). Informational only: purchases show the store's own price.
  final String? priceDisplay;
  final bool isFree;
  final List<String> features;
  final Entitlements entitlements;

  /// Numeric limits; `null` means unlimited.
  final Map<String, int?> limits;

  factory PlanInfo.fromJson(Map<String, dynamic> j) {
    final price = j['price'] is Map ? Map<String, dynamic>.from(j['price'] as Map) : const <String, dynamic>{};
    return PlanInfo(
      id: (j['id'] as num).toInt(),
      slug: (j['slug'] ?? '').toString(),
      name: (j['name'] ?? '').toString(),
      description: j['description'] as String?,
      isDefault: j['is_default'] == true,
      priceDisplay: price['display'] as String?,
      isFree: ((price['amount_minor'] as num?) ?? 0) == 0,
      features: [for (final f in (j['features'] as List? ?? const [])) f.toString()],
      entitlements: Entitlements.fromJson(j['entitlements']),
      limits: {
        if (j['limits'] is Map)
          for (final e in (j['limits'] as Map).entries) e.key.toString(): (e.value as num?)?.toInt(),
      },
    );
  }
}

class Membership {
  const Membership({required this.plan, required this.subscription, required this.entitlements, required this.limits, required this.paymentProviders, required this.revalidateAfter});

  final PlanInfo? plan;
  final SubscriptionInfo? subscription;
  final Entitlements entitlements;
  final Map<String, int?> limits;

  /// Stores the server can verify purchases for. Empty means in-app purchase is not offered.
  final List<String> paymentProviders;
  final Duration revalidateAfter;

  factory Membership.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    return Membership(
      plan: j['plan'] is Map ? PlanInfo.fromJson(Map<String, dynamic>.from(j['plan'] as Map)) : null,
      subscription: j['subscription'] is Map ? SubscriptionInfo.fromJson(Map<String, dynamic>.from(j['subscription'] as Map)) : null,
      entitlements: Entitlements.fromJson(j['entitlements']),
      limits: {
        if (j['limits'] is Map)
          for (final e in (j['limits'] as Map).entries) e.key.toString(): (e.value as num?)?.toInt(),
      },
      paymentProviders: [
        if (j['payments'] is Map) for (final p in ((j['payments'] as Map)['available_providers'] as List? ?? const [])) p.toString(),
      ],
      revalidateAfter: Duration(seconds: (j['revalidate_after_seconds'] as num?)?.toInt() ?? 300),
    );
  }
}

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v)?.toLocal() : null;
