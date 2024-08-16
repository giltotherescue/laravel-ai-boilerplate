<?php

namespace App\Exceptions;

use Exception;

class AiException extends \Exception
{
    private $status;

    public function __construct($message, $status = null)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus()
    {
        return $this->status;
    }
}
