<?php

namespace Db;

class DbException extends \Exception {
  function __construct($message, \Exception $previous = null) {
    parent::__construct($message, 0, $previous);
  }
}
