<?php

namespace App\Services;

class ValidationService
{
    public function validateRow(array $row): array
    {
        $errors = [];

        // Check required fields
        $name = $row['student_name'] ?? $row['studentName'] ?? '';
        if (empty(trim((string)$name))) {
            $errors['student_name'] = 'Name is required.';
        }

        $phone = $row['phone'] ?? '';
        if (empty(trim((string)$phone))) {
            $errors['phone'] = 'Phone number is required.';
        } else {
            try {
                $normalizedPhone = PhoneNormalizer::normalize((string) $phone);
                if (empty($normalizedPhone)) {
                    $errors['phone'] = 'Invalid phone number format.';
                }
            } catch (\Throwable $e) {
                $errors['phone'] = 'Invalid phone number format.';
            }
        }

        $email = $row['email'] ?? '';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address format.';
        }

        return $errors;
    }
}
