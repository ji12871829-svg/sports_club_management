<?php

/**
 * Password strength rules for member registration and profile updates.
 */
function asc_validate_password_strength(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'one number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'one special character';
    }

    if ($errors === []) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok' => false,
        'message' => 'Password must include ' . implode(', ', $errors) . '.',
    ];
}

function asc_password_strength_hint(): string
{
    return 'Use 8+ characters with uppercase, lowercase, a number, and a special character.';
}
