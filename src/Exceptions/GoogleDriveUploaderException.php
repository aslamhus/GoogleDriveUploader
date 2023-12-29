<?php

namespace Aslamhus\GoogleDrive\Exceptions;

class GoogleDriveUploaderException extends \Exception
{
    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        $message = self::class . ": $message";
        parent::__construct($message, $code, $previous);

    }
}
