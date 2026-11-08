/// What went wrong, in terms a screen can react to.
/// Each kind matches one of the states in docs/screen-flow.md.
enum ApiErrorKind {
  validation, // 422: the input was rejected
  unauthenticated, // 401: not signed in, or wrong credentials
  forbidden, // 403: signed in, but this role may not do this
  notFound, // 404: no such record
  conflict, // 409: valid request, but the record is in the wrong state
  network, // no answer from the server
  server, // 5xx, or an answer the app cannot read
  unknown, // anything else
}

/// The one error type every API call can throw.
/// Screens never look at raw HTTP codes, they look at [kind].
class ApiException implements Exception {
  const ApiException(
    this.kind,
    this.message, {
    this.code,
    this.statusCode,
    this.fields = const {},
  });

  final ApiErrorKind kind;

  /// Text that is safe to show the user.
  final String message;

  /// The API's own code, for example INVALID_CREDENTIALS.
  final String? code;

  final int? statusCode;

  /// Per-field messages from a 422 answer, keyed by field name.
  final Map<String, List<String>> fields;

  /// True when trying again could work.
  bool get canRetry => kind == ApiErrorKind.network || kind == ApiErrorKind.server;

  @override
  String toString() => 'ApiException($kind, status: $statusCode, code: $code): $message';
}
