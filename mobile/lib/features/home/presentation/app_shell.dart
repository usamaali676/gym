import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../sync/presentation/sync_widgets.dart';

/// Bottom navigation around the signed-in screens. Tabs are added as their features ship; every visible tab works.
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.shell});
  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // The sync strip sits just above the tab bar, so it never fights the screens' own top padding.
      body: Column(children: [
        Expanded(child: shell),
        const SyncStatusStrip(),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'HOME'),
          NavigationDestination(icon: Icon(Icons.fitness_center_outlined), selectedIcon: Icon(Icons.fitness_center), label: 'TRAIN'),
          NavigationDestination(icon: Icon(Icons.menu_book_outlined), selectedIcon: Icon(Icons.menu_book), label: 'LIBRARY'),
          NavigationDestination(icon: Icon(Icons.restaurant_outlined), selectedIcon: Icon(Icons.restaurant), label: 'FUEL'),
          NavigationDestination(icon: Icon(Icons.show_chart), selectedIcon: Icon(Icons.insights), label: 'PROGRESS'),
          NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'ACCOUNT'),
        ],
      ),
    );
  }
}
