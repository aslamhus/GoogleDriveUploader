# Google Drive Uploader

The LogsheetReader Google Drive Uploader is a PHP class that facilitates uploading files to Google Drive using the Google Drive API. It supports both basic and resumable uploads, allowing you to efficiently upload large files in chunks. You

## Dependencies

This class relies on the Google API PHP client library and requires a Google service account with the Google Drive API enabled. Please refer to the following resources for additional information and setup instructions:

- [Google Drive API Quickstart for PHP](https://developers.google.com/drive/api/v3/quickstart/php)
- [Google Drive API Managing Uploads](https://developers.google.com/drive/api/v3/manage-uploads)
- [Google API PHP Client GitHub Repository](https://github.com/googleapis/google-api-php-client/blob/main/examples/large-file-upload.php)

## Composer Dependencies

To install the required dependencies, run the following Composer command:

```bash
composer require google/apiclient
```

## Setting up Google Drive API Service Account

To use the LogsheetReader Google Drive Uploader, you need to set up a Google Cloud Console service account and obtain the necessary credentials. Follow these steps:

1. Create a Google Cloud project: [Google Cloud Console](https://console.cloud.google.com/projectcreate)
2. Enable the Google Drive API: [Google Workspace Product Library](https://console.cloud.google.com/marketplace/product/google/drive.googleapis.com)
3. Set up OAuth for your app: Navigate to APIs and services > OAuth consent screen.
4. Choose scopes: Select the required scopes for Google Drive API. See available scopes [here](https://developers.google.com/drive/api/guides/api-specific-auth).
5. Create access credentials (API key): Navigate to APIs & Services > Credentials, then click "Create Credentials" > "API key."
6. Create access credentials (OAuth client ID): Navigate to APIs & Services > Credentials, then click "Create Credentials" > "OAuth client ID." Add authorized URLs and download the client secret JSON file.
7. Download the service account secret: Navigate to Service accounts > Keys > Create key.

8. Create a service account: Navigate to IAM & Admin > Service Accounts, then click "Create Service Account."
9. Add the service account to the Google Drive folder by granting permissions to the service account email.

You're now ready to use the Google Drive Uploader!

## Usage

### Setup

`Aslamhus\GoogleDrive\Uploader` sets default credentials via environment variables. You must set the environment variable like so:

```php
putenv('GOOGLE_APPLICATION_CREDENTIALS=<path-to-service-account-credentials-json-file>');
```

### Basic Upload

```php
use Aslamhus\GoogleDrive\GoogleDriveUploader;
$uploader = new GoogleDriveUploader($driveFolderId);
$file = $uploader->uploadBasic($filePath, $fileName, $mimeType);
```

### Resumable Upload

```php
$uploader = new GoogleDriveUploader($driveFolderId);
$resumeUri = $uploader->initResumable($fileName, $mimeType);
$uploader->startResumable($filePath);
```

### Asynchronous Resumable Upload

```php
$uploader = new GoogleDriveUploader($driveFolderId);
$resumeUri = $uploader->initResumable($fileName, $mimeType);
// the second argument to upload resumable is the optional onChunkRead callback
$asyncTask = $uploader->startResumable($filePath, null, true);

foreach ($asyncTask as $response) {
    // Continue any other logic
    // $response will return false until the upload is complete
    // You can abort the upload at any time by calling $uploader->abort()
}

// Once the upload is complete, $response will contain a Google Drive File object
$fileId = $response['id'];
```

### Resume an Interrupted Upload

```php
$uploader = new GoogleDriveUploader($driveFolderId);
// init and store the resumable uri
$resumeUri = $uploader->initResumable($fileName, $mimeType);
$uploader->startResumable($filePath);
// If the upload is interrupted, resume it with the resume() method passing the resumeUri argument
$uploader->resume($resumeUri);
```

## Callbacks

### onChunkRead

You can pass an optional `onChunkRead` callback method to `startResumable` and `resume`.

```php
/**
 * On chunk read
 *
 * @param string $chunk - the chunk byte string
 * @param int $progress - the progress percentage, e.g. 10.00
 * @param int $fileSize - the file size in bytes
 * @param arary $byteRange - the range of bytes read [from, to], i.e. [0, 256000]
 **/
$onChunkRead = function($chunk,  $progress, $fileSize, $byteRange) {
    // do something like outputting the progress
    echo $progress . "%";
}
$uploader->startResumable($filePath, $onChunkRead);
```

## Exception Handling

The class includes a custom exception class, `GoogleDriveUploaderException`, to handle errors in the Google Drive uploading process.

Feel free to contribute or report issues on [GitHub](https://github.com/aslamhus/GoogleDriveUploader).

Happy uploading!
