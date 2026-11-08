import 'package:flutter/material.dart';

import '../api/api_exception.dart';

/// Shown while a request is in progress.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key});

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(32),
        child: CircularProgressIndicator(),
      ),
    );
  }
}

/// Shown when the API answered successfully with an empty list.
class EmptyView extends StatelessWidget {
  const EmptyView({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.inbox_outlined, size: 48, color: scheme.outline),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

/// Shown when a request failed. Network and server errors offer a Retry button.
class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, this.onRetry});

  final ApiException error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final (icon, title) = switch (error.kind) {
      ApiErrorKind.network => (Icons.cloud_off, 'Cannot reach the server'),
      ApiErrorKind.server => (Icons.error_outline, 'Something went wrong on our side'),
      ApiErrorKind.forbidden => (Icons.lock_outline, 'Not allowed'),
      ApiErrorKind.unauthenticated => (Icons.logout, 'Your session has ended'),
      ApiErrorKind.notFound => (Icons.search_off, 'Not found'),
      _ => (Icons.info_outline, 'Something is not right'),
    };

    final scheme = Theme.of(context).colorScheme;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 48, color: scheme.error),
              const SizedBox(height: 12),
              Text(title, style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text(error.message, textAlign: TextAlign.center),
              if (error.canRetry && onRetry != null) ...[
                const SizedBox(height: 16),
                FilledButton.tonal(onPressed: onRetry, child: const Text('Try again')),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Loads data from the API and shows the right state: loading, error (with retry),
/// empty, or the content. Every list and detail screen is built on this, so the
/// required states are handled in one place.
class ApiLoader<T> extends StatefulWidget {
  const ApiLoader({
    super.key,
    required this.load,
    required this.builder,
    this.isEmpty,
    this.emptyMessage = 'Nothing to show yet.',
  });

  /// Calls the API. Called again when the user taps Try again or [builder] calls reload.
  final Future<T> Function() load;

  /// Builds the content. [reload] fetches the data again.
  final Widget Function(BuildContext context, T data, VoidCallback reload) builder;

  /// Optional: returns true when [data] counts as empty.
  final bool Function(T data)? isEmpty;

  final String emptyMessage;

  @override
  State<ApiLoader<T>> createState() => _ApiLoaderState<T>();
}

class _ApiLoaderState<T> extends State<ApiLoader<T>> {
  late Future<T> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  void _reload() {
    setState(() {
      _future = widget.load();
    });
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<T>(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const LoadingView();
        }

        if (snapshot.hasError) {
          final error = snapshot.error;
          if (error is ApiException) {
            return ErrorView(error: error, onRetry: _reload);
          }
          debugPrint('Unexpected error: $error');
          return ErrorView(
            error: const ApiException(ApiErrorKind.unknown, 'An unexpected error occurred.'),
            onRetry: _reload,
          );
        }

        final data = snapshot.data as T;
        if (widget.isEmpty?.call(data) ?? false) {
          return EmptyView(message: widget.emptyMessage);
        }
        return widget.builder(context, data, _reload);
      },
    );
  }
}
