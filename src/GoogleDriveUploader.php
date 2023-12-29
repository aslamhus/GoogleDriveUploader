<?php

namespace Aslamhus\GoogleDrive;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Http\MediaFileUpload;
use Aslamhus\GoogleDrive\CurlRequest;
use Aslamhus\GoogleDrive\CurlResponse;
use Aslamhus\GoogleDrive\Exceptions\GoogleDriveUploaderException;
use Aslamhus\GoogleDrive\Exceptions\GoogleDriveUploaderResumeException;

/**
 * Google Drive Uploader
 * v1.0.0
 *
 * by @aslamhus
 *
 * For further documentation @see https://github.com/aslamhus/GoogleDriveUploader.git
 * or the README.md file
 *
 *
 * ### Basic upload
 * ```php
 * $uploader = new DriveUploader($driveFolderId);
 * $uploader->uploadBasic($filePath, $fileName, $mimeType);
 * ```
 *
 * ### Resumable upload
 * ```php
 * // init the uploader and get the resume uri
 * $resumeUri = $uploader->initResumable($fileName, $mimeType);
 * // upload file
 * $uploader->startResumable($filePath, $mimeType);
 * ```
 *
 * ### Asynchronous Resumable upload
 * ```php
 * // init the uploader and get the resume uri
 * $resumeUri = $uploader->initResumable($fileName, $mimeType);
 * // upload file asynchronously by setting the async flag to true in the 3rd argument
 * $asyncTask = $uploader->startResumable($filePath, $mimeType, true);
 * foreach($asyncTask as $response) {
 *      // continue any other logic
 *      // $response will return false until the upload is complete
 *      // you can abort the upload at any time by calling $uploader->abort()
 * }
 * // once the upload is complete it will return a Google Drive File object
 * $fileId = $response['id'];
 * ```
 *
 * ### Resume an interrupted upload
 * ```php
 * // Note: The resume URI is stored after initResumable(), and can be accessed with getResumeUri() after resumableUpload() is called
 * $uploader = new DriveUploader($driveFolderId);
 * $resumeUri = $uploader->initResumable($fileName, $mimeType);
 * $uploader->startResumable($filePath, $mimeType);
 * // at this point, if the upload is interrupted, you can resume it with the resume() method
 * $uploader->resume($resumeUri);
 * ```
 *
 *
 */

class GoogleDriveUploader
{
    private ?Client $client;
    private Drive $driveService;
    private string $driveFolderId;
    private int $chunkSize;
    private string $resumeUri;
    private MediaFileUpload $media;
    private const CHUNK_SIZE = 262144;
    private bool $shouldAbort = false;

    /**
     * Constructor
     *
     * @param string $driveFolderId
     * @param [int] $chunkSize - the chunk size for chunk uploads, defaults to Google recommended chunk size 262144
     */
    public function __construct(string $driveFolderId, int $chunkSize = self::CHUNK_SIZE, Client $client = null)
    {
        $this->client = $client;
        // set the drive folder id
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

    /**
     * Init drive service
     *
     * Initializes the google drive service with the service account credentials
     * If a client is passed in the constructor, it will be used instead.
     * Otherwise, the service account credentials must be stored in the environment variable GOOGLE_APPLICATION_CREDENTIALS
     *
     * ```php
     * putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $myServiceAccountCredentials);
     * ```
     *
     * @return void
     */
    private function initDriveService()
    {
        if(!$this->client) {
            $this->client = new Client();
            $this->client->useApplicationDefaultCredentials();
            $this->client->addScope(Drive::DRIVE);
        }
        $this->driveService = new Drive($this->client);
    }

    /**
     * Create drive file with parent folder id
     *
     * @param string $fileName
     * @return DriveFile
     */
    private function createDriveFile(string $fileName): DriveFile
    {
        $fileMetadata = new DriveFile([
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
     * @return DriveFile
     */
    public function uploadBasic(string $filePath, string $fileName, string $mimeType, string $uploadType = 'multipart'): Drive\DriveFile
    {

        try {

            $fileMetadata = $this->createDriveFile($fileName);
            // validate file path
            if(!$filePath) {
                throw new GoogleDriveUploaderException('Invalid file path:' . $filePath);
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
     * Must be called before startResumable()
     *
     * @param string $fileName - the name that will be given to the file in google drive
     * @param string $mimeType - the mimetype of the file, i.e. image/jpeg | audio/mp3
     * @return string - the resume uri
     */
    public function initResumable(string $fileName, string $mimeType): string
    {
        // create the file metadata
        $file = $this->createDriveFile($fileName);
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
     * This method is synchronous by default, but can be asynchronous by setting the async flag to true.
     * An asynchronous upload will return a gnerator function that yields the response for each
     * chunk sent to the google drive api.
     * An asyc upload may be aborted by calling the abort() method
     *
     * ## Example
     * ```php
     * // Note: The resume URI is stored after initResumable(), and can be accessed with getResumeUri() after resumableUpload() is called
     * $uploader = new DriveUploader($driveFolderId);
     * $resumeUri = $uploader->initResumable($fileName, $mimeType);
     * $uploader->startResumable($filePath, $mimeType);
     * // at this point, if the upload is interrupted, you can resume it with the resume() method
     * $uploader->resume($resumeUri);
     * ```
     *
     * @param string $filePath - the path to the file to upload
     * @param ?callable $onChunkRead - a callback function that will be called on each chunk read
     * @param int [$bytesPointer] - the byte range to start from
     * @param boolean [$async] - whether to upload asynchronously
     *
     * @return mixed - If async is set to true,
     * a generator is returned which yields false for incomplete uploads and the DriveFile object for complete uploads
     * If async is set to false, the final response is returned
     */
    public function startResumable(string $filePath, callable $onChunkRead = null, $async = false): mixed
    {

        // check that initResumable() has been called
        if(!$this->resumeUri || !$this->media) {
            throw new GoogleDriveUploaderException('initResumable() must be called before startResumable()');
        }

        try {
            // validate file path
            if(!$filePath || !is_file($filePath)) {
                throw new GoogleDriveUploaderException('Invalid file path:' . $filePath);
            }
            // set the overall file size
            $fileSize = filesize($filePath);
            $this->media->setFileSize($fileSize);

            // If async flag is true, return the generator
            if ($async) {
                return $this->startResumableAsync($filePath, $fileSize, $onChunkRead);
            }

            // if async flag is false, return the final response
            $response = false;
            foreach($this->readChunks($filePath, $fileSize, $onChunkRead) as $chunkArgs) {
                $response =  $this->media->nextChunk($chunkArgs[0]);

            }
            return $response;


        } catch(Exception $e) {
            throw new GoogleDriveUploaderException($e->getMessage(), $e->getCode(), $e->getPrevious());
        }

    }

    /**
     * Upload resumable async
     *
     * used by startResumable() when the async flag is set to true
     *
     * @param string $filePath
     * @param integer $fileSize
     * @param callable $onChunkRead
     * @return Generator<DriveFile|false> - yields false for incomplete uploads and the DriveFile object for complete uploads
     */
    private function startResumableAsync(string $filePath, int $fileSize, callable $onChunkRead)
    {
        $asyncTask =  $this->readChunks($filePath, $fileSize, $onChunkRead);
        foreach($asyncTask as $chunkArgs) {
            yield $this->media->nextChunk($chunkArgs[0]);
        }
    }



    /**
     * Read chunks from a file
     *
     * @param string $filePath
     * @param integer $fileSize
     * @param ?callable $onChunkRead - callback with arguments: chunk, progress, filesize, byte range
     * @param integer $bytesPointer
     *
     * @return Generator<array> - yields the chunk, progress, filesize and byte range
     */
    private function readChunks(string $filePath, int $fileSize, ?callable $onChunkRead, int $bytesPointer = 0)
    {

        // reset abort flag
        $this->shouldAbort = false;

        $handle = fopen($filePath, "rb");
        // set the byte pointer
        fseek($handle, $bytesPointer);
        $countChunks = 0;

        while(!feof($handle) && !$this->shouldAbort) {
            if($this->shouldAbort) {
                break;
            }
            $chunk = fread($handle, $this->chunkSize);
            // get the number of bytes processed
            $bytesProcessed = ftell($handle);
            // calculate progress as percentage
            $progress = number_format(($bytesProcessed  / $fileSize * 100), 2, '.', '');
            // get byte range:
            // [start, end -1] (because zero indexed)
            $byteRange = [$bytesProcessed - strlen($chunk), $bytesProcessed - 1];
            // set the arguments to pass to the onChunkRead callback
            $args = [$chunk,  $progress, $fileSize, $byteRange];
            // fire the onChunkRead callback
            if(is_callable($onChunkRead)) {
                call_user_func($onChunkRead, ...$args);
            }
            // yield the chunk arguments
            yield $args;


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
      * By default, the resume() method is synchronous and will return the final response from the google drive api
      * However, you can set the async flag to true to make it asynchronous
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
      * @param callable [$onChunkRead]- a callback function that will be called on each chunk read
      * @param boolean [$async] - whether to upload asynchronously
      * @return  Generator<DriveFile|false|DriveFile|false - if async is set to true, returns a generator that will
      * yield false for incomplete uploads and the DriveFile object for complete uploads. If async is set to false,
      * returns the final response, which will be false for incomplete uploads and the DriveFile object for complete uploads
      *
      * @throws GoogleDriveUploaderException
      */
    public function resume(string $resumeUri, string $filePath, callable $onChunkRead = null, bool $async = false): Generator|DriveFile|false
    {

        // perform a curl PUT request to the resume uri
        $curlResponse = $this->queryResumableUri($resumeUri);
        // get the response and status code
        $status = $curlResponse->getStatus();
        $output = $curlResponse->getOutput();
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
                return $this->putRemainingChunks($resumeUri, $filePath, $byteRange, $onChunkRead, $status, $async);
                break;
            case 200:
            case 201:
                // upload is complete, no resume necessary
                return true;
            case 400:
                throw new GoogleDriveUploaderResumeException('Bad request', [$output, $status]);


            case 404:
            case 410:
            case 500:
            case 503:
                // upload did not start, failed or resume uri expired, you must restart
                throw new GoogleDriveUploaderResumeException('Upload has not started, you must restart.', [$output, $status]);


        }
        // return the response
        return false;

    }

    /**
     * Abort upload
     *
     *
     * Interrupts the upload (if it is in progress)
     * Only works for resumable uploads.
     *
     * ```php
     * $uploader = new DriveUploader($driveFolderId);
     * $resumeUri = $uploader->initResumable($fileName, $mimeType);
     * $asyncTask = $uploader->startResumable($filePath, $mimeType);
     * foreach($asyncTask as $response) {
     *     // cancel upload after some event
     *    if($myInterruptingEvent === true) {
     *       $uploader->abort();
     *   }
     * }
     * ```
     *
     * @param string $resumeUri
     * @return void
     */
    public function abort()
    {
        $this->shouldAbort = true;
    }

    /**
     * Put remaining chunks
     *
     * Uploads the remaining chunks of a file
     * This method is called from resume() and uploads the remaining chunks
     *
     * @param string $resumeUri
     * @param string $filePath
     * @param array $byteRange
     * @param callable $onChunkRead
     * @param integer $status
     * @param boolean $async - whether to upload asynchronously (default is false)
     *
     * @return Generator<DriveFile|false>|DriveFile|false - if async is set to true, returns a generator that will
      * yield false for incomplete uploads and the DriveFile object for complete uploads. If async is set to false,
      * returns the final response, which will be false for incomplete uploads and the DriveFile object for complete uploads
     */
    private function putRemainingChunks(string $resumeUri, string $filePath, array $byteRange, callable $onChunkRead, &$status, bool $async = false): Generator|DriveFile|false
    {
        $response = false;

        $fileSize = filesize($filePath);
        $bytesPointer = $byteRange[1] + 1;
        // if async is set to true, return the generator
        if($async === true) {
            return $this->putRemainingChunksAsync($resumeUri, $filePath, $fileSize, $bytesPointer, $onChunkRead, $status);
        }
        // if async is set to false, return the final response
        $response = null;
        foreach($this->readChunks($filePath, $fileSize, $onChunkRead, $bytesPointer) as $chunkArgs) {
            $response =  $this->processRemainingChunk($resumeUri, $chunkArgs, $onChunkRead, $status);
        }

        return $response;

    }

    /**
     * Put remaining chunks asynchronously
     *
     * This method is called from putRemainingChunks() and uploads each chunk
     *
     * @param string $resumeUri
     * @param string $filePath
     * @param integer $fileSize
     * @param integer $bytesPointer
     * @param callable $onChunkRead
     * @param integer $status
     * @return Generator<DriveFile|false> - yields false for incomplete uploads and the DriveFile object for complete uploads
     */
    private function putRemainingChunksAsync(string $resumeUri, string $filePath, int $fileSize, int $bytesPointer, callable $onChunkRead, &$status)
    {
        // asynchronously read the remaining chunks
        $asyncTask =  $this->readChunks($filePath, $fileSize, $onChunkRead, $bytesPointer);
        foreach($asyncTask as $chunkArgs) {
            yield $this->processRemainingChunk($resumeUri, $chunkArgs, $onChunkRead, $status);
        }
    }

    /**
     * Process remaining chunk
     *
     * This method is called from putRemainingChunksAsync() and uploads each chunk.
     * It calls the resumePutChunk() method to send a PUT request to the resume uri with the chunk.
     *
     * @param string $resumeUri
     * @param array $chunkArgs
     * @param callable $onChunkRead
     * @param integer $status
     *
     * @return DriveFile|false
     */
    private function processRemainingChunk(string $resumeUri, array $chunkArgs, callable $onChunkRead, &$status)
    {
        list($chunk, $progress, $fileSize, $byteRange) = $chunkArgs;
        // sends a PUT request to the resume uri with the chunk
        $curlResponse = $this->resumePutChunk($chunk, $progress, $fileSize, $byteRange, $resumeUri);
        // get the response and status code
        $output = $curlResponse->getOutput();
        $status = $curlResponse->getStatus();
        // validate the put request status
        // Note: chunk status will return 308 until the final chunk which should return 200
        if($status != 202 && $status != 200 && $status != 308) {
            throw new GoogleDriveUploaderException("chunk upload failed with status: " . $status);
        }
        // fire the onChunkRead callback
        call_user_func($onChunkRead, $chunk, $progress, $fileSize, $byteRange);
        // parse the response
        $response = $this->parseChunkResponse($output);

        return $response;
    }

    /**
     * Parse chunk response to DriveFile object or false
     *
     * @param mixed $response
     * @return DriveFile|false
     */
    private function parseChunkResponse($response)
    {
        $response =  json_decode($response, true);
        // if the response contains an id, instantiate a DriveFile object
        if($response && isset($response['id'])) {
            // instantiate a DriveFile object and set properties
            // Note: setting the properties individually was necessary to avoid a bug where DriveFile did not read associative array properties
            $driveFile = new DriveFile($response);
            $driveFile->setDriveId($response['id']);
            $driveFile->setName($response['name'] ?? '');
            $driveFile->setMimeType($response['mimeType'] ?? '');
            $driveFile->setKind($response['kind'] ?? '');
            $response = $driveFile;
        }
        return $response;
    }

    /**
     * Resume put chunk
     *
     * Sends a PUT request to the resume uri with the chunk.
     *
     * This method is called from putRemainingChunks() and uploads each chunk.
     *
     * @see https://developers.google.com/drive/api/v3/manage-uploads#resume-upload
     *
     * From Google: "Now that you know where to resume the upload,
     * continue to upload the file beginning with the next byte.
     * Include a Content-Range header to indicate which portion of the file you send.
     * For example, Content-Range: bytes 43-1999999 indicates that you send bytes 44 through 2,000,000."
     *
     * @return CurlResponse - the cURL output and the http status code
     */
    private function resumePutChunk($chunk, $progress, $fileSize, $byteRange, $resumeUri): CurlResponse
    {
        // create the cURL request
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
        return CurlRequest::exec($options);

    }

    /**
     * Query resumable uri
     *
     * Perform a cURL PUT request to the resume uri in order
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
     * @return CurlResponse
     */
    private function queryResumableUri(string $resumeUri): CurlResponse
    {

        $options = [
            CURLOPT_URL => $resumeUri,
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => [
                'Content-Range' => "*/*"
            ]
        ];
        return CurlRequest::exec($options);
    }

    /**
     * Get the byte range from a PUT request to the resume uri
     *
     * @param string $curlOutput
     * @return array
     */
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
