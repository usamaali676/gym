import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_error.dart';
import '../../../core/providers.dart';
import '../../../core/storage/cache_provider.dart';
import '../data/exercise_repository.dart';
import '../domain/exercise.dart';

final exerciseRepositoryProvider = Provider<ExerciseRepository>(
  (ref) => ExerciseRepository(api: ref.watch(apiClientProvider), cache: ref.watch(jsonCacheProvider)),
);

class LibraryState {
  const LibraryState({
    this.filters = const LibraryFilters(),
    this.items = const [],
    this.page = 0,
    this.lastPage = 1,
    this.total = 0,
    this.loading = true,
    this.loadingMore = false,
    this.error,
    this.fromCache = false,
    this.savedAt,
  });

  final LibraryFilters filters;
  final List<Exercise> items;
  final int page;
  final int lastPage;
  final int total;

  /// The first page of the current filters is on its way.
  final bool loading;
  final bool loadingMore;
  final ApiError? error;
  final bool fromCache;
  final DateTime? savedAt;

  bool get hasMore => page > 0 && page < lastPage;

  LibraryState copyWith({
    LibraryFilters? filters,
    List<Exercise>? items,
    int? page,
    int? lastPage,
    int? total,
    bool? loading,
    bool? loadingMore,
    ApiError? error,
    bool clearError = false,
    bool? fromCache,
    DateTime? savedAt,
  }) =>
      LibraryState(
        filters: filters ?? this.filters,
        items: items ?? this.items,
        page: page ?? this.page,
        lastPage: lastPage ?? this.lastPage,
        total: total ?? this.total,
        loading: loading ?? this.loading,
        loadingMore: loadingMore ?? this.loadingMore,
        error: clearError ? null : (error ?? this.error),
        fromCache: fromCache ?? this.fromCache,
        savedAt: savedAt ?? this.savedAt,
      );
}

/// Search, filters and paging of the exercise library. Typing is debounced; an answer for filters the member has since
/// changed is discarded so results never flash the wrong list.
class LibraryController extends Notifier<LibraryState> {
  Timer? _debounce;
  int _generation = 0;

  static const searchDelay = Duration(milliseconds: 350);

  @override
  LibraryState build() {
    ref.onDispose(() => _debounce?.cancel());
    Future.microtask(_reload);
    return const LibraryState();
  }

  void search(String text) {
    _debounce?.cancel();
    _debounce = Timer(searchDelay, () => _apply(state.filters.copyWith(query: text)));
  }

  void setMuscle(String? v) => _apply(state.filters.copyWith(muscle: v));
  void setEquipment(String? v) => _apply(state.filters.copyWith(equipment: v));
  void setDifficulty(String? v) => _apply(state.filters.copyWith(difficulty: v));
  void clearFilters() => _apply(LibraryFilters(query: state.filters.query));

  void _apply(LibraryFilters filters) {
    state = state.copyWith(filters: filters);
    _reload();
  }

  Future<void> refresh() => _reload();

  Future<void> _reload() async {
    final generation = ++_generation;
    final filters = state.filters;
    state = state.copyWith(loading: true, clearError: true);
    try {
      final loaded = await ref.read(exerciseRepositoryProvider).page(filters, 1);
      if (generation != _generation || !ref.mounted) return;
      final p = loaded.data;
      state = LibraryState(
        filters: filters,
        items: p.items,
        page: p.page,
        lastPage: p.lastPage,
        total: p.total,
        loading: false,
        fromCache: loaded.fromCache,
        savedAt: loaded.savedAt,
      );
    } on ApiError catch (e) {
      if (generation != _generation || !ref.mounted) return;
      state = state.copyWith(loading: false, error: e, items: const [], page: 0);
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore || state.fromCache) return;
    final generation = _generation;
    final filters = state.filters;
    state = state.copyWith(loadingMore: true);
    try {
      final loaded = await ref.read(exerciseRepositoryProvider).page(filters, state.page + 1);
      if (generation != _generation || !ref.mounted) return;
      final p = loaded.data;
      // A new page can overlap the previous one if the catalogue changed meanwhile: keep each exercise once.
      final seen = {for (final e in state.items) e.id};
      state = state.copyWith(items: [...state.items, ...p.items.where((e) => !seen.contains(e.id))], page: p.page, lastPage: p.lastPage, loadingMore: false);
    } on ApiError catch (e) {
      if (generation != _generation || !ref.mounted) return;
      state = state.copyWith(loadingMore: false, error: e);
    }
  }
}

final libraryControllerProvider = NotifierProvider<LibraryController, LibraryState>(LibraryController.new);
