# GoogleDriveUploader

A PHP class for uploading files to Google Drive, providing methods for basic and resumable uploads, with support for managing dependencies and setting up Google Drive API service accounts

## Usage

```php
$uploader = new DriveUploader($driveFolderId);
// basic upload
$uploader->uploadBasic($filePath, $fileName, $mimeType);
// resumable upload
$resumeSessionUri = $uploader->initResumable($fileName, $mimeType);
// where onChunkRead is a callback method with progress information
$uploader->uploadResumable($filePath, $onChunkRead);
```

## Dependencies

Requires a google service account with the google drive api enabled

@see <https://developers.google.com/drive/api/v3/quickstart/php>
@see <https://developers.google.com/drive/api/v3/manage-uploads>
@see <https://github.com/googleapis/google-api-php-client/blob/main/examples/large-file-upload.php>

## Composer dependencies

run composer require google/apiclient

## Instructions to set up Google Drive API service account

You need a Google Cloud Console service account and credentials see (<https://developers.google.com/workspace/guides/create-project>)

1. create a google cloud project:
   go to page-> <https://console.cloud.google.com/projectcreate>

2. enable google api you want to use
   In the Google Cloud console, go to Menu menu > More products > Google Workspace > Product Library.

3. set up Oauth for your app
   under Apis and services, go to OAuth consent screen and follow instructions

4. choose scopes, for Google Drive API see scopes here: <https://developers.google.com/drive/api/guides/api-specific-auth>

5. create access credentials (api key)
   In the Google Cloud console, go to Menu menu > APIs & Services > Credentials.
   click create credentails -> api key
   Copy it and add it to your config file / env file

6. create access credentails (Oauth client id)
   click create crednetials -> oauth client id
   add the authorized urlis (authorized javascript origins/redirect urls)
   Download the client secret json file

_NOTE: you must also download the service account secret. Go tot service accounts -> keys -> create key_

7. create service account
   In the Google Cloud console, go to Menu menu > IAM & Admin > Service Accounts.
   click create service account

8. add service account to google drive folder (give permissions to the service account email)
   Use the folder id of the folder you want to upload to

You're done!
