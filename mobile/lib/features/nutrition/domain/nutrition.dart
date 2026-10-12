// Nutrition as the server computes it. The app never does food maths of its own: every number on screen is one the
// server returned (150 g of a food with 20 g protein per 100 g comes back as 30 g protein).

double _n(Object? v) => (v as num?)?.toDouble() ?? 0;

class Macros {
  const Macros({required this.calories, required this.proteinG, required this.carbsG, required this.fatG, required this.fibreG});

  final double calories;
  final double proteinG;
  final double carbsG;
  final double fatG;
  final double fibreG;

  /// `{calories|kcal, protein_g, carbs_g, fat_g, fibre_g}`: the server uses both names for energy.
  factory Macros.fromJson(Map<String, dynamic> j) => Macros(
        calories: _n(j['calories'] ?? j['kcal']),
        proteinG: _n(j['protein_g']),
        carbsG: _n(j['carbs_g']),
        fatG: _n(j['fat_g']),
        fibreG: _n(j['fibre_g']),
      );
}

class Serving {
  const Serving({required this.id, required this.label, required this.grams, required this.isDefault});
  final int id;
  final String label;
  final double grams;
  final bool isDefault;

  factory Serving.fromJson(Map<String, dynamic> j) => Serving(id: (j['id'] as num).toInt(), label: (j['label'] ?? '').toString(), grams: _n(j['grams']), isDefault: j['is_default'] == true);
}

class Food {
  const Food({required this.id, required this.name, required this.brand, required this.dietType, required this.verified, required this.custom, required this.per100g, required this.servings});

  final int id;
  final String name;
  final String? brand;
  final String? dietType;
  final bool verified;
  final bool custom;
  final Macros per100g;
  final List<Serving> servings;

  Serving? get defaultServing => servings.where((s) => s.isDefault).firstOrNull ?? servings.firstOrNull;

  factory Food.fromJson(Map<String, dynamic> j) => Food(
        id: (j['id'] as num).toInt(),
        name: (j['name'] ?? '').toString(),
        brand: j['brand'] as String?,
        dietType: j['diet_type'] as String?,
        verified: j['is_verified'] == true,
        custom: j['custom'] == true,
        per100g: Macros.fromJson(Map<String, dynamic>.from(j['per_100g'] as Map? ?? const {})),
        servings: [for (final s in (j['servings'] as List? ?? const [])) Serving.fromJson(Map<String, dynamic>.from(s as Map))],
      );
}

/// What an amount of a food is worth: the server's answer to `POST /nutrition/calculate`.
class Calculation {
  const Calculation({required this.food, required this.grams, required this.servingLabel, required this.macros});
  final Food food;
  final double grams;
  final String? servingLabel;
  final Macros macros;

  factory Calculation.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    return Calculation(
      food: Food.fromJson(Map<String, dynamic>.from(j['food'] as Map)),
      grams: _n(j['grams']),
      servingLabel: j['serving_label'] as String?,
      macros: Macros.fromJson(Map<String, dynamic>.from(j['macros'] as Map)),
    );
  }
}

class FoodLogEntry {
  const FoodLogEntry({required this.id, required this.foodId, required this.meal, required this.name, required this.quantity, required this.unit, required this.servingLabel, required this.grams, required this.macros});

  final int id;
  final int? foodId;
  final String meal;
  final String name;
  final double quantity;

  /// grams | serving
  final String unit;
  final String? servingLabel;
  final double grams;
  final Macros macros;

  String get amountLabel => unit == 'serving' ? '${quantity == quantity.roundToDouble() ? quantity.round() : quantity} × ${servingLabel ?? 'serving'}' : '${grams.round()} g';

  factory FoodLogEntry.fromJson(Map<String, dynamic> j) => FoodLogEntry(
        id: (j['id'] as num).toInt(),
        foodId: (j['food_id'] as num?)?.toInt(),
        meal: (j['meal'] ?? '').toString(),
        name: (j['name'] ?? '').toString(),
        quantity: _n(j['quantity']),
        unit: (j['unit'] ?? 'grams').toString(),
        servingLabel: j['serving_label'] as String?,
        grams: _n(j['grams']),
        macros: Macros.fromJson(j),
      );
}

class MealGroup {
  const MealGroup({required this.meal, required this.label, required this.kcal, required this.proteinG, required this.entries});
  final String meal;
  final String label;
  final double kcal;
  final double proteinG;
  final List<FoodLogEntry> entries;

  factory MealGroup.fromJson(Map<String, dynamic> j) => MealGroup(
        meal: (j['meal'] ?? '').toString(),
        label: (j['label'] ?? j['meal'] ?? '').toString(),
        kcal: _n(j['kcal']),
        proteinG: _n(j['protein_g']),
        entries: [for (final e in (j['entries'] as List? ?? const [])) FoodLogEntry.fromJson(Map<String, dynamic>.from(e as Map))],
      );
}

class FoodIdea {
  const FoodIdea({required this.foodId, required this.name, required this.serving, required this.proteinG, required this.kcal});
  final int foodId;
  final String name;
  final String? serving;
  final double proteinG;
  final double kcal;

  factory FoodIdea.fromJson(Map<String, dynamic> j) => FoodIdea(foodId: (j['food_id'] as num).toInt(), name: (j['name'] ?? '').toString(), serving: j['serving'] as String?, proteinG: _n(j['protein_g']), kcal: _n(j['kcal']));
}

class NutritionDay {
  const NutritionDay({required this.date, required this.isTrainingDay, required this.totals, required this.targets, required this.remaining, required this.ideas, required this.meals});

  final DateTime date;
  final bool isTrainingDay;
  final Macros totals;

  /// `null` until the member's profile allows building targets.
  final Macros? targets;
  final Macros? remaining;
  final List<FoodIdea> ideas;
  final List<MealGroup> meals;

  factory NutritionDay.fromJson(Object? json) {
    final j = Map<String, dynamic>.from(json! as Map);
    Macros? macros(Object? v) => v is Map ? Macros.fromJson(Map<String, dynamic>.from(v)) : null;
    return NutritionDay(
      date: DateTime.parse(j['date'].toString()),
      isTrainingDay: j['is_training_day'] == true,
      totals: macros(j['totals']) ?? const Macros(calories: 0, proteinG: 0, carbsG: 0, fatG: 0, fibreG: 0),
      targets: macros(j['targets']),
      remaining: macros(j['remaining']),
      ideas: [for (final s in (j['suggestions'] as List? ?? const [])) FoodIdea.fromJson(Map<String, dynamic>.from(s as Map))],
      meals: [for (final m in (j['meals'] as List? ?? const [])) MealGroup.fromJson(Map<String, dynamic>.from(m as Map))],
    );
  }
}
