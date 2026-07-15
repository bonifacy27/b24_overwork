<?php
/**
 * Bitrix24 NTLM guard for trusted NLE domain users.
 *
 * The Apache NTLM module may authenticate NLE users successfully and pass
 * REMOTE_USER=NLE\username to PHP. Bitrix24 then treats the request as domain
 * SSO and can spend up to max_execution_time trying to resolve that user.
 *
 * For NLE identities only, remove SSO-related server variables before the
 * Bitrix prolog starts. NSC users are left untouched.
 */

$remoteUser = (string)($_SERVER['REMOTE_USER'] ?? $_SERVER['AUTH_USER'] ?? '');
$remoteUser = trim($remoteUser);

$isNleUser = false;

if ($remoteUser !== '') {
    $normalizedUser = str_replace('/', '\\', $remoteUser);
    $isNleUser = (bool)preg_match('~^(?:NLE\\\\|[^@]+@NLE(?:\\.|$))~i', $normalizedUser);
}

if ($isNleUser) {
    foreach (
        [
            'REMOTE_USER',
            'AUTH_USER',
            'AUTH_TYPE',
            'PHP_AUTH_USER',
            'PHP_AUTH_PW',
            'PHP_AUTH_DIGEST',
            'HTTP_AUTHORIZATION',
            'Authorization',
            'REDIRECT_REMOTE_USER',
            'REDIRECT_AUTH_USER',
            'REDIRECT_HTTP_AUTHORIZATION',
        ] as $key
    ) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
}
