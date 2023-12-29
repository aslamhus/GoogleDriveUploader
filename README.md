# Google Drive Uploader

Google Drive Uploader is a PHP class that simplifies uploading files to Google Drive using the Google Drive API. It supports basic and resumable uploads, allowing you to efficiently upload large files in chunks with options for asynchronous programming.

## Google Drive API Documentation

This class relies on the Google API PHP client library and requires a Google service account with the Google Drive API enabled. Please refer to the following resources for additional information and setup instructions:

- [Google Drive API Quickstart for PHP](https://developers.google.com/drive/api/v3/quickstart/php)
- [Google Drive API Managing Uploads](https://developers.google.com/drive/api/v3/manage-uploads)
- [Google API PHP Client GitHub Repository](https://github.com/googleapis/google-api-php-client/blob/main/examples/large-file-upload.php)

## Setting up Google Drive API Service Account

To use the Google Drive Uploader, you need to set up a Google Cloud Console service account and obtain the necessary credentials. Follow these steps:

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

### Install

```bash
composer require aslamhus/google-drive-uploader
```

### Config

Before you begin uploading you must set your `GOOGLE_APPLICATION_CREDENTIALS` environment variable and create a drive folder with shared permissions granted to your service account email. Make sure you've followed the steps above if you haven't already got a service account in Google Cloud Console.

1. Service account credentials

`GoogleDriveUploader` sets default credentials via environment variables like so:

```php
putenv('GOOGLE_APPLICATION_CREDENTIALS=<path-to-service-account-credentials-json-file>');
```

2. Drive folder id

In order to upload a file to a Google Drive account,
create a folder in your desired google drive account where you'd like your uploads to be delivered. Then grant your service account email address access to the folder. You can find your service account email in Google Cloud Console.

Once you've done this, simply copy the drive folder id from the drive folder url. You can find the drive folder url by navigating to the appropriate folder in Google Drive and then copying the id string from url:

`https://drive.google.com/drive/folders/file-id-string`

### Basic Upload

Use this method for small files.

```php
use Aslamhus\GoogleDrive\GoogleDriveUploader;
$uploader = new GoogleDriveUploader($driveFolderId);
$file = $uploader->uploadBasic($filePath, $fileName, $mimeType);
```

### Resumable Upload

Use this method for large uploads and/or when the upload
process has a high chance of being interrupted.

```php
$uploader = new GoogleDriveUploader($driveFolderId);
$resumeUri = $uploader->initResumable($fileName, $mimeType);
$uploader->startResumable($filePath);
```

### Asynchronous Resumable Upload

To perform an aysnchronous upload, use the resumable upload method.

```php
$uploader = new GoogleDriveUploader($driveFolderId);
$resumeUri = $uploader->initResumable($fileName, $mimeType);
// the second argument to upload resumable is the optional onChunkRead callback
$asyncTask = $uploader->startResumable($filePath, null, true);

foreach ($asyncTask as $response) {
    // perform other logic
    // you can abort the upload at any time by calling $uploader->abort()
    // $response will return false until the upload is complete
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

## Memory Limits

The `basicUpload` method loads the file into memory using `file_get_contents`, so it's best not to use large files with this method. If you do, you may run into memory limit errors.

The resumable upload is performed by splitting the file into chunks which are by default `262144 bytes`. This is the Google recommended chunk size. You should be able to upload very large files without encountering any memory limits.

## Exception Handling

The class includes a custom exception class, `GoogleDriveUploaderException`, to handle errors in the Google Drive uploading process.

## Testing

Before testing, make sure you've installed dev dependencies, and set up your .env file with the `GOOGLE_APPLICATION_CREDENTIALS` and `GOOGLE_DRIVE_FOLDER_ID`.

_Note: running the tests will upload 3 small test videos to your good drive folder with the id you specify_

Then run:

```bash
composer run-script test
```

Feel free to contribute or report issues on [GitHub](https://github.com/aslamhus/GoogleDriveUploader).

Happy uploading!
