<?php

namespace Db;

use \Openclerk\Events;

/**
 * Represents a database that stores the state of updated tables, and uses
 * either replicated database hosts based on the type of query.
 */
class ReplicatedConnection implements Connection {

  function __construct($master_host, $slave_host, $database, $username, $password, $port = 3306, $timezone = false) {
    if (!session_id()) {
      if (!session_start()) {
        throw new DbException("Could not start session for MasterSlaveConnection");
      }
    }
    if (!isset($_SESSION['master_slave_data'])) {
      // persists for the lifetime of the session
      $_SESSION['master_slave_data'] = array();
    }
    $this->trimSessionData();

    $this->master = new SoloConnection($database, $username, $password, $master_host, $port, $timezone);
    $this->slave = new SoloConnection($database, $username, $password, $slave_host, $port, $timezone);
  }

  function resetSessionData() {
    $_SESSION['master_slave_data'] = array();
  }

  /**
   * Trim any old session data that we should actually no longer track.
   * For example, we can maybe assume that all UPDATEs have gone through after 60 seconds.
   */
  function trimSessionData() {
    foreach ($_SESSION['master_slave_data'] as $table => $last_updated) {
      if ($last_updated < time() - 60) {
        unset($_SESSION['master_slave_data'][$table]);
      }
    }
  }

  /**
   * So that we can carry on using {@link #lastInsertId()} correctly
   */
  var $lastConnection = null;

  function prepare($query) {
    if ($this->shouldUseMaster($query)) {
      if ($this->isWriteQuery($query)) {
        // update the session, but only for write queries
        $_SESSION['master_slave_data'][$this->getTable($query)] = time();
      }

      $this->lastConnection = $this->master;
      Events::trigger('db_prepare_master', $query);
      return new ReplicatedQuery($this->master, $query, true);
    } else {
      $this->lastConnection = $this->slave;
      Events::trigger('db_prepare_slave', $query);
      return new ReplicatedQuery($this->slave, $query, false);
    }
  }

  /**
   * Returns true if one of the following situations is true:
   * - {@code USE_MASTER_DB} is defined and true
   * - the query is a write query: {@link #isWriteQuery()}
   * - this session has recently updated the table used in this query
   */
  function shouldUseMaster($query) {
    if (defined('USE_MASTER_DB') && USE_MASTER_DB) {
      return true;
    }

    if ($this->isWriteQuery($query)) {
      return true;
    }

    foreach ($_SESSION['master_slave_data'] as $table => $last_updated) {
      if ($this->queryUsesTable($query, $table)) {
        return true;
      }
    }

    return false;
  }

  /**
   * NOTE this is a very simple implementation
   * @return false if there is any chance the given query is a write (UPDATE, SELECT, INSERT) query.
   */
  static function isWriteQuery($query) {
    $q = " " . strtolower(preg_replace("/\\s/i", " ", $query)) . " ";
    return strpos($q, " update ") !== false ||
      strpos($q, " insert ") !== false ||
      strpos($q, " delete ") !== false ||
      strpos($q, " create table ") !== false ||
      strpos($q, " alter table ") !== false ||
      strpos($q, " drop table ") !== false ||
      strpos($q, " show tables ") !== false ||
      strpos($q, " from migrations ") !== false;
  }

  /**
   * NOTE this is a very simple implementation
   * @return the table name, in lowercase, from the given query
   * @throws DbException if this is not a write query.
   */
  static function getTable($query) {
    if (!self::isWriteQuery($query)) {
      throw new DbException("Query '$query' is not a write query");
    }
    $query = " " . strtolower(preg_replace("/\\s+/i", " ", $query)) . " ";
    if (preg_match("# (update|delete from|insert into|create table|alter table|drop table) ([^ ;]+)[ ;]#i", $query, $matches)) {
      return $matches[2];
    }
    if (preg_match("# from migrations #i", $query, $matches)) {
      return "migrations";
    }
    if (preg_match("# (show tables) #i", $query, $matches)) {
      return "global";
    }
    throw new DbException("Could not identify table for query '$query'");
  }

  /**
   * NOTE this is a very simple implementation
   * @return false if there is any chance the given query uses the given table
   */
  function queryUsesTable($query, $table) {
    // an extremely lazy implementation
    return strpos(strtolower($query), strtolower($table)) !== false;
  }

  function lastInsertId() {
    if (!$this->lastConnection === null) {
      throw new DbException("There is no last connection to retrieve a lastInsertId from");
    }
    return $this->lastConnection->getPDO()->lastInsertId();
  }

  /**
   * We implement {@link Serializable} so that this can be used in a serialized
   * exception argument.
   */
  function serialize() {
    return serialize("[master: " . $this->getMaster()->getDSN() . ", slave: " . $this->getSlave()->getDSN() . "]");
  }

  /**
   * @throws Exception since unserialize() is not supported on this object
   */
  function unserialize($ser) {
    throw new \Exception("\Db\Connection can not be unserialized");
  }

  function getMaster() {
    return $this->master;
  }

  function getSlave() {
    return $this->slave;
  }

}
