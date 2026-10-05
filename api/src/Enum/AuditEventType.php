<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditEventType: string
{
    case RegistrationRequested = 'registration_requested';
    case EmailVerified = 'email_verified';
    case EmailVerificationResent = 'email_verification_resent';
    case LoginSuccess = 'login_success';
    case LoginFailure = 'login_failure';
    case MfaCodeSent = 'mfa_code_sent';
    case MfaCodeFailed = 'mfa_code_failed';
    case MfaCodeSuccess = 'mfa_code_success';
    case MfaPendingExpired = 'mfa_pending_expired';
    case PasswordChanged = 'password_changed';
    case PasswordChangeFailed = 'password_change_failed';
    case PasswordAdded = 'password_added';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case AccountLinked = 'account_linked';
    case AccountUnlinked = 'account_unlinked';
    case OauthScopesGranted = 'oauth_scopes_granted';
    case TrustedDeviceAdded = 'trusted_device_added';
    case TrustedDeviceRevoked = 'trusted_device_revoked';
    case RefreshTokenReuseDetected = 'refresh_token_reuse_detected';
    case OauthLoginSuccess = 'oauth_login_success';
    case OauthLoginFailure = 'oauth_login_failure';
    case Logout = 'logout';
    case SessionRevoked = 'session_revoked';
    case AccountDeletionCodeSent = 'account_deletion_code_sent';
    case AccountDeletionCodeFailed = 'account_deletion_code_failed';
    case AccountDeletionScheduled = 'account_deletion_scheduled';
    case AccountDeletionCancelled = 'account_deletion_cancelled';
    /** Written after the purge, linked to no account and without any personal data. */
    case AccountDeleted = 'account_deleted';
    case DataExported = 'data_exported';
}
