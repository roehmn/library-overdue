import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../state/auth_state.dart';
import '../widgets/state_views.dart';

/// Temporary landing screen. It proves that the token works by asking the API who is signed in,
/// and shows the loading / error / retry states. The real role screens replace it next.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final api = context.read<ApiClient>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Library Overdue Notifications'),
        actions: [
          TextButton.icon(
            onPressed: auth.logout,
            icon: const Icon(Icons.logout),
            label: const Text('Sign out'),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: ApiLoader<Map<String, dynamic>>(
        load: () async => (await api.get('/auth/me')) as Map<String, dynamic>,
        builder: (context, me, reload) => Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Card(
              margin: const EdgeInsets.all(16),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Connected to the API', style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 12),
                    Text('Signed in as ${me['name']}'),
                    Text('Role: ${me['role']}'),
                    const SizedBox(height: 16),
                    FilledButton.tonal(onPressed: reload, child: const Text('Check again')),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
