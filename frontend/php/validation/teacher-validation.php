<?php

function validateTeacher(
    string $name,
    string $employeeId,
    string $email,
    string $username,
    string $password,
    string $confirmPassword
): array {

    $errors = [];


    if ($name === "") {

        $errors[] =
            "Teacher name is required.";

    }


    if ($employeeId === "") {

        $errors[] =
            "Employee ID is required.";

    }


    if ($email === "") {

        $errors[] =
            "Email is required.";

    } elseif (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            "Enter a valid email address.";

    }


    if ($username === "") {

        $errors[] =
            "Username is required.";

    } elseif (
        strlen($username) < 4
    ) {

        $errors[] =
            "Username must contain at least 4 characters.";

    }


    if ($password === "") {

        $errors[] =
            "Password is required.";

    } elseif (
        strlen($password) < 8
    ) {

        $errors[] =
            "Password must contain at least 8 characters.";

    }


    if (
        $password !==
        $confirmPassword
    ) {

        $errors[] =
            "Passwords do not match.";

    }


    return $errors;

}