<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();
$dotenv->required(['GOOGLE_APPLICATION_CREDENTIALS'])->notEmpty();
$dotenv->required(['GOOGLE_DRIVE_FOLDER_ID'])->notEmpty();
