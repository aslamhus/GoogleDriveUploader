<?php

namespace Aslamhus\GoogleDrive;

/**
 * Curl Response
 *
 * Hold the output and status code from a CurlRequest
 */
class CurlResponse implements \IteratorAggregate
{
    public string $output;
    public int $status;

    public function __construct($output, $status)
    {
        $this->output = $output;
        $this->status = $status;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator([$this->output, $this->status]);
    }
}
