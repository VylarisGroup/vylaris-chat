<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AdminBackupController;
use App\Controllers\AdminOperationsController;
use App\Controllers\AdminSecurityController;
use App\Controllers\AdminPrivacyController;
use App\Controllers\Api\PrivacyApiController;
use App\Controllers\PrivacyController;
use App\Controllers\Api\ConversationController;
use App\Controllers\Api\MeController;
use App\Controllers\Api\MessageController;
use App\Controllers\Api\MiscController;
use App\Controllers\Api\PollController;
use App\Controllers\AuthController;
use App\Controllers\SecurityController;
use App\Controllers\Api\TwoFactorApiController;
use App\Controllers\Api\PushController;
use App\Controllers\PageController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Controllers\AdminLicenseController;
use App\Controllers\AdminUpdateController;
use App\Middleware\LicenseGate;
use App\Middleware\ReleaseSession;
use App\Middleware\RequireAdmin;
use App\Middleware\VerifyCsrf;

return static function (Router $r): void {
    $csrf = [VerifyCsrf::class];
    $auth = [Authenticate::class];
    $api = [Authenticate::class, VerifyCsrf::class, LicenseGate::class, ReleaseSession::class];
    $admin = [Authenticate::class, RequireAdmin::class, VerifyCsrf::class, LicenseGate::class];

    // Anmeldung und Konto (öffentlich)
    $r->add('GET', '/login', [AuthController::class, 'showLogin']);
    $r->add('POST', '/login', [AuthController::class, 'login'], $csrf);
    $r->add('GET', '/login/sso', [AuthController::class, 'ssoStart']);
    $r->add('GET', '/login/sso/callback', [AuthController::class, 'ssoCallback']);
    $r->add('GET', '/login/2fa', [AuthController::class, 'showTwoFactor']);
    $r->add('POST', '/login/2fa', [AuthController::class, 'twoFactor'], $csrf);
    $r->add('POST', '/logout', [AuthController::class, 'logout'], $csrf);
    $r->add('GET', '/register', [AuthController::class, 'showRegister']);
    $r->add('POST', '/register', [AuthController::class, 'register'], $csrf);
    $r->add('GET', '/verify/{token}', [AuthController::class, 'verify']);
    $r->add('POST', '/verify-resend', [AuthController::class, 'resendVerification'], $csrf);
    $r->add('GET', '/forgot', [AuthController::class, 'showForgot']);
    $r->add('POST', '/forgot', [AuthController::class, 'forgot'], $csrf);
    $r->add('GET', '/reset/{token}', [AuthController::class, 'showReset']);
    $r->add('POST', '/reset/{token}', [AuthController::class, 'reset'], $csrf);
    $r->add('GET', '/invite/{token}', [AuthController::class, 'showInvite']);
    $r->add('POST', '/invite/{token}', [AuthController::class, 'acceptInvite'], $csrf);
    $r->add('GET', '/branding/logo', [MeController::class, 'logo']);
    $r->add('GET', '/manifest.webmanifest', [PageController::class, 'manifest']);
    $r->add('GET', '/health', [AdminOperationsController::class, 'health']);
    $r->add('GET', '/privacy', [PrivacyController::class, 'policy']);

    // Pflichtschritte nach der Anmeldung (Passwortwechsel, 2FA-Einrichtung)
    $r->add('GET', '/password-change', [SecurityController::class, 'showPasswordChange'], $auth);
    $r->add('POST', '/password-change', [SecurityController::class, 'passwordChange'], [Authenticate::class, VerifyCsrf::class]);
    $r->add('GET', '/security/2fa', [SecurityController::class, 'showTwoFactorSetup'], $auth);
    $r->add('POST', '/security/2fa', [SecurityController::class, 'twoFactorSetup'], [Authenticate::class, VerifyCsrf::class, LicenseGate::class]);

    // Oberfläche und geschützte Medien
    $r->add('GET', '/', [PageController::class, 'app'], $auth);
    $r->add('GET', '/avatar/u/{id}', [MeController::class, 'userAvatar'], $auth);
    $r->add('GET', '/avatar/c/{id}', [MeController::class, 'conversationAvatar'], $auth);
    $r->add('GET', '/files/{id}', [MiscController::class, 'file'], $auth);

    // API
    $r->add('GET', '/api/bootstrap', [MeController::class, 'bootstrap'], $api);
    $r->add('GET', '/api/poll', [PollController::class, 'poll'], $api);
    $r->add('GET', '/api/stream', [PollController::class, 'stream'], $api);
    $r->add('GET', '/api/users', [MeController::class, 'users'], $api);
    $r->add('GET', '/api/search', [MiscController::class, 'search'], $api);
    $r->add('GET', '/api/notifications', [MiscController::class, 'notifications'], $api);
    $r->add('POST', '/api/notifications/read', [MiscController::class, 'markNotifications'], $api);

    $r->add('PATCH', '/api/me', [MeController::class, 'update'], $api);
    $r->add('PATCH', '/api/me/settings', [MeController::class, 'settings'], $api);
    $r->add('POST', '/api/me/avatar', [MeController::class, 'avatar'], $api);
    $r->add('DELETE', '/api/me/avatar', [MeController::class, 'removeAvatar'], $api);
    $r->add('POST', '/api/me/password', [MeController::class, 'password'], $api);
    $r->add('GET', '/api/me/sessions', [MeController::class, 'sessions'], $api);
    $r->add('POST', '/api/me/sessions/revoke-others', [MeController::class, 'revokeOtherSessions'], $api);
    $r->add('DELETE', '/api/me/sessions/{id}', [MeController::class, 'revokeSession'], $api);
    $r->add('POST', '/api/me/delete', [MeController::class, 'deleteAccount'], $api);
    $r->add('POST', '/api/me/export', [PrivacyApiController::class, 'export'], $api);
    $r->add('GET', '/api/me/exports', [PrivacyApiController::class, 'exports'], $api);
    $r->add('GET', '/api/me/exports/{id}/download', [PrivacyApiController::class, 'exportDownload'], $api);
    $r->add('GET', '/api/me/privacy-requests', [PrivacyApiController::class, 'requests'], $api);
    $r->add('POST', '/api/me/privacy-requests', [PrivacyApiController::class, 'createRequest'], $api);

    $r->add('POST', '/api/push/subscribe', [PushController::class, 'subscribe'], $api);
    $r->add('POST', '/api/push/unsubscribe', [PushController::class, 'unsubscribe'], $api);
    $r->add('GET', '/api/push/latest', [PushController::class, 'latest'], $api);
    $r->add('GET', '/api/me/2fa', [TwoFactorApiController::class, 'status'], $api);
    $r->add('POST', '/api/me/2fa/recovery', [TwoFactorApiController::class, 'recovery'], $api);
    $r->add('POST', '/api/me/2fa/disable', [TwoFactorApiController::class, 'disable'], $api);

    $r->add('GET', '/api/conversations', [ConversationController::class, 'index'], $api);
    $r->add('POST', '/api/conversations/direct', [ConversationController::class, 'direct'], $api);
    $r->add('POST', '/api/conversations/group', [ConversationController::class, 'createGroup'], $api);
    $r->add('GET', '/api/conversations/{id}', [ConversationController::class, 'show'], $api);
    $r->add('PATCH', '/api/conversations/{id}', [ConversationController::class, 'update'], $api);
    $r->add('POST', '/api/conversations/{id}/avatar', [ConversationController::class, 'avatar'], $api);
    $r->add('DELETE', '/api/conversations/{id}/avatar', [ConversationController::class, 'removeAvatar'], $api);
    $r->add('POST', '/api/conversations/{id}/members', [ConversationController::class, 'addMembers'], $api);
    $r->add('DELETE', '/api/conversations/{id}/members/{uid}', [ConversationController::class, 'removeMember'], $api);
    $r->add('PATCH', '/api/conversations/{id}/members/{uid}', [ConversationController::class, 'setRole'], $api);
    $r->add('POST', '/api/conversations/{id}/leave', [ConversationController::class, 'leave'], $api);
    $r->add('PATCH', '/api/conversations/{id}/prefs', [ConversationController::class, 'prefs'], $api);
    $r->add('GET', '/api/conversations/{id}/messages', [ConversationController::class, 'messages'], $api);
    $r->add('POST', '/api/conversations/{id}/messages', [ConversationController::class, 'send'], $api);
    $r->add('POST', '/api/conversations/{id}/read', [ConversationController::class, 'read'], $api);
    $r->add('POST', '/api/conversations/{id}/typing', [ConversationController::class, 'typing'], $api);
    $r->add('POST', '/api/conversations/{id}/uploads', [ConversationController::class, 'upload'], $api);

    $r->add('PATCH', '/api/messages/{id}', [MessageController::class, 'edit'], $api);
    $r->add('DELETE', '/api/messages/{id}', [MessageController::class, 'delete'], $api);
    $r->add('POST', '/api/messages/{id}/reactions', [MessageController::class, 'react'], $api);
    $r->add('POST', '/api/messages/{id}/forward', [MessageController::class, 'forward'], $api);
    $r->add('POST', '/api/messages/{id}/report', [MessageController::class, 'report'], $api);

    // Administration
    $r->add('GET', '/admin', [AdminController::class, 'dashboard'], $admin);
    $r->add('GET', '/admin/users', [AdminController::class, 'users'], $admin);
    $r->add('POST', '/admin/users/invite', [AdminController::class, 'invite'], $admin);
    $r->add('POST', '/admin/users/create', [AdminController::class, 'createUser'], $admin);
    $r->add('POST', '/admin/users/{id}/update', [AdminController::class, 'updateUser'], $admin);
    $r->add('POST', '/admin/users/{id}/action', [AdminController::class, 'userAction'], $admin);
    $r->add('POST', '/admin/invitations/{id}/revoke', [AdminController::class, 'revokeInvitation'], $admin);
    $r->add('GET', '/admin/groups', [AdminController::class, 'groups'], $admin);
    $r->add('GET', '/admin/groups/{id}', [AdminController::class, 'group'], $admin);
    $r->add('POST', '/admin/groups/{id}/admin', [AdminController::class, 'groupAdmin'], $admin);
    $r->add('GET', '/admin/reports', [AdminController::class, 'reports'], $admin);
    $r->add('POST', '/admin/reports/{id}', [AdminController::class, 'handleReport'], $admin);
    $r->add('GET', '/admin/settings', [AdminController::class, 'settings'], $admin);
    $r->add('POST', '/admin/settings/organization', [AdminController::class, 'saveOrganization'], $admin);
    $r->add('POST', '/admin/settings/system', [AdminController::class, 'saveSystem'], $admin);
    $r->add('POST', '/admin/settings/smtp', [AdminController::class, 'saveSmtp'], $admin);
    $r->add('POST', '/admin/settings/smtp-test', [AdminController::class, 'testSmtp'], $admin);
    $r->add('POST', '/admin/settings/cleanup', [AdminController::class, 'runCleanup'], $admin);
    $r->add('GET', '/admin/privacy', [AdminPrivacyController::class, 'index'], $admin);
    $r->add('POST', '/admin/privacy/settings', [AdminPrivacyController::class, 'saveSettings'], $admin);
    $r->add('GET', '/admin/privacy/breaches', [AdminPrivacyController::class, 'breaches'], $admin);
    $r->add('POST', '/admin/privacy/breaches', [AdminPrivacyController::class, 'createBreach'], $admin);
    $r->add('POST', '/admin/privacy/breaches/{id}', [AdminPrivacyController::class, 'updateBreach'], $admin);
    $r->add('GET', '/admin/privacy/review', [AdminPrivacyController::class, 'review'], $admin);
    $r->add('POST', '/admin/privacy/review/checklist', [AdminPrivacyController::class, 'saveChecklist'], $admin);
    $r->add('POST', '/admin/privacy/review/record', [AdminPrivacyController::class, 'saveRecord'], $admin);
    $r->add('GET', '/admin/privacy/verzeichnis.md', [AdminPrivacyController::class, 'registerDownload'], $admin);
    $r->add('POST', '/admin/privacy/requests', [AdminPrivacyController::class, 'createRequest'], $admin);
    $r->add('POST', '/admin/privacy/requests/{id}', [AdminPrivacyController::class, 'updateRequest'], $admin);
    $r->add('POST', '/admin/users/{id}/export', [AdminPrivacyController::class, 'exportUser'], $admin);
    $r->add('GET', '/admin/security', [AdminSecurityController::class, 'index'], $admin);
    $r->add('POST', '/admin/security/policy', [AdminSecurityController::class, 'savePolicy'], $admin);
    $r->add('POST', '/admin/security/oidc', [AdminSecurityController::class, 'saveOidc'], $admin);
    $r->add('POST', '/admin/security/unlock/{id}', [AdminSecurityController::class, 'unlock'], $admin);
    $r->add('GET', '/admin/operations', [AdminOperationsController::class, 'index'], $admin);
    $r->add('POST', '/admin/operations/realtime', [AdminOperationsController::class, 'saveRealtime'], $admin);
    $r->add('POST', '/admin/operations/push', [AdminOperationsController::class, 'savePush'], $admin);
    $r->add('POST', '/admin/operations/test-push', [AdminOperationsController::class, 'testPush'], $admin);
    $r->add('POST', '/admin/operations/monitor', [AdminOperationsController::class, 'saveMonitor'], $admin);
    $r->add('POST', '/admin/operations/test-alert', [AdminOperationsController::class, 'testAlert'], $admin);
    $r->add('GET', '/admin/backup', [AdminBackupController::class, 'index'], $admin);
    $r->add('POST', '/admin/backup/create', [AdminBackupController::class, 'create'], $admin);
    $r->add('POST', '/admin/backup/settings', [AdminBackupController::class, 'settings'], $admin);
    $r->add('POST', '/admin/backup/{name}/download', [AdminBackupController::class, 'download'], $admin);
    $r->add('POST', '/admin/backup/{name}/delete', [AdminBackupController::class, 'delete'], $admin);
    $r->add('POST', '/admin/backup/{name}/verify', [AdminBackupController::class, 'verify'], $admin);
    $r->add('GET', '/admin/license', [AdminLicenseController::class, 'index'], $admin);
    $r->add('POST', '/admin/license/save', [AdminLicenseController::class, 'save'], $admin);
    $r->add('POST', '/admin/license/check', [AdminLicenseController::class, 'check'], $admin);
    $r->add('POST', '/admin/license/remove', [AdminLicenseController::class, 'remove'], $admin);
    $r->add('GET', '/admin/update', [AdminUpdateController::class, 'index'], $admin);
    $r->add('POST', '/admin/update/check', [AdminUpdateController::class, 'check'], $admin);
    $r->add('POST', '/admin/update/install', [AdminUpdateController::class, 'install'], $admin);
    $r->add('POST', '/admin/update/baseline', [AdminUpdateController::class, 'baseline'], $admin);
    $r->add('POST', '/admin/update/rollback', [AdminUpdateController::class, 'rollback'], $admin);
    $r->add('GET', '/admin/tests', [AdminController::class, 'tests'], $admin);
    $r->add('POST', '/admin/tests/run', [AdminController::class, 'runTests'], $admin);
    $r->add('GET', '/admin/logs', [AdminController::class, 'logs'], $admin);
};
