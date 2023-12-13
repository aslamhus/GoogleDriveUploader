<?php

namespace LogsheetReader\GoogleDrive;

use Google\Client;
use Google\Service\Drive;
use Google\Http\MediaFileUpload;

/**
 * Google Drive Uploader
 *
 *
 * @example
 *
 * $uploader = new DriveUploader($driveFolderId);
 * // basic upload
 * $uploader->uploadBasic($filePath, $fileName, $mimeType);
 * // resumable upload
 * $resumeSessionUri = $uploader->initResumable($fileName, $mimeType);
 * $uploader->uploadResumable($filePath, $onChunkRead);
 *
 *
 *
 * ## Dependencies
 *
 * Requires a google service account with the google drive api enabled
 *
 * @see https://developers.google.com/drive/api/v3/quickstart/php
 * @see https://developers.google.com/drive/api/v3/manage-uploads
 * @see https://github.com/googleapis/google-api-php-client/blob/main/examples/large-file-upload.php
 *
 *
 * ## Composer dependencies
 *
 * run composer require google/apiclient
 *
 * ## Instructions to set up Google Drive API service account
 *
 * You need a Google Cloud Console service account and credentials see (<https://developers.google.com/workspace/guides/create-project>)
 *
 * 1. create a google cloud project:
 *  go to page-> <https://console.cloud.google.com/projectcreate>
 *
 *  2. enable google api you want to use
 *  In the Google Cloud console, go to Menu menu > More products > Google Workspace > Product Library.
 *
 * 3. set up Oauth for your app
 *  under Apis and services, go to OAuth consent screen and follow instructions
 *
 * 4. choose scopes, for Google Drive API see scopes here: <https://developers.google.com/drive/api/guides/api-specific-auth>
 *
 * 5.  create access credentials (api key)
 * In the Google Cloud console, go to Menu menu > APIs & Services > Credentials.
 *  click create credentails -> api key
 * Copy it and add it to your config file / env file
 *
 * 6. create access credentails (Oauth client id)
 *  click create crednetials -> oauth client id
 *  add the authorized urlis (authorized javascript origins/redirect urls)
 * Download the client secret json file
 *
 * _NOTE: you must also download the service account secret. Go tot service accounts -> keys -> create key_
 *
 * 7. create service account
 *  In the Google Cloud console, go to Menu menu > IAM & Admin > Service Accounts.
 *  click create service account
 *
 * 8. add service account to google drive folder (give permissions to the service account email)
 * Use the folder id of the folder you want to upload to
 *
 * You're done!
 *
 *
 * @example
 *
 * $uploader = new DriveUploader($driveFolderId);
 * $uploader->uploadBasic($filePath, $fileName, $mimeType);
 *
 */

class DriveUploader
{
    private Client $client;
    private Drive $driveService;
    private string $driveFolderId;
    private int $chunkSize;
    private string $resumeUri;
    private MediaFileUpload $media;
    private const CHUNK_SIZE = 1 * 1024 * 1024;

    public function __construct(string $driveFolderId, int $chunkSize = self::CHUNK_SIZE)
    {
        $this->driveFolderId = $driveFolderId;
        // set the chunk size for chunk uploads
        $this->chunkSize =  $chunkSize;
        $this->initDriveService();

    }

    /**
     * Set the drive folder id where the files will be uploaded
     *
     * Typically this is a shared parent folder id (so you can access it in another google drive account)
     *
     * @param string $id
     * @return void
     */
    public function setDriveFolderId(string $id): void
    {
        $this->driveFolderId = $id;
    }

    /**
     * Gets the resume uri if it has been set
     *
     * @return string
     */
    public function getResumeUri(): string
    {
        return $this->resumeUri ?? '';
    }

    private function initDriveService()
    {
        $this->client = new Client();
        $this->client->useApplicationDefaultCredentials();
        $this->client->addScope(Drive::DRIVE);
        $this->driveService = new Drive($this->client);
    }

    /**
     * Create drive file
     *
     * @param string $fileName
     * @return Drive\DriveFile
     */
    private function createDriveFile(string $fileName): Drive\DriveFile
    {
        $fileMetadata = new Drive\DriveFile([
            'name' => $fileName,
            'parents' => [ $this->driveFolderId ]
        ]);
        return $fileMetadata;
    }

    /**
     * Basic google drive upload for small files
     *
     * @param string $filePath - the path to the file to upload
     * @param string $fileName - the name that will be given to the file in google drive
     * @param string $mimeType - the mimetype of the file, i.e. image/jpeg | audio/mp3
     * @param string $uploadType - multipart|media|resumable @see https://developers.google.com/drive/api/guides/manage-uploads
     * @return Drive\DriveFile
     */
    public function uploadBasic(string $filePath, string $fileName, string $mimeType, string $uploadType = 'multipart'): Drive\DriveFile
    {

        try {

            $fileMetadata = $this->createDriveFile($fileName);
            // validate file path
            if(!$filePath) {
                throw new \Exception('Invalid file path:' . $filePath);
            }
            $content = file_get_contents($filePath);
            $file = $this->driveService->files->create($fileMetadata, array(
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => $uploadType,
                'fields' => 'id',
                'supportsAllDrives' => true // <--- Added

            ));
            return $file;
        } catch(\Exception $e) {
            throw new GoogleDriveUploaderException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }
    }

    /**
     * Init resumable upload
     *
     * @param string $fileName - the name that will be given to the file in google drive
     * @param string $mimeType - the mimetype of the file, i.e. image/jpeg | audio/mp3
     * @return string - the resume uri
     */
    public function initResumable(string $fileName, string $mimeType): string
    {
        // create the file metadata
        $fileMetaData =  $this->createDriveFile($fileName);
        // create a file object
        $file = new Drive\DriveFile($fileMetaData);
        // set defer to true
        $this->client->setDefer(true);
        // create the request
        $request = $this->driveService->files->create($file);
        // initialize the media file upload request
        $this->media = new MediaFileUpload($this->client, $request, $mimeType, null, true, $this->chunkSize);
        // save the resume uri
        $this->resumeUri = $this->media->getResumeUri();
        return $this->resumeUri;
    }


    /**
     * Upload resumable
     *
     * Begins a file upload in chunks using the resumable upload protocol.
     * This method can only be called after initResumable() has been called.
     * If interrupted, save the resume uri and call the resume() method to resume the upload
     *
     * @example
     *
     * // Note: The resume URI is stored after initResumable(), and can be accessed with getResumeUri() after resumableUpload() is called
     * $uploader = new DriveUploader($driveFolderId);
     * $resumeUri = $uploader->initResumable($fileName, $mimeType);
     * $uploader->uploadResumable($filePath, $mimeType);
     *
     * // at this point, if the upload is interrupted, you can resume it with the resume() method
     * $uploader->resume($resumeUri, $filePath, $mimeType);
     *
     *
     *
     * @param string $filePath - the path to the file to upload
     * @param callable $onChunkRead - a callback function that will be called on each chunk read
     * @param int [$bytesPointer] - the byte range to start from
     *
     * @return mixed - false when the upload is unfinished or the decoded http response
     */
    public function uploadResumable(string $filePath, callable $onChunkRead): mixed
    {
        // check that initResumable() has been called
        if(!$this->resumeUri || !$this->media) {
            throw new GoogleDriveUploaderException('initResumable() must be called before uploadResumable()');
        }
        $status = false;
        /**
         * Note: we wrap onChunkRead in a closure that includes the operation  MediaFileUpload::nextChunk
         */
        $handleChunkRead = function ($chunk, $progress, $fileSize, $byteRange) use ($onChunkRead, &$status) {
            $status = $this->media->nextChunk($chunk);
            return call_user_func($onChunkRead, $chunk, $progress, $fileSize, $byteRange);
        };

        try {
            // validate file path
            if(!$filePath || !is_file($filePath)) {
                throw new \Exception('Invalid file path:' . $filePath);
            }
            // set the overall file size
            $fileSize = filesize($filePath);
            $this->media->setFileSize($fileSize);
            // open the file and read chunks
            $this->readChunks($filePath, $fileSize, $handleChunkRead);
            // reset client
            $this->client->setDefer(false);
            return $status;

        } catch(Exception $e) {
            throw new GoogleDriveUploaderException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }
    }


    /**
     * Read chunks from a file
     *
     * @param string $filePath
     * @param integer $fileSize
     * @param callable $onChunkRead
     * @param integer $bytesPointer
     * @return void
     */
    private function readChunks(string $filePath, int $fileSize, callable $onChunkRead, int $bytesPointer = 0)
    {

        $handle = fopen($filePath, "rb");
        // set the byte pointer
        fseek($handle, $bytesPointer);
        $countChunks = 0;

        while(!feof($handle)) {
            $chunk = fread($handle, $this->chunkSize);
            // get the number of bytes processed
            $bytesProcessed = ftell($handle);
            // calculate progress as percentage
            $progress = ceil($bytesProcessed  / $fileSize * 100);
            // get byte range:
            // [start, end -1] (because zero indexed)
            $byteRange = [$bytesProcessed - strlen($chunk), $bytesProcessed - 1];
            // fire onChunkRead callback if it exists
            if($onChunkRead) {
                $args = [$chunk,  $progress, $fileSize, $byteRange];
                call_user_func($onChunkRead, ...$args);
            }

            $countChunks++;
        }

        // close the file reader
        fclose($handle);
    }



    /**
     * Resume upload
     *
     * Resumes an interrupted upload
     *
     * 1. query the resumable uri
     *
     * 2. if the response is 308, then the upload is incomplete and we can resume.
     * The response will contain a range header that indicates the byte range that has been uploaded
     * We can use this to determine where to resume the upload.
     * We then call putRemainingChunks() to upload the remaining chunks.
     *
     * 3. if the response is 200, then the upload is complete and we return true.
     *
     * 4. for any other response the upload has either not started, failed or the resume uri
     * has expired. In these cases an exception is thrown and you must restart
     *
     *
     * @param string $resumeUri
     * @param string $filePath - the path to the file to upload
     * @param string $fileName - the name that will be given to the file in google drive
     * @param string $mimeType
     * @param callable $onChunkRead - a callback function that will be called on each chunk read
     * @return bool - true if the upload is complete
     *
     * @throws GoogleDriveUploaderException
     */
    public function resume(string $resumeUri, string $filePath, $fileName, string $mimeType, callable $onChunkRead = null)
    {
        // perform a curl PUT request to the resume uri
        list($output, $status) = $this->queryResumableUri($resumeUri);

        switch($status) {
            case 308:
                /**
                 * If you received a 308 Resume Incomplete response, process the Range header of the response
                 * to determine which bytes the server has received. If the response doesn't have a Range header,
                 * no bytes have been received. For example, a Range header of bytes=0-42 indicates that the first 43 bytes of the file were received and that the next chunk to upload would start with byte 44.
                 */

                // find the byte range from the response
                $byteRange = $this->getByteRangeFromResumeURI($output);
                // complete the upload
                $this->putRemainingChunks($resumeUri, $filePath, $byteRange, $onChunkRead);

                return true;
            case 200:
            case 201:
                // upload is complete, no resume necessary
                return true;

            case 404:
            case 410:
            case 500:
            case 503:
                // upload did not start, failed or resume uri expired, you must restart
                throw new \Exception('Upload has not started, you must restart. Http status: ' . $status);


        }

        return false;

    }

    /**
     * Put remaining chunks
     *
     * Uploads the remaining chunks of a file
     *
     * @param string $resumeUri
     * @param string $filePath
     * @param array $byteRange
     * @param callable $onChunkRead
     * @return void
     */
    private function putRemainingChunks(string $resumeUri, string $filePath, array $byteRange, callable $onChunkRead)
    {
        $fileSize = filesize($filePath);
        $bytesPointer = $byteRange[1] + 1;
        // wrap class method resumePutChunk in closure so it can access resumeUri and onChunkRead variables
        $handleChunkRead = function ($chunk, $progress, $fileSize, $byteRange) use ($resumeUri, $onChunkRead) {
            // sends a PUT request to the resume uri with the chunk
            list($status, $output) = $this->resumePutChunk($chunk, $progress, $fileSize, $byteRange, $resumeUri);
            // validate the put request status
            // note, chunk status will return 308 until the final chunk which should return 200
            if($status != 202 && $status != 200 && $status != 308) {
                throw new GoogleDriveUploaderException("chunk upload failed with status: " . $status);
            }
            // fire the onChunkRead callback
            call_user_func($onChunkRead, $chunk, $progress, $fileSize, $byteRange);
        };
        // read remaining chunks
        $this->readChunks($filePath, $fileSize, $handleChunkRead, $bytesPointer);

    }

    /**
     * resume put chunk
     *
     * Sends a PUT request to the resume uri with the chunk.
     *
     * This method is called from putRemainingChunks() and uploads each chunk.
     *
     * @see https://developers.google.com/drive/api/v3/manage-uploads#resume-upload
     *
     * From Google: Now that you know where to resume the upload,
     * continue to upload the file beginning with the next byte.
     * Include a Content-Range header to indicate which portion of the file you send.
     * For example, Content-Range: bytes 43-1999999 indicates that you send bytes 44 through 2,000,000.
     *
     * @return void
     */
    private function resumePutChunk($chunk, $progress, $fileSize, $byteRange, $resumeUri)
    {

        $cH = curl_init();
        $chunkLength = strlen($chunk);
        $options = [
            CURLOPT_URL => $resumeUri,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => array(
                "Content-Range: bytes " . $byteRange[0] . "-" . $byteRange[1] . "/" . $fileSize,
                "Content-Length: $chunkLength"
            ),
            CURLOPT_POSTFIELDS => $chunk
        ];
        // file_put_contents(__DIR__ . '/chunk.txt', $chunk);
        curl_setopt_array($cH, $options);
        // execute
        $output = curl_exec($cH);
        if($output === false) {
            $error = curl_error($cH);
            throw new \Exception($error);
        }
        $status = curl_getinfo($cH, CURLINFO_HTTP_CODE);
        curl_close($cH);
        return [$status, $output];


    }

    /**
     * Query resumable uri
     *
     * Perform a curl PUT request to the resume uri in order
     * to determine the status of the interrupted upload
     *
     * 1. create an empty PUT request to the resumable session URI
     * 2. set the Content-Range header to bytes * / $totalSize3.
     * 3. send the request
     *
     * @see https://developers.google.com/drive/api/v3/manage-uploads#resume-upload
     *
     * ## response codes
     * if the response is 308, then the upload is incomplete
     * if the response is 200, then the upload is complete
     * if the response is 404, then the upload has not started
     * if the response is 410, then the upload was cancelled
     * if the response is 500, then the upload failed
     * if the response is 503, then the upload failed
     *
     *
     * @param string $resumeUri
     * @return array - [ $output, $status ] - the cURL output and the http status code
     */
    private function queryResumableUri(string $resumeUri): array
    {
        $cH = curl_init();
        $options = [
            CURLOPT_URL => $resumeUri,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => [
                'Content-Range' => "*/*"
            ]
        ];
        curl_setopt_array($cH, $options);
        // execute
        $output = curl_exec($cH);
        $status = curl_getinfo($cH, CURLINFO_HTTP_CODE);
        curl_close($cH);
        return [ $output, $status];
    }

    private function getByteRangeFromResumeURI(string $curlOutput): array
    {
        $headers = explode("\r\n", $curlOutput);
        $byteRange = [];
        foreach($headers as $header) {
            if(preg_match('/range:/i', $header)) {
                preg_match('/(\d+)\s?-\s?(\d+)/', $header, $matches);
                $byteRange = [$matches[1], $matches[2]];
            }
        }
        return $byteRange;
    }
}


class GoogleDriveUploaderException extends \Exception
{
    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        $message = "GoogleDriveUploaderException: $message";
        parent::__construct($message, $code, $previous);

    }
}
