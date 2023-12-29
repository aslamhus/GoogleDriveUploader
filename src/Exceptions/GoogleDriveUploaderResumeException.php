<?php

namespace Aslamhus\GoogleDrive\Exceptions;

class GoogleDriveUploaderResumeException extends GoogleDriveUploaderException
{
    public array $response;

    public function __construct(string $message, array $response)
    {
        parent::__construct($message, 0, null);
        $this->response = $response;
    }

    /**
     * Get the response from the failed request
     *
     * @return array - [output, status]
     */
    public function getResponse(): array
    {
        return $this->response;
    }

}
