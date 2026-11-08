import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'api_exception.dart';

/// Where the Laravel API lives. Override when running or building:
///   flutter run -d web-server --dart-define=API_BASE_URL=https://example.test/api/v1
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://127.0.0.1:8000/api/v1',
);

/// The only class that talks to the API. It adds the headers, applies a timeout,
/// and turns every failure into an [ApiException].
class ApiClient {
  ApiClient({http.Client? httpClient, String? baseUrl})
      : _http = httpClient ?? http.Client(),
        _baseUrl = baseUrl ?? apiBaseUrl;

  final http.Client _http;
  final String _baseUrl;

  // Kept in memory only. A page refresh signs the user out (see docs/screen-flow.md).
  String? _token;

  /// Called when a signed-in request is answered with 401 (token expired or revoked).
  void Function()? onSessionExpired;

  void setToken(String? token) => _token = token;

  Future<dynamic> get(String path, {Map<String, String>? query}) => _send('GET', path, query: query);

  Future<dynamic> post(String path, {Map<String, dynamic>? body}) => _send('POST', path, body: body);

  Future<dynamic> patch(String path, {Map<String, dynamic>? body}) => _send('PATCH', path, body: body);

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
  }) async {
    var uri = Uri.parse('$_baseUrl$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }

    final request = http.Request(method, uri);
    request.headers['Accept'] = 'application/json';
    if (_token != null) {
      request.headers['Authorization'] = 'Bearer $_token';
    }
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      final streamed = await _http.send(request).timeout(const Duration(seconds: 15));
      response = await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw const ApiException(
        ApiErrorKind.network,
        'The server took too long to answer. Please try again.',
      );
    } on http.ClientException {
      throw const ApiException(
        ApiErrorKind.network,
        'Cannot reach the server. Check that the API is running, then try again.',
      );
    }

    final status = response.statusCode;
    if (status == 204) {
      return null;
    }

    dynamic json;
    try {
      final text = utf8.decode(response.bodyBytes);
      json = text.isEmpty ? null : jsonDecode(text);
    } on FormatException {
      json = null;
    }

    if (status >= 200 && status < 300) {
      if (json == null) {
        throw ApiException(
          ApiErrorKind.server,
          'The server sent an answer the app cannot read.',
          statusCode: status,
        );
      }
      return json;
    }

    throw _toException(status, json);
  }

  /// Reads the API's error envelope: {"error": {"code", "message", "fields"}}.
  ApiException _toException(int status, dynamic json) {
    String? code;
    String? message;
    final fields = <String, List<String>>{};

    if (json is Map && json['error'] is Map) {
      final error = json['error'] as Map;
      code = error['code']?.toString();
      message = error['message']?.toString();

      final rawFields = error['fields'];
      if (rawFields is Map) {
        rawFields.forEach((key, value) {
          fields[key.toString()] =
              value is List ? value.map((item) => item.toString()).toList() : [value.toString()];
        });
      }
    }

    final ApiErrorKind kind;
    final String fallback;

    if (status == 401) {
      kind = ApiErrorKind.unauthenticated;
      fallback = 'Please sign in to continue.';
      // A 401 that is not a failed sign-in means the session has ended.
      if (code != 'INVALID_CREDENTIALS') {
        onSessionExpired?.call();
      }
    } else if (status == 403) {
      kind = ApiErrorKind.forbidden;
      fallback = 'You are not allowed to do this.';
    } else if (status == 404) {
      kind = ApiErrorKind.notFound;
      fallback = 'The item was not found.';
    } else if (status == 409) {
      kind = ApiErrorKind.conflict;
      fallback = 'This cannot be done in the current state.';
    } else if (status == 422) {
      kind = ApiErrorKind.validation;
      fallback = 'Please check the highlighted fields.';
    } else if (status >= 500) {
      kind = ApiErrorKind.server;
      fallback = 'Something went wrong on our side. Please try again.';
    } else {
      kind = ApiErrorKind.unknown;
      fallback = 'Something unexpected happened.';
    }

    return ApiException(kind, message ?? fallback, code: code, statusCode: status, fields: fields);
  }
}
