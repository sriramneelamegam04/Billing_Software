<?php
function validate_required($fields, $data) {
    $errors = [];
    foreach ($fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst($field) . " is required";
        }
    }
    return $errors;
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
