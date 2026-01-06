<?php

session_start();

header('Content-Type: application/json');

$language = $_POST['language'] ?? 'en';

if (!in_array($language, ['en', 'ar'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid language']);
    exit();
}

$_SESSION['language'] = $language;

echo json_encode(['success' => true, 'message' => 'Language changed', 'language' => $language]);
?>

