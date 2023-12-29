<?php

namespace Aslamhus\GoogleDrive\Exceptions;

class CurlException extends GoogleDriveUploaderException
{
    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

}
