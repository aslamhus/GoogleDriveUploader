<?php

require_once __DIR__ . '/config.php';

use PHPUnit\Framework\TestCase;
use Aslamhus\GoogleDrive\GoogleDriveUploader;
use Google\Client;
use Google\Service\Drive;

// Set the path to the service account's key file
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $_ENV['GOOGLE_APPLICATION_CREDENTIALS']);
define('TEST_FILE', __DIR__ . '/test.mp4');
define('DRIVE_FOLDER_ID', $_ENV['GOOGLE_DRIVE_FOLDER_ID']);

class GoogleDriveUploaderTest extends TestCase
{
    public function testBasicUpload()
    {
        $uploader = new GoogleDriveUploader(DRIVE_FOLDER_ID);
        $file = $uploader->uploadBasic(TEST_FILE, 'test-basic-upload.mp4', 'video/mp4');
        $this->assertTrue(isset($file['id']));
    }

    public function testClientInjection()
    {
        // custom client with refresh token using the google oauth playground
        $client = new Client();
        $client->setAuthConfig($_ENV['GOOGLE_DELEGATE_APPLICATION_CREDENTIALS']);
        $client->setClientId($_ENV['GOOGLE_DELEGATE_CLIENT_ID']);
        $client->setRedirectUri('https://developers.google.com/oauthplayground');
        $client->setAccessType('offline');
        $token = $_ENV['REFRESH_TOKEN_PATH'];
        $client->setAccessToken(json_decode(file_get_contents($token), true));
        $client->addScope(Drive::DRIVE);
        // inject the custom client
        $uploader = new GoogleDriveUploader(DRIVE_FOLDER_ID, $client);
        $file = $uploader->uploadBasic(TEST_FILE, 'test-basic-upload.mp4', 'video/mp4');
        $this->assertTrue(isset($file['id']));
    }

    public function testResumableUpload()
    {
        $uploader = new GoogleDriveUploader(DRIVE_FOLDER_ID);
        $resumeUri = $uploader->initResumable('test-resumable-upload.mp4', 'video/mp4');
        $this->assertTrue(!empty($resumeUri));
        $file = $uploader->startResumable(TEST_FILE);
        $this->assertTrue(isset($file['id']));
    }

    public function testResumableUploadAsyncWithAbort()
    {
        $uploader = new GoogleDriveUploader(DRIVE_FOLDER_ID);
        // init resumable upload and get the resume uri
        $resumeUri = $uploader->initResumable('test-async-resumable-with-abort.mp4', 'video/mp4');
        $this->assertTrue(!empty($resumeUri));
        // define on chunk read callback
        function onChunkRead($chunk, $progress, $fileSize, $byteRange)
        {
            // for real time progress printed to the console, flush the output buffer
            ob_flush();
            flush();
            echo "progress: $progress% \n";
        }
        // upload file async
        $asyncTask = $uploader->startResumable(TEST_FILE, 'onChunkRead', true);
        $count = 0;
        foreach($asyncTask as $task) {
            if($count > 3) {
                // abort the upload after 3 chunks uploaded
                echo "aborting upload... \n";
                $uploader->abort();
                break;
            }
            $count++;
        }
        // restart the upload, sleeping for 2 to simulate a delay
        sleep(2);
        echo "resuming upload... \n";
        // you could also make resume async, but for this test we will make it synchronous
        $file = $uploader->resume($resumeUri, TEST_FILE, 'onChunkRead');
        var_dump($file);
        $this->assertTrue(isset($file['id']));
    }
}
