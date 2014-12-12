<?php

namespace Db;

/**
 * Represents an instance of a database connection, which can be used to
 * prepare and execute queries.
 * TODO master/slave switch
 * TODO query metrics
 * TODO everything else
 * TODO maybe create a subclass SwitchingConnection that can switch as necessary?
 *   we can then put initialisation in the __construct
 */
class Connection implements \Serializable {

  var $pdo = null;

  function __construct($database, $username, $password, $host = "localhost", $port = 3306, $timezone = false) {
    // lazily store these settings for later (in getPDO())
    $this->database = $database;
    $this->username = $username;
    $this->password = $password;
    $this->host = $host;
    $this->port = $port;
    $this->timezone = $timezone;
  }

  function prepare($query) {
    // TODO things
    return new Query($this, $query);
  }

  function getPDO() {
    if ($this->pdo === null) {
      // TODO escape string
      // TODO add port number
      // TODO not assume that all Db's are MySQL
      $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->database;
      $this->pdo = new \PDO($dsn, $this->username, $this->password);

      // set timezone if set
      if ($this->timezone) {
        $q = $this->prepare("SET timezone=?");
        $q->execute(array($this->timezone));
      }
    }
    return $this->pdo;
  }

  function lastInsertId() {
    return $this->getPDO()->lastInsertId();
  }

  /**
   * We implement {@link Serializable} so that this can be used in a serialized
   * exception argument.
   */
  function serialize() {
    return serialize($this->database);
  }

  /**
   * @throws Exception since unserialize() is not supported on this object
   */
  function unserialize($ser) {
    throw new \Exception("\Db\Connection can not be unserialized");
  }

  // TODO setAttribute()

}
