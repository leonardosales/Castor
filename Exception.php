<?php

namespace Castor;

/**
 * Description of Exception
 *
 * @author leosales
 */
class Exception extends \Exception {

    public function __construct($message, $code = NULL)
    {
        parent::__construct($message, $code);
    }

}
