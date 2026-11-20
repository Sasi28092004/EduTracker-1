<?php

function validateLogin(
    string $username,
    string $password,
    string $role
): array {

    $errors = [];

    if (trim($username) === "") {
        $errors[] = "Username is required.";
    }

    if ($password === "") {
        $errors[] = "Password is required.";
    }

    $allowedRoles = ["admin", "student", "teacher"];

    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = "Please select a valid role.";
    }

    return $errors;
}
