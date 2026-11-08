import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';

/// The signed-in person, as returned by the API. Only id, name and role (data minimization).
class AuthUser {
  const AuthUser({required this.id, required this.name, required this.role});

  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
        id: json['id'] as int,
        name: json['name'] as String,
        role: json['role'] as String,
      );

  final int id;
  final String name;

  /// borrower, librarian or supervisor.
  final String role;
}

/// Who is signed in. Screens watch this and rebuild when it changes.
class AuthState extends ChangeNotifier {
  AuthState(this.api) {
    api.onSessionExpired = _handleSessionExpired;
  }

  final ApiClient api;

  AuthUser? _user;
  String? _notice;

  AuthUser? get user => _user;

  bool get isLoggedIn => _user != null;

  /// A message for the login screen, for example "Your session has ended".
  String? get notice => _notice;

  /// Throws [ApiException] when sign-in fails. The login screen shows the message.
  Future<void> login(String email, String password) async {
    final data = await api.post(
      '/auth/login',
      body: {'email': email, 'password': password},
    ) as Map<String, dynamic>;

    api.setToken(data['token'] as String);
    _user = AuthUser.fromJson(data['user'] as Map<String, dynamic>);
    _notice = null;
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } on ApiException {
      // The person asked to sign out, so end the local session even if the
      // server could not be reached. (Known limitation: the token then stays valid
      // on the server until it is revoked.)
    }
    _clear(null);
  }

  void _handleSessionExpired() {
    if (_user != null) {
      _clear('Your session has ended. Please sign in again.');
    }
  }

  void _clear(String? notice) {
    api.setToken(null);
    _user = null;
    _notice = notice;
    notifyListeners();
  }
}
